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

#### PageIndexer — Kategorie-Logik

Für jede indexierte Seite wird geprüft, ob deren Artikel (`tl_article`) Kategorien in
`guc_categories` haben:

- **Kategorien vorhanden:** Pro Kategorie ein separater FTS-Eintrag mit
  `type = category_alias`, `badge = category_title`, `id = page_{id}_cat_{catId}`.
- **Keine Kategorien:** Fallback auf `type = 'page'`, `badge = 'Seite'`, `id = page_{id}`.

Beim Re-Indexieren werden alle bisherigen Seiteneinträge via `clearByIdPrefix('page_')`
gelöscht — unabhängig davon, unter welchem Typ sie gespeichert waren.

### Manuelle Kategorien (`tl_guc_category`)

Im Backend unter **"Erweiterte Suche → Kategorien"** können Kategorien verwaltet werden.

| Feld | Bedeutung |
|---|---|
| `title` | Anzeigename im Suchoverlay (z.B. «Team») |
| `alias` | Technischer Schlüssel im FTS-Index (z.B. `team`), eindeutig, auto-generiert |
| `active` | Nur aktive Kategorien erscheinen in der Suche |

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
| `clearType(string $type)` | Alle Einträge eines Typs löschen |
| `clearByIdPrefix(string $prefix)` | Alle Einträge löschen, deren `id` mit Präfix beginnt (SQLite GLOB) |
| `searchGrouped(...)` | Gruppierte Suche — Fallback: `getDistinctTypes()` aus dem Index |

### Controller

| Klasse | Route | Zweck |
|---|---|---|
| `SearchApiController` | `GET /api/search` | JSON-API, Query-Params: `q`, `lang`, `type`, `page` |
| `SearchIndexController` | `GET/POST /contao/guc-search` | Backend-Verwaltung (ADMIN) |
| `SearchModuleController` | Fragment | Contao Frontend-Modul (`guc_search`) |

`SearchApiController` lädt aktive Kategorien aus `tl_guc_category` (via Doctrine DBAL),
um `allowedTypes` und `badgeLabels` dynamisch zu befüllen. Kategorie-Aliases werden
dabei den fixen Typen (`page`, `file`, `news`, `event`, `member`, `faq`)
vorangestellt, damit sie in der gruppierten Antwort zuerst erscheinen.

### API-Response-Format

Ohne `type`-Filter (gruppiert):
```json
{
  "grouped": [
    { "type": "team", "label": "Team", "results": [...], "total": 5, "hasMore": false }
  ],
  "query": "suchbegriff"
}
```

Mit `type`-Filter (paginiert):
```json
{ "results": [...], "total": 12, "page": 1, "pages": 2, "query": "suchbegriff" }
```

### Frontend-Widget

`public/search.js` + `public/search.css` — eingebunden via Contao-Asset-System.
Konfiguration über `data-*`-Attribute des `.guc-search`-Containers:
- `data-api-url`, `data-min-chars`, `data-debounce`, `data-lang`

## Datei-Struktur

```
src/
  Command/BuildSearchIndexCommand.php   CLI-Befehl
  ContaoManager/Plugin.php              Contao-Manager-Plugin (Routing + Bundles)
  Controller/
    Backend/SearchIndexController.php
    FrontendModule/SearchModuleController.php
    SearchApiController.php
  DependencyInjection/GucSearchExtension.php
  GucSearchBundle.php
  Indexer/
    CustomTableIndexer.php
    EventIndexer.php
    FileIndexer.php
    IndexerInterface.php
    NewsIndexer.php
    PageIndexer.php
  Repository/SearchRepository.php

contao/
  config/config.php                     BE_MOD-Registrierung "Erweiterte Suche"
  dca/tl_article.php                    Erweiterung: Feld guc_categories (checkboxWizard)
  dca/tl_guc_category.php              DCA für Kategorieverwaltung
  dca/tl_module.php                     Felder: guc_search_min_chars, guc_search_resultsPage
  dca/tl_search_config.php              DCA für Custom-Tabellen-Konfiguration
  languages/de/ + en/
    default.php                         MOD-Labels inkl. "Erweiterte Suche"
    tl_article.php                      Labels für guc_categories-Feld
    tl_guc_category.php                 Labels für Kategorie-DCA

templates/
  backend/search_index.html.twig        Backend-Verwaltung
  frontend_module/guc_search.html.twig  Frontend-Modul (Fragment-Template)

public/
  search.js
  search.css
```

## Backend — "Erweiterte Suche"

Das Backend-Modul wird unter einer eigenen Gruppe "Erweiterte Suche" angezeigt:

| Modul | Tabelle | Zweck |
|---|---|---|
| Kategorien | `tl_guc_category` | Manuelle Suchkategorien anlegen/bearbeiten |
| Tabellen-Konfiguration | `tl_search_config` | Custom-Inhaltsquellen konfigurieren |

Nach dem Anlegen/Ändern von Kategorien muss der Suchindex neu aufgebaut werden:
```bash
php bin/console guc:search:index --type=page
```

## Sicherheit

### Was das Bundle selbst absichert
- API-Parameter (`q`, `type`, `lang`) werden validiert und auf dynamischer Whitelist geprüft
- `type`-Whitelist = feste Typen + aktive Kategorie-Aliases aus `tl_guc_category`
- FTS5-Query wird von Sonderzeichen bereinigt (`sanitizeQuery`)
- API-Response gibt nur explizit erlaubte Felder zurück (`formatResult`)
- Excerpt erlaubt serverseitig nur `<mark>`-Tags (`strip_tags($excerpt, '<mark>')`)
- Backend-Route erfordert `ROLE_ADMIN` + CSRF-Token
- `CustomTableIndexer` validiert Tabellen-/Feldnamen via `^\w+$`
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

## Bekannte Probleme / TODOs

Siehe `STATUS.md` für den aktuellen Entwicklungsstand.

## Twig-Namespace

`@GucSearch/` → `templates/` (registriert in `GucSearchExtension`)

## Custom-Table-Konfiguration (tl_search_config)

Im Backend unter "Erweiterte Suche → Tabellen-Konfiguration" können beliebige Tabellen
indexiert werden. Pflichtfelder: `tableName`, `titleField`, `bodyField`, `urlPattern`
(mit `%s`-Platzhalter). SQL-Injection-Schutz: Identifier werden via Regex `^\w+$` validiert.
