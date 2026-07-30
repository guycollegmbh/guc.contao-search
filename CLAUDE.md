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

| Klasse | Route | Zweck |
|---|---|---|
| `SearchApiController` | `GET /api/search` | JSON-API, Query-Params: `q`, `lang`, `type`, `page`, `types` |
| `SearchIndexController` | `GET/POST /contao/guc-search` | Backend-Verwaltung (ADMIN), erreichbar über BE-Menü |
| `SearchModuleController` | Fragment | Contao Frontend-Modul (`guc_search`), nutzt Doctrine DBAL |

`SearchApiController` lädt aktive Kategorien aus `tl_guc_category` (via Doctrine DBAL),
um `allowedTypes`, `badgeLabels`, `categoryColors` und `categoryLightText` dynamisch zu befüllen.
Kategorie-Aliases werden den fixen Typen vorangestellt, damit sie zuerst erscheinen.
Exceptions aus Indexern werden im `SearchIndexController` gefangen und als Fehlermeldung
im Backend-Template angezeigt (kein 500-Fehler).

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

**Barrierefreiheit (ARIA):**
- Tab-Buttons haben eindeutige `id="guc-tab-{type}"`
- Tab-Panels haben `aria-labelledby="guc-tab-{type}"` und `tabindex="0"`
- Keyboard-Navigation berücksichtigt `.guc-search__link` und `.guc-search__more`

**Typ-Auswahl im Frontend-Modul:**
- `_categories`-Platzhalter im Modul-Backend wird zur Laufzeit durch alle aktiven Aliases ersetzt.
- `SearchModuleController` nutzt Doctrine DBAL (nicht mehr Legacy `Contao\Database`).
- Neue Kategorien erscheinen automatisch ohne Modul-Neukonfiguration.

## Datei-Struktur

```
src/
  Command/BuildSearchIndexCommand.php       CLI-Befehl
  ContaoManager/Plugin.php                  Contao-Manager-Plugin (Routing + Bundles)
  Controller/
    Backend/SearchIndexController.php
    FrontendModule/SearchModuleController.php
    SearchApiController.php
  DependencyInjection/GucSearchExtension.php
  EventListener/SearchIndexListener.php     Re-Index-Callbacks für alle relevanten Tabellen
  GucSearchBundle.php
  Indexer/
    EventIndexer.php
    FaqIndexer.php
    FileIndexer.php
    IndexerInterface.php
    MemberIndexer.php
    NewsIndexer.php
    PageIndexer.php
  Repository/SearchRepository.php

contao/
  config/config.php                         BE_MOD: guc_search_index (Route) + guc_search_categories (DCA)
  dca/tl_article.php                        Erweiterung: Feld guc_categories (checkboxWizard)
  dca/tl_guc_category.php                  DCA für Kategorieverwaltung
  dca/tl_module.php                         Felder: guc_search_min_chars, guc_search_types, guc_search_resultsPage
  languages/de/ + en/
    default.php                             MOD-Labels inkl. "Erweiterte Suche"
    tl_article.php                          Labels für guc_categories-Feld
    tl_guc_category.php                     Labels für Kategorie-DCA (title, alias, color, lightText, active)

templates/
  backend/search_index.html.twig            Backend-Verwaltung
  frontend_module/guc_search.html.twig      Frontend-Modul (Fragment-Template)

public/
  search.js
  search.css
```

## Backend — "Erweiterte Suche"

Das Backend-Modul wird unter einer eigenen Gruppe "Erweiterte Suche" angezeigt:

| Modul | Route / Tabelle | Zweck |
|---|---|---|
| Kategorien | `tl_guc_category` | Manuelle Suchkategorien anlegen/bearbeiten |

Der **Suchindex-Controller** (`SearchIndexController`) ist über die direkte URL
`/contao/guc-search` erreichbar (erfordert ROLE_ADMIN). Der `route`-Key im `BE_MOD`-Array
wird von Contao 5 nicht korrekt gerendert und wurde deshalb nicht verwendet.

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
- FTS5-Query wird von Sonderzeichen bereinigt (`sanitizeQuery`)
- API-Response gibt nur explizit erlaubte Felder zurück (`formatResult`)
- Excerpt erlaubt serverseitig nur `<mark>`-Tags (`strip_tags($excerpt, '<mark>')`)
- Backend-Route erfordert `ROLE_ADMIN` + CSRF-Token
- `unserialize()` mit `['allowed_classes' => false]`

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

## Twig-Namespace

`@GucSearch/` → `templates/` (registriert in `GucSearchExtension`)
