# GUC Contao Search Bundle — CLAUDE.md

## Überblick

Contao 5.3+ Bundle für eine AJAX-Volltextsuche auf Basis von **SQLite FTS5**.
Paketname: `guc/search-bundle`, Namespace: `Guc\SearchBundle`.

## Technologie-Stack

| Komponente | Details |
|---|---|
| PHP | ^8.2 |
| Contao | ^5.3 |
| Suchdatenbank | SQLite FTS5 (`var/search.db`) |
| Frontend | Vanilla JS (kein Framework), IIFE-Muster |
| Template-Engine | Twig (Backend & Frontend), Contao-Fragment-Controller |

## Wichtige Befehle

```bash
# Suchindex komplett aufbauen
php bin/console guc:search:index

# Nur einen Typ indexieren (page, file, news, event, member, faq)
php bin/console guc:search:index --type=news

# Nach DCA-Änderungen (neue Felder in tl_guc_category etc.)
php bin/console contao:migrate
```

## Architektur

### Indexer (`src/Indexer/`)

Alle implementieren `IndexerInterface` (`getType(): string`, `index(): int`).
Services werden mit Tag `guc.search.indexer` registriert.

| Klasse | Typ | Quelle |
|---|---|---|
| `PageIndexer` | `page` / Kategorie-Alias | `tl_page` + `tl_search` + `tl_article.guc_categories` |
| `NewsIndexer` | `news` | `tl_news` + `tl_news_archive` |
| `EventIndexer` | `event` | `tl_calendar_events` + `tl_calendar` |
| `FileIndexer` | `file` | `tl_files` (pdf, doc, docx, xls, xlsx, ppt, pptx) |
| `MemberIndexer` | `member` | `tl_member` — alle aktiven Mitglieder, URL via `findMemberPage()` |
| `FaqIndexer` | `faq` | `tl_faq` + `tl_faq_category` |

#### Transaktionssicherheit

Jeder Indexer wrapet `clear + inserts + setMeta` in einer SQLite-Transaktion
(`beginTransaction / commit / rollback`). Ein PHP-Absturz während des Indexierens
hinterlässt keinen leeren Index — die vorherigen Daten bleiben bis zum erfolgreichen
`commit` erhalten.

#### Zirkelschutz in rekursiven Closures

Alle `resolveLanguage`- und `resolveSuffix`-Closures verwenden einen `null`-Sentinel
im Memoization-Array (`array_key_exists` statt `isset`). Zirkuläre `pid`-Verweise in
`tl_page` terminieren mit `''` statt Stack-Overflow.

#### Zeitvergleiche

`NewsIndexer`, `EventIndexer` und `MemberIndexer` verwenden PHP `time()` als
DBAL-Parameter (`:now`) statt MySQL-spezifischem `UNIX_TIMESTAMP()`.

#### PageIndexer — Ausschluss-Logik

Folgende Seiten werden **nicht** indexiert:

| Bedingung | Contao-Feld | Bedeutung |
|---|---|---|
| Nicht veröffentlicht | `published != '1'` | Entwurf / deaktiviert |
| Zugriffsgeschützt | `protected = '1'` | Seite erfordert Login |
| Robots-Noindex | `robots LIKE '%noindex%'` | Suchmaschinen-Anweisung |
| Aus Suche ausgeschlossen | `noSearch = '1'` | Backend-Checkbox "Aus der Suche ausschliessen" |

**Nicht** als Ausschluss gewertet: `sitemap = 'map_never'` — das steuert nur die XML-Sitemap,
nicht die Suche. Seiten können aus der Sitemap ausgenommen, aber trotzdem durchsuchbar sein.

**Vererbter Schutz:** Nur Seiten mit `protected = '1'` direkt auf der Seite werden ausgeschlossen.
Kindseiten die ihren Schutz von einer Elternseite erben (aber selbst `protected = '0'` haben)
erscheinen weiterhin im Index — in diesem Fall `noSearch = '1'` auf der Kindseite setzen.

#### PageIndexer — Kategorie-Logik

Für jede indexierte Seite wird geprüft, ob deren Artikel (`tl_article`) Kategorien in
`guc_categories` haben:

- **Kategorien vorhanden:** Pro Kategorie ein separater FTS-Eintrag mit
  `type = category_alias`, `badge = category_title`, `id = page_{id}_cat_{catId}`.
- **Keine Kategorien:** Fallback auf `type = 'page'`, `badge = 'Seite'`, `id = page_{id}`.

Beim Re-Indexieren werden alle bisherigen Seiteneinträge via `clearByIdPrefix('page_')`
gelöscht — unabhängig davon, unter welchem Typ sie gespeichert waren.

#### MemberIndexer — URL-Verhalten

Alle Mitglieder-Suchergebnisse verlinken auf die Team-Listenseite (via `findMemberPage()`).
Tiefere Verlinkung auf individuelle Mitglieder würde eine installationsspezifische
URL-Konfiguration erfordern und ist bewusst nicht implementiert.

### Manuelle Kategorien (`tl_guc_category`)

Im Backend unter **"Erweiterte Suche → Kategorien"** können Kategorien verwaltet werden.

| Feld | Typ | Bedeutung |
|---|---|---|
| `title` | string | Anzeigename im Suchoverlay (z.B. «Team») |
| `alias` | string | Technischer Schlüssel im FTS-Index (z.B. `team`), eindeutig, auto-generiert |
| `color` | string (7) | Hex-Farbcode **ohne** `#`-Prefix (z.B. `e30613`), Leer = Standard-Grau |
| `lightText` | checkbox | `1` = Badge-Schriftfarbe weiss (für dunkle Hintergrundfarben) |
| `active` | checkbox | Nur aktive Kategorien erscheinen in der Suche |

**Farbsystem:** Contao's Colorpicker speichert Hex-Werte **ohne** `#`-Prefix in der DB.
Der `SearchApiController` normalisiert dies beim Lesen (`'#' . ltrim($color, '#')`),
damit im Frontend ein gültiger CSS-Farbwert ankommt.

**Zuweisung zu Artikeln:** Im Contao-Artikel-Editor erscheint unter der Legende
"Erweiterte Suche" ein Checkbox-Widget `guc_categories`.
Ein Artikel kann beliebig viele Kategorien erhalten. Die Kategorien werden über alle
Artikel einer Seite hinweg aggregiert — eine Seite erscheint in so vielen Tabs, wie
einzigartige Kategorien auf ihren Artikeln vergeben sind.

### Repository (`src/Repository/SearchRepository.php`)

Direkte PDO-Verbindung zu `var/search.db`.
FTS5-Tabelle: `search_index` mit Feldern `id, type, language, title, body, url, badge, updated`.
Meta-Tabelle: `search_meta` (key/value, z.B. `last_index_page`).

SQLite-Verbindung setzt `PRAGMA busy_timeout=5000` — bei konkurrierenden Schreibzugriffen
(z.B. Cron + manueller Trigger gleichzeitig) wartet SQLite bis zu 5 Sekunden
statt sofort mit `SQLITE_BUSY` (error 5) zu scheitern.

Wichtige Methoden:

| Methode | Zweck |
|---|---|
| `beginTransaction()` / `commit()` / `rollback()` | Transaktionssteuerung für Indexer |
| `clearType(string $type)` | Alle Einträge eines Typs löschen |
| `clearByIdPrefix(string $prefix)` | Batch-DELETE via `rowid IN (...)` — zwei-stufig: SELECT rowids per GLOB, dann ein DELETE. GLOB auf FTS5-UNINDEXED in DELETE ist unzuverlässig. |
| `searchGrouped(...)` | Gruppierte Suche — Fallback: `getDistinctTypes()` aus dem Index |

### Event Listener (`src/EventListener/SearchIndexListener.php`)

Triggert automatische Neuindexierung bei Backend-Änderungen:

| Tabelle | Indexer |
|---|---|
| `tl_page`, `tl_article`, `tl_guc_category` | `PageIndexer` |
| `tl_news` | `NewsIndexer` |
| `tl_calendar_events` | `EventIndexer` |
| `tl_files` | `FileIndexer` |
| `tl_member` | `MemberIndexer` |
| `tl_content` | Je nach `ptable`: Page-, News- oder EventIndexer |

### Controller

| Klasse | Route / Zweck |
|---|---|
| `SearchApiController` | `GET /api/search` — JSON-API, Query-Params: `q`, `lang`, `type`, `page`, `types` |
| `SearchModuleController` | Fragment — Contao Frontend-Modul (`guc_search`), das Live-Widget |
| `SearchResultsModuleController` | Fragment — Contao Frontend-Modul (`guc_search_results`), serverseitige Ergebnisseite |
| `Backend\SearchIndexModule` | BE_MOD-Callback — reine Index-Statusanzeige im Backend |

### Geteilte Such-Services (`src/Search/`)

Damit Overlay (JSON-API) und Ergebnisseite sich garantiert identisch verhalten,
liegt die gemeinsame Logik in eigenen Services statt in `private`-Methoden der Controller:

| Klasse | Zweck |
|---|---|
| `CategoryProvider` | Lädt aktive `tl_guc_category`-Zeilen via DBAL und liefert ein `SearchTypes` |
| `SearchTypes` | Immutable: `allowed()`, `ordered()`, `label()`, `color()`, `isLightText()`, `filterList()`. Konstante `SearchTypes::FIXED` = die sechs festen Typen |
| `FuzzyQueryBuilder` | `build(string $query): ?array` — Levenshtein-Expansion (vorher `SearchApiController::buildFuzzyFtsQuery()`) |
| `ResultFormatter` | `format()` / `formatAll()` inkl. `sanitizeSnippet()` und `sanitizeUrl()` |

`SearchTypes::ordered()` stellt Kategorie-Aliases den fixen Typen voran, damit sie zuerst erscheinen.

### Ergebnisseite (`guc_search_results`)

Serverseitiges Gegenstück zum Live-Widget. Liest `keywords` und `type` aus der URL —
exakt die Parameter, die `search.js` beim Verlinken schreibt — und rendert ohne JavaScript.

| Modus | Bedingung | Ausgabe |
|---|---|---|
| `empty` | kein `keywords` oder > 200 Zeichen | nur das Suchformular |
| `grouped` | `keywords`, kein `type` | Gruppen-Übersicht, `perPage` Treffer je Gruppe, je ein „Alle N anzeigen"-Link |
| `filtered` | `keywords` + gültiger `type` | flache, paginierte Liste eines Typs |

- Paginierungs-Parameter: `page_s{moduleId}` (Contao-Konvention, kollidiert nicht mit anderen Modulen)
- Fuzzy-Fallback identisch zur API — bei Treffer wird `.guc-search__fuzzy-hint` gerendert
- `guc_search_types` wirkt als Whitelist: ein `type` ausserhalb der Modulkonfiguration fällt auf `grouped` zurück
- CSS-Block `.guc-search-results` in `search.css`; Item-/Badge-Klassen werden vom Overlay mitbenutzt

`Backend\SearchIndexModule` ist kein Symfony-Controller, sondern eine Contao-BE_MOD-Callback-Klasse.
Contao instanziiert sie via `System::importStatic()` (DI-fähig in Contao 5) und ruft `generate()` auf.
Das zurückgegebene HTML wird von Contao's `BackendController` in das vollständige Backend-Layout eingebettet.
Das Template (`search_index.html.twig`) enthält daher kein `extends` mehr.

Abhängigkeiten werden in `generate()` via `System::getContainer()->get()` geholt (nicht via
Konstruktor-Injection), da Contao in manchen Pfaden `new SearchIndexModule()` direkt aufruft.
`IndexerRegistry` ist ein öffentlicher Service, der den tagged Iterator `guc.search.indexer`
kapselt und so per `$container->get(IndexerRegistry::class)` abrufbar ist.

### API-Response-Format

Ohne `type`-Filter (gruppiert):
```json
{
  "grouped": [
    {
      "type": "team",
      "label": "Team",
      "results": [...],
      "total": 15,
      "hasMore": true,
      "color": "#e30613",
      "lightText": true
    }
  ],
  "query": "suchbegriff"
}
```

- `color` — nur vorhanden wenn `tl_guc_category.color` gesetzt ist (mit `#`-Prefix normalisiert)
- `lightText` — nur vorhanden (und `true`) wenn `tl_guc_category.lightText = '1'`
- `hasMore` — `true` wenn mehr als 10 Treffer existieren; JS rendert dann einen "Alle N Ergebnisse anzeigen →"-Link

Mit `type`-Filter (paginiert):
```json
{ "results": [...], "total": 12, "page": 1, "pages": 2, "query": "suchbegriff" }
```

### Frontend-Widget

`public/search.js` + `public/search.css` — eingebunden via Contao-Asset-System.
Konfiguration über `data-*`-Attribute des `.guc-search`-Containers:
- `data-api-url`, `data-min-chars`, `data-debounce`, `data-lang`

**Badge-Farben:**
- Fixe Typen (`page`, `file`, `news`, `event`, `member`, `faq`) haben je eine CSS-Klasse
  `.guc-search__badge--{type}` mit hartcodierten Farben.
- Manuelle Kategorien erhalten ihre Farbe via Inline-Style (`badge.style.backgroundColor = group.color`).
  Inline-Style überschreibt die CSS-Klasse.
- Wenn `group.lightText === true`, wird zusätzlich `badge.style.color = '#ffffff'` gesetzt.
- Ohne gesetzten Farbwert greift die Basisklasse `.guc-search__badge` (Standard-Grau).

**"Mehr anzeigen"-Link:**
- Wenn `group.hasMore === true` und eine `resultsUrl` am Widget gesetzt ist, wird
  am Ende der Ergebnisliste ein `.guc-search__more`-Link gerendert.
- Link navigiert zu `resultsUrl?keywords=...&type=...` (direkt gefilterte Ergebnisseite).
- Keyboard-Navigation (`ArrowDown`/`ArrowUp`) schließt den Link ein.

**Mehrfach-Instanzen auf einer Seite:**
Themes rendern das Widget häufig doppelt (z.B. Desktop-Header + Mobile-Menü).
`initSearch()` vergibt darum pro Instanz ein ID-Prefix `guc-s{index}-`, und der
Tab-Wechsel löst das Panel über `panelsEl.children[tab.dataset.index]` auf statt über
`document.getElementById()`. Ohne das würde ein Tab-Klick in der einen Instanz das
Panel der anderen umschalten.

**Barrierefreiheit (ARIA):**
- Tab-Buttons haben eindeutige `id="guc-s{index}-tab-{type}"`
- Tab-Panels haben `aria-labelledby` auf diese ID und `tabindex="0"`
- Keyboard-Navigation berücksichtigt `.guc-search__link` und `.guc-search__more`

**Typ-Auswahl im Frontend-Modul:**
- `_categories`-Platzhalter im Modul-Backend wird zur Laufzeit durch alle aktiven Aliases ersetzt.
- `SearchModuleController` nutzt Doctrine DBAL (nicht mehr Legacy `Contao\Database`).
- Neue Kategorien erscheinen automatisch ohne Modul-Neukonfiguration.

### Darstellung: `inline` vs. `overlay`

Modul-Feld `guc_search_layout` (Default `inline`).

| Layout | Markup | Verhalten |
|---|---|---|
| `inline` | Feld + `.guc-search__results` direkt im Container | Treffer als Dropdown unter dem Feld (`position: absolute`) |
| `overlay` | Lupen-Button + `.guc-search__layer` | Klick öffnet ein Fullscreen-Panel über der ganzen Seite |

Das Suchfeld-Markup liegt für beide Layouts in einem einzigen Partial
(`templates/frontend_module/_search_field.html.twig`, eingebunden über `@GucSearch/`),
damit die Varianten nicht auseinanderlaufen können.

**Zwei Fallstricke, die der Overlay-Modus umgeht:**

1. `search.js` hängt `.guc-search__layer` beim Init an `<body>`. `position: fixed` löst
   sonst gegen den nächsten Vorfahren mit `transform`/`filter`/`perspective` auf — bei
   einem Sticky-Header ist das die Regel, und das Overlay bliebe im Header eingesperrt.
2. Der Layer trägt **selbst** die Klasse `guc-search`. Sämtliche CSS-Regeln sind als
   `.guc-search .guc-search__…` gescoped (um Theme-Selektoren wie `[type=button]` zu
   überstimmen); nach dem Verschieben ans `<body>` ist der Layer kein Nachfahre des
   Widgets mehr und wäre sonst komplett unstyled. `initSearch()` selektiert darum
   `.guc-search:not(.guc-search__layer)`, damit der Layer nicht als eigenes Widget initialisiert wird.

**Overlay-Interaktion:** ESC und Backdrop-Klick schliessen, Fokus wandert beim Öffnen
ins Feld und beim Schliessen zurück auf die Lupe, `document.body.style.overflow` wird
gesperrt und auf den vorherigen Wert zurückgesetzt, Tab-Fokus ist im Layer gefangen.
Beim Schliessen wird das Feld geleert.

**Lupen-Icon:** Modul-Feld `guc_search_toggleIcon` (Dateiauswahl). Leer = eingebautes
Inline-SVG. `SearchModuleController::resolveToggleIcon()` prüft die Existenz der Datei
und gibt einen root-relativen Pfad zurück.

## Fuzzy-Suche & Autocomplete

### Wörterverzeichnis (`search_words`)

Beim Indexieren sammeln alle Indexer den Volltext und rufen
`SearchRepository::extractWords()` auf (Unicode-Buchstaben, mind. 4 Zeichen).
Die Wörter werden mit Häufigkeit in der SQLite-Tabelle `search_words` gespeichert
(`upsertWords`, `ON CONFLICT DO UPDATE SET frequency = frequency + 1`).

Bei `guc:search:index` ohne `--type` wird `clearWords()` zuerst aufgerufen
(vollständiger Neuaufbau des Verzeichnisses).

### Autocomplete (`/api/search/suggestions`)

`SearchSuggestionsController` → `GET /api/search/suggestions?q=prefix`

- Mindestlänge: 2 Zeichen; nur Buchstaben-Präfixe
- `SearchRepository::getSuggestions(prefix, limit=8)` — LIKE-Suche in `search_words`
- Response: `{"suggestions": ["word1", "word2", ...]}`, `Cache-Control: private, max-age=60`
- JS ruft diesen Endpoint mit 150 ms Debounce auf, solange noch keine Suchergebnisse sichtbar sind
- Suggestions werden bei Klick ins Suchfeld übernommen und lösen sofort eine Suche aus

### Fuzzy-Fallback (Levenshtein)

Wenn eine Suche **0 Treffer** liefert, baut `SearchApiController::buildFuzzyFtsQuery()` eine
erweiterte FTS5-Query auf:

- Jedes Wort mit ≥ 4 Zeichen wird mit dem `search_words`-Verzeichnis verglichen (`levenshtein()`)
- Maximale Edit-Distanz: **1** für Wörter 4–6 Zeichen, **2** für 7+ Zeichen
- Wörter < 4 Zeichen werden unverändert übernommen (zu viele False Positives)
- Gefundene Kandidaten werden als FTS5-OR-Gruppe expandiert: `(original OR kandidat1 OR kandidat2)`
- Mehrere Wörter → implizites AND zwischen den Gruppen

Die erweiterte Query wird mit den `*Fts()`-Methoden ausgeführt (keine erneute Sanitization).
Bei Treffer enthält die API-Response: `"fuzzy": true, "suggestion": "korrigiertes wort"`.

**JS-Verhalten bei Fuzzy-Treffer:**
- Banner `.guc-search__fuzzy-hint` wird oberhalb der Tabs angezeigt:
  `Keine Treffer für «falschschreibung» — Ergebnisse für «korrektschreibung»`
- Styling: heller gelb-beiger Hintergrund (#fffbf0), kleine Schrift (0.82rem)

### Dateistruktur (neu)

```
src/Controller/SearchSuggestionsController.php   GET /api/search/suggestions
src/Repository/SearchRepository.php              search_words-Tabelle, extractWords(), getSuggestions(), getAllWords()
                                                 *Fts()-Varianten für fuzzy queries
src/Controller/SearchApiController.php           buildFuzzyFtsQuery(), fuzzy/suggestion in Response
```

## Datei-Struktur

```
src/
  Command/BuildSearchIndexCommand.php       CLI-Befehl
  ContaoManager/Plugin.php                  Contao-Manager-Plugin (Routing + Bundles)
  Backend/
    SearchIndexModule.php                 BE_MOD-Callback (kein Symfony-Controller)
  Controller/
    FrontendModule/SearchModuleController.php
    FrontendModule/SearchResultsModuleController.php
    SearchApiController.php
    SearchSuggestionsController.php
  Search/
    CategoryProvider.php                      tl_guc_category → SearchTypes
    SearchTypes.php                           Typ-Whitelist, Labels, Farben
    FuzzyQueryBuilder.php                     Levenshtein-Expansion
    ResultFormatter.php                       Feld-Whitelist + Sanitization
  DependencyInjection/GucSearchExtension.php
  EventListener/SearchIndexListener.php     Re-Index-Callbacks für alle relevanten Tabellen
  GucSearchBundle.php
  Indexer/
    EventIndexer.php
    FaqIndexer.php
    FileIndexer.php
    IndexerInterface.php
    IndexerRegistry.php                       Öffentlicher Service-Wrapper für den tagged Iterator
    MemberIndexer.php
    NewsIndexer.php
    PageIndexer.php
  Repository/SearchRepository.php

contao/
  config/config.php                         BE_MOD: guc_search_index (callback) + guc_search_categories (DCA)
  dca/tl_article.php                        Erweiterung: Feld guc_categories (checkboxWizard)
  dca/tl_guc_category.php                  DCA für Kategorieverwaltung
  dca/tl_module.php                         Paletten guc_search + guc_search_results;
                                            Felder: guc_search_min_chars, guc_search_types,
                                            guc_search_resultsPage, guc_search_perPage,
                                            guc_search_layout (+ Subpalette guc_search_toggleIcon)
  languages/de/ + en/
    default.php                             MOD-Labels inkl. "Erweiterte Suche"
    tl_article.php                          Labels für guc_categories-Feld
    tl_guc_category.php                     Labels für Kategorie-DCA (title, alias, color, lightText, active)

templates/
  backend/search_index.html.twig            Backend-Verwaltung
  frontend_module/guc_search.html.twig      Live-Widget (Fragment-Template, inline + overlay)
  frontend_module/guc_search_results.html.twig  Ergebnisseite (Fragment-Template)
  frontend_module/_search_field.html.twig   Geteiltes Feld-Markup (@GucSearch/), von beiden Layouts inkludiert

public/
  search.js
  search.css
```

## Backend — "Erweiterte Suche"

Das Backend-Modul wird unter einer eigenen Gruppe "Erweiterte Suche" angezeigt:

| Modul | Tabelle / Callback | Zweck |
|---|---|---|
| Suchindex | `SearchIndexModule::generate()` | Reine Statusanzeige: Einträge pro Typ, letzter Indexierungszeitpunkt, DB-Grösse |
| Kategorien | `tl_guc_category` | Manuelle Suchkategorien anlegen/bearbeiten |

Nach dem Anlegen/Ändern von Kategorien muss der Suchindex neu aufgebaut werden:
```bash
php bin/console guc:search:index --type=page
```

Nach Änderungen am DCA (neue Felder) muss die Datenbank migriert werden:
```bash
php bin/console contao:migrate
```

## Sicherheit

### Was das Bundle selbst absichert
- API-Parameter (`q`, `type`, `lang`, `types`) werden validiert und auf dynamischer Whitelist geprüft
- `type`-Whitelist = feste Typen + aktive Kategorie-Aliases aus `tl_guc_category`
- FTS5-Query wird von Sonderzeichen bereinigt (`sanitizeQuery`); Fuzzy-Pfad sanitiert zusätzlich jeden Wortteil
- API-Response gibt nur explizit erlaubte Felder zurück (`formatResult`)
- Excerpt erlaubt serverseitig nur `<mark>`-Tags (`strip_tags($excerpt, '<mark>')`)
- `sanitizeUrl()` lässt nur root-relative Pfade durch — lehnt `javascript:`, `data:` und protokoll-relative `//host`-URLs ab
- `getAllWords()` ist auf 10'000 Wörter limitiert (verhindert Memory-Erschöpfung)
- Backend-Route erfordert Contao-Backend-Login (BE_MOD-System)
- `unserialize()` mit `['allowed_classes' => false]`
- Geschützte Seiten (`protected = '1'`) und `noSearch = '1'`-Seiten werden nicht indexiert

### Rate-Limiting — muss auf Anwendungsebene konfiguriert werden

`GET /api/search` hat kein eingebautes Rate-Limiting. Bei häufigen Anfragen
(z.B. automatisiertes Scraping) kann der SQLite-Index CPU/Disk belasten.

**Option A — nginx** (empfohlen für Produktion):
```nginx
limit_req_zone $binary_remote_addr zone=guc_search:10m rate=20r/m;

location /api/search {
    limit_req zone=guc_search burst=5 nodelay;
}
```

**Option B — Symfony Rate Limiter** (`symfony/rate-limiter` installieren):
```yaml
# config/packages/rate_limiter.yaml
framework:
    rate_limiter:
        guc_search_api:
            policy: sliding_window
            limit: 30
            interval: '1 minute'
```
Dann im `SearchApiController` via `RateLimiterFactory $gucSearchApiLimiter` einbinden.

## Bekannte Einschränkungen

- **MemberIndexer URL:** Alle Mitglieder-Suchergebnisse verlinken auf die Team-Listenseite.
  Individuelle Mitglieder-URLs würden eine installationsspezifische Konfiguration erfordern.
- **`hasMore`-Link** erscheint nur wenn `data-results-url` am Widget gesetzt ist.
  Ohne diese URL wird der Link nicht gerendert (kein "blinder" Link auf `#`).
- **Ergebnisseite muss verdrahtet werden:** `data-results-url` (Feld „Weiterleitungsseite")
  sollte auf eine Seite zeigen, auf der ein `guc_search_results`-Modul liegt. Zeigt es auf
  eine Seite mit Contaos eigenem `mod_search`, laufen Overlay und Ergebnisliste gegen
  verschiedene Indizes (`search.db` vs. `tl_search`) und liefern abweichende Treffer.

## Twig-Namespace

`@GucSearch/` → `templates/` (registriert in `GucSearchExtension`)
