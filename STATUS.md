# STATUS

Zuletzt aktualisiert: 2026-06-26 (Session 2)

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
- [x] `FaqIndexer`: Häufige Fragen aus `tl_faq` + `tl_faq_category` (contao/faq-bundle)
- [x] `MemberIndexer`: Team-Mitglieder aus `tl_member` (firstname, lastname, company/Rolle)
- [x] `PageIndexer`: `tl_search` als primäre Quelle (enthält RSCE/Custom-Elemente), `tl_content` als Fallback
- [x] `PageIndexer`: `tl_search`-Query filtert nur publizierte Seiten (kein Stale-Content)
- [x] `PageIndexer`: URL-Suffix aus `tl_page.urlSuffix` der Root-Seite (kein hardcodiertes `.html`)
- [x] `PageIndexer`: `noSearch`- und `sitemap='map_never'`-Seiten werden ausgeschlossen
- [x] `NewsIndexer`: Volltext aus `tl_content` (ptable='tl_news'), nicht nur Teaser
- [x] `EventIndexer`: Volltext aus `tl_content` (ptable='tl_calendar_events'), nicht nur Teaser
- [x] `FileIndexer`: Metadaten aus `tl_files` (pdf, doc, docx, xls, xlsx, ppt, pptx)
- [x] `CustomTableIndexer`: konfigurierbar via `tl_search_config` im Backend
- [x] Alle Indexer: Sprache über `tl_page`-JOIN aufgelöst, URL-Suffix dynamisch
- [x] CLI-Befehl `guc:search:index` (mit `--type=`-Filter)
- [x] Automatisches tägliches Re-Indexieren via `RebuildSearchIndexCron` (`#[AsCronJob('daily')]`)
- [x] Automatisches Re-Indexieren via `SearchIndexListener` (Contao DCA-Callbacks)

### API
- [x] `GET /api/search` — JSON mit Gruppierung (ohne `type`-Filter) oder Paginierung (mit `type`)
- [x] Query-Parameter: `q`, `lang`, `type`, `page`, `types`
- [x] `?types=`-Filter (Modul-Konfiguration) gilt auch für Single-Type-Pfad `?type=`
- [x] Sprachfilter: `AND (language = :lang OR language = '')`
- [x] Title-Highlighting: `snippet()` auf title-Spalte, `<mark>`-Tags
- [x] Excerpt-Highlighting: `snippet()` auf body-Spalte
- [x] Fehlerresistenz: `try-catch` um Datenbankoperationen, JSON statt 500-HTML
- [x] HTTP `Cache-Control: private, max-age=30`
- [x] Input-Validierung: Länge, Typ-Whitelist, Sprach-Regex
- [x] Grouped-Request: 8 SQLite-Queries statt 14 (1× `COUNT GROUP BY type` statt 7× `countByType`)

### Frontend
- [x] Enter-Taste leitet auf konfigurierte Ergebnisseite weiter (`?keywords=suchbegriff`)
- [x] Live-Suche mit Debounce (400 ms) und `AbortController`
- [x] `DOMContentLoaded`-Guard (Script-Placement unabhängig)
- [x] Lade-Spinner (CSS-Animation, Brand-Rot `#e30613`) während API-Fetch
- [x] Tastaturnavigation: `ArrowDown`/`ArrowUp` im aktiven Panel, `Escape` schliesst
- [x] Title-Highlighting mit `<mark>`-Tags (rosa `rgba(246,188,209,0.6)`, nur Hintergrund)
- [x] Mobile: `width:100%`/`max-width:100vw` verhindert Overflow
- [x] Fehlerstatus: "Suche nicht verfügbar." bei HTTP-Fehler
- [x] Konfiguration über `data-*`-Attribute (api-url, min-chars, debounce, lang, types, results-url)
- [x] Overlay: Kategorie-Tabs (mobil horizontal oben, Desktop Sidebar 170px links)
- [x] Brand-Rot `#e30613` durchgehend (Focus-Border, Tab-Indikatoren, Spinner, Clear-Gradient)
- [x] CSS-Spezifitäts-Overrides gegen Contao-Theme-Button-Styles (`background-color: #4f4f51`)

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

## Voraussetzungen für optionale Indexer

- **FaqIndexer:** Erfordert `contao/faq-bundle` — wenn nicht installiert, gibt `index()` 0 zurück (kein Fehler)

## Bekannte Probleme / Offene Punkte

### Mittel

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

## Kompatibilität

- **PHP:** `^8.2` (deckt 8.2, 8.3, 8.4 ab — vollständig rückwärtskompatibel)
- **Contao:** `^5.3` (deckt 5.3, 5.4, 5.5, 5.6, 5.7+ ab — nur stabile Core-APIs verwendet)

## Abhängigkeiten / Voraussetzungen

- Contao ^5.3
- PHP ^8.2 mit `pdo_sqlite`-Extension
- `var/`-Verzeichnis muss schreibbar sein (`var/search.db`)
- Für News-Indexer: `contao/news-bundle`
- Für Event-Indexer: `contao/calendar-bundle`
