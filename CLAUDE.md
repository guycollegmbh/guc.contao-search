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

# Nur einen Typ indexieren (page, file, news, event, custom)
php bin/console guc:search:index --type=news
```

## Architektur

### Indexer (`src/Indexer/`)

Alle implementieren `IndexerInterface` (`getType(): string`, `index(): int`).
Services werden mit Tag `guc.search.indexer` registriert.

| Klasse | Typ | Quelle |
|---|---|---|
| `PageIndexer` | `page` | `tl_page` + `tl_search` (Contao-eigener Index) |
| `NewsIndexer` | `news` | `tl_news` + `tl_news_archive` |
| `EventIndexer` | `event` | `tl_calendar_events` + `tl_calendar` |
| `FileIndexer` | `file` | `tl_files` (pdf, doc, docx, xls, xlsx, ppt, pptx) |
| `CustomTableIndexer` | `custom` | Konfigurierbar via `tl_search_config` (Backend) |

### Repository (`src/Repository/SearchRepository.php`)

Direkte PDO-Verbindung zu `var/search.db`.
FTS5-Tabelle: `search_index` mit Feldern `id, type, language, title, body, url, badge, updated`.
Meta-Tabelle: `search_meta` (key/value, z.B. `last_index_page`).

### Controller

| Klasse | Route | Zweck |
|---|---|---|
| `SearchApiController` | `GET /api/search` | JSON-API, Query-Params: `q`, `lang`, `type`, `page` |
| `SearchIndexController` | `GET/POST /contao/guc-search` | Backend-Verwaltung (ADMIN) |
| `SearchModuleController` | Fragment | Contao Frontend-Modul (`guc_search`) |

### API-Response-Format

Ohne `type`-Filter (gruppiert):
```json
{
  "grouped": [
    { "type": "page", "label": "Seite", "results": [...], "total": 5, "hasMore": false }
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
  ContaoManagerPlugin.php               VERALTET — nicht in composer.json referenziert
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
  Module/ModuleSearch.php               LEGACY — nicht aktiv genutzt
  Repository/SearchRepository.php

contao/
  config/config.php                     Leer (FrontendModule via Attribut registriert)
  config.php                            REDUNDANT — nur Kommentar
  dca/tl_module.php                     Felder: guc_search_min_chars, guc_search_resultsPage
  dca/tl_search_config.php              DCA für Custom-Tabellen-Konfiguration
  languages/de/ + en/

templates/
  backend/search_index.html.twig        Backend-Verwaltung
  frontend_module/guc_search.html.twig  Frontend-Modul (Fragment-Template)
  search_module.html.twig               UNBENUTZT
  search_results.html.twig              UNBENUTZT

public/
  search.js
  search.css
```

## Bekannte Probleme / TODOs

Siehe `STATUS.md` für den aktuellen Entwicklungsstand.

## Twig-Namespace

`@GucSearch/` → `templates/` (registriert in `GucSearchExtension`)

## Custom-Table-Konfiguration (tl_search_config)

Im Backend unter "GUC Suche" können beliebige Tabellen indexiert werden.
Pflichtfelder: `tableName`, `titleField`, `bodyField`, `urlPattern` (mit `%s`-Platzhalter).
SQL-Injection-Schutz: Identifier werden via Regex `^\w+$` validiert.
