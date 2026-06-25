# STATUS

Zuletzt aktualisiert: 2026-06-25

## Entwicklungsstand

**Phase:** Produktiv im Einsatz — lernwerk.guycolle.dev.

## Was funktioniert

### Kern
- [x] SQLite FTS5 Datenbank mit automatischer Schema-Erstellung und Migration
- [x] FTS5 Prefix-Index (`prefix='2 3 4'`) für schnelle Prefix-Queries beim Tippen
- [x] bm25-Ranking mit Titel-Gewichtung (10:1 gegenüber Body)
- [x] SQLite-Performance-PRAGMAs (WAL, NORMAL synchronous, 10 MB cache)
- [x] Schema-Versionierung via `PRAGMA user_version` (automatischer Rebuild bei Schema-Änderung)

### Indexer
- [x] `MemberIndexer`: Team-Mitglieder aus `tl_member` (firstname, lastname, company/Rolle)
- [x] `PageIndexer`: liest `tl_content`/`tl_article` direkt (unabhängig von Contaos Crawler)
- [x] `PageIndexer`: URL-Suffix aus `tl_page.urlSuffix` der Root-Seite (kein hardcodiertes `.html`)
- [x] `PageIndexer`: `noSearch`- und `sitemap='map_never'`-Seiten werden ausgeschlossen
- [x] `NewsIndexer`: Volltext aus `tl_content` (ptable='tl_news'), nicht nur Teaser
- [x] `EventIndexer`: Volltext aus `tl_content` (ptable='tl_calendar_events'), nicht nur Teaser
- [x] `FileIndexer`: Metadaten aus `tl_files` (pdf, doc, docx, xls, xlsx, ppt, pptx)
- [x] `CustomTableIndexer`: konfigurierbar via `tl_search_config` im Backend
- [x] Alle Indexer: Sprache über `tl_page`-JOIN aufgelöst, URL-Suffix dynamisch
- [x] CLI-Befehl `guc:search:index` (mit `--type=`-Filter)
- [x] Automatisches Re-Indexieren via `SearchIndexListener` (Contao DCA-Callbacks)

### API
- [x] `GET /api/search` — JSON mit Gruppierung (ohne `type`-Filter) oder Paginierung (mit `type`)
- [x] Query-Parameter: `q`, `lang`, `type`, `page`
- [x] Sprachfilter: `AND (language = :lang OR language = '')`
- [x] Title-Highlighting: `snippet()` auf title-Spalte, `<mark>`-Tags
- [x] Excerpt-Highlighting: `snippet()` auf body-Spalte
- [x] Fehlerresistenz: `try-catch` um Datenbankoperationen, JSON statt 500-HTML
- [x] HTTP `Cache-Control: private, max-age=30`
- [x] Input-Validierung: Länge, Typ-Whitelist, Sprach-Regex

### Frontend
- [x] Live-Suche mit Debounce (400 ms) und `AbortController`
- [x] `DOMContentLoaded`-Guard (Script-Placement unabhängig)
- [x] Lade-Spinner (CSS-Animation) während API-Fetch
- [x] Tastaturnavigation: `ArrowDown`/`ArrowUp` zwischen Resultaten, `Escape` schliesst
- [x] Fokus-Styles für Keyboard-User
- [x] Title-Highlighting mit `<mark>`-Tags
- [x] Mobile: `width:100%`/`max-width:100vw` verhindert Overflow
- [x] Fehlerstatus: "Suche nicht verfügbar." bei HTTP-Fehler
- [x] Konfiguration über `data-*`-Attribute (api-url, min-chars, debounce, lang)

### Backend
- [x] Index-Verwaltung unter `/contao/guc-search` (ROLE_ADMIN + CSRF)
- [x] Re-Indexierung pro Typ oder gesamt
- [x] Index-Statistiken (Einträge pro Typ, Datenbankgrösse)
- [x] ⚠-Warnung wenn Index leer oder älter als 24 Stunden

### Sicherheit
- [x] API-Parameter validiert und auf Whitelists geprüft
- [x] FTS5-Query bereinigt (`sanitizeQuery`)
- [x] Nur `<mark>`-Tags in Excerpt/Title erlaubt (`strip_tags`)
- [x] Backend-Route: `ROLE_ADMIN` + CSRF-Token
- [x] `CustomTableIndexer`: Tabellen-/Feldnamen via `^\w+$` validiert
- [x] `unserialize()` mit `['allowed_classes' => false]`

## Bekannte Probleme / Offene Punkte

### Mittel

1. **`guc_search_resultsPage` nicht implementiert**
   Das DCA-Feld ist definiert, wird aber von `SearchModuleController` nicht ausgewertet.
   Klicks auf "Mehr anzeigen" verlinken auf `?q=...&type=...` ohne dedizierte Ergebnisseite.

### Niedrig

2. **Kein Rate-Limiting in der API**
   `GET /api/search` hat kein eingebautes Rate-Limiting.
   → Empfehlung: nginx `limit_req` oder `symfony/rate-limiter` (Konfiguration in `CLAUDE.md`).

3. **PDF-Volltext nicht extrahiert**
   `FileIndexer` liest nur `tl_files`-Metadaten (Dateiname, Meta-Keywords, -Description).
   Echter PDF-Inhalt erfordert `pdftotext` oder `smalot/pdfparser`.

4. **Fehlende EN-Labels für `tl_search_config`**

5. **Keine automatisierte Test-Suite**
   Kein PHPUnit/Pest für Indexer oder Repository.

## Deployment-Ablauf

```bash
# Nach jedem Bundle-Update:
composer update guc/search-bundle
php bin/console cache:clear
php bin/console assets:install

# Nach Schema-Änderungen oder erstem Deployment:
php bin/console guc:search:index
```

**Wichtig:** Bei Schema-Änderungen (erkennbar an Einträgen in HISTORY.md)
wird `search.db` beim ersten Request automatisch neu angelegt.
Ein anschliessender `guc:search:index` ist zwingend erforderlich.

## Abhängigkeiten / Voraussetzungen

- Contao ^5.3
- PHP ^8.2 mit `pdo_sqlite`-Extension
- `var/`-Verzeichnis muss schreibbar sein (`var/search.db`)
- Für News-Indexer: `contao/news-bundle`
- Für Event-Indexer: `contao/calendar-bundle`
