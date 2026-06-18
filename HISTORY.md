# HISTORY

## [Unreleased]

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
