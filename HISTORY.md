# HISTORY

## [Unreleased]

### Hinzugefügt
- `SearchIndexListener`: automatisches Re-Indexieren via Contao `#[AsCallback]` beim
  Speichern/Löschen in `tl_page`, `tl_article`, `tl_content`, `tl_news`,
  `tl_calendar_events`, `tl_files`, `tl_search_config`
- `tl_content`-Callback löst je nach `ptable` nur den betroffenen Indexer aus

### Geändert
- `PageIndexer`: liest Content direkt aus `tl_content`/`tl_article` statt `tl_search`
- `NewsIndexer`: URL dynamisch über `jumpTo`-Seite aufgelöst
- `EventIndexer`: URL dynamisch über `jumpTo`-Seite aufgelöst
- Route `/contao/guc-search` erlaubt jetzt POST (Backend-Reindex-Button)

### Entfernt
- Toter Code: `ContaoManagerPlugin.php`, `ModuleSearch.php`, `mod_guc_search.html5`,
  `search_module.html.twig`, `search_results.html.twig`, `contao/config.php`

## [0.1.0] — 2026-06-18

### Hinzugefügt
- Initiale Modulstruktur als Contao 5.3 Bundle (`guc/search-bundle`)
- `SearchRepository` mit SQLite FTS5 (`var/search.db`), unicode61-Tokenizer
- Indexer-Architektur via `IndexerInterface` + Tagged-Service-Iterator
  - `PageIndexer` — Contao-Seiten (tl_page + tl_search)
  - `NewsIndexer` — Contao News (tl_news)
  - `EventIndexer` — Contao Kalender-Events (tl_calendar_events)
  - `FileIndexer` — Dateien aus tl_files (PDF, Office-Formate)
  - `CustomTableIndexer` — beliebige Datenbanktabellen via Backend-Konfiguration
- CLI-Befehl `guc:search:index` mit `--type`-Option
- REST-API `GET /api/search` mit Gruppierung nach Typ, Paginierung, Sprachfilter
- Backend-Controller `/contao/guc-search` (Admin-only, CSRF-gesichert) zur Index-Verwaltung
- Frontend-Modul `guc_search` als Contao Fragment-Controller
- Vanilla-JS-Widget mit Debouncing (400 ms), AbortController, Keyboard-Navigation
- CSS-Styles für das Suchwidget inkl. farbige Typ-Badges
- DCA `tl_search_config` für Custom-Tabellen-Konfiguration im Backend
- Zweisprachige Labels (de/en)
- Twig-Namespace `@GucSearch`
