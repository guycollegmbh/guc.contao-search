# HISTORY

Chronologisches Entwicklungsprotokoll des `guc/search-bundle`.
Neueste Einträge zuerst.

---

## 2026-06-25 — Bugfixes & umfassende Verbesserungen

### Kritischer Bugfix: Frontend-Widget zeigte keine Resultate

**Ursache:** `|static`-Flag in `TL_JAVASCRIPT` kombiniert `search.js` mit jQuery
und lädt das Ergebnis als `<script>` im `<head>`. Das IIFE lief damit vor dem
DOM-Parse — `querySelectorAll('.guc-search')` fand nichts, keine Event-Listener
wurden registriert, kein API-Request wurde je ausgelöst.

**Fix:** `|static` entfernt (Script lädt jetzt vor `</body>`); zusätzlich
`DOMContentLoaded`-Guard im IIFE als Absicherung gegen zukünftige Platzierung.

**Commit:** `a769a1f`

---

### Bugfix: API lieferte leere Resultate bei gesetztem `lang`-Parameter

**Ursache:** `searchByType()` und `countByType()` verwendeten selbstreferenzielle
FTS5-Unterabfragen (`rowid IN (SELECT rowid FROM search_index WHERE ...)`),
die in bestimmten SQLite-Versionen mit gleichzeitigem MATCH-Operator versagen.

**Fix:** Direkte Spaltenfilter als FTS5-Post-Filter:
```sql
WHERE search_index MATCH :query AND type = :type AND (language = :lang OR language = '')
```

**Commit:** `3854281`

---

### Bugfix: Such-URLs mit hartem `.html`-Suffix

**Ursache:** Alle drei Indexer (Page, News, Event) hatten `.html` hardcodiert.

**Fix:** URL-Suffix wird aus `tl_page.urlSuffix` der Root-Seite gelesen,
aufgelöst per pid-Traversal bis zur Root-Seite.

**Commit:** `823f7b4`

---

### Feature: Performance

| Änderung | Effekt |
|---|---|
| `PRAGMA journal_mode=WAL` | Parallele Reads während Index-Rebuild |
| `PRAGMA synchronous=NORMAL` | Schnellere Writes |
| `PRAGMA cache_size=-10000` | 10 MB SQLite Query-Cache |
| FTS5 `prefix='2 3 4'` | Precompiled Präfixe → schnellere Resultate beim Tippen |
| Schema-Migration via `PRAGMA user_version` | Automatischer Rebuild bei Schema-Änderung |
| `Cache-Control: private, max-age=30` | Browsercaching für identische Suchanfragen |

---

### Feature: Suchqualität

- **bm25-Gewichtung:** Titel-Treffer 10× stärker als Body (`bm25(... 10.0, 1.0 ...)`)
- **Volltext News/Events:** `tl_content` vollständig indexiert (nicht nur Teaser)
- **PageIndexer:** `noSearch='1'` und `sitemap='map_never'`-Seiten ausgeschlossen
- **Title-Highlighting:** `snippet()` auf title-Spalte, `<mark>`-Tags in API + Frontend

---

### Feature: Frontend-UX

- Lade-Spinner (CSS-Animation) während API-Fetch
- Tastaturnavigation: `ArrowDown`/`ArrowUp` + `Escape`
- Fokus-Styles für Keyboard-User
- Mobile Overflow-Fix (`width:100%` + `max-width:100vw`)
- Fehlerstatus "Suche nicht verfügbar." bei HTTP-Fehler

---

### Feature: Backend

- Health-Warning (⚠) bei leerem oder >24 h altem Index
- `try-catch` um alle DB-Operationen → JSON statt 500-HTML bei Fehler

**Commit:** `5af504f`

---

## 2026-06-26 — Overlay-Redesign: Sidebar/Tabs-Navigation

### Feature: Kategorie-Navigation im Suchergebnis-Overlay

**Vorher:** Lange vertikale Liste aller Kategorien untereinander.

**Nachher:**
- **Mobil** (`< 520px`): Horizontale Tab-Leiste oben (scrollbar, ohne Scrollbalken), Resultate darunter
- **Desktop** (`≥ 520px`): Sidebar links (170px) mit vertikalen Tabs, roter Balken am rechten Rand des aktiven Tabs
- Klick auf Kategorie wechselt die Resultate; Keyboard-Navigation bleibt auf das aktive Panel beschränkt

**CSS-Besonderheiten:**
- `.guc-search .guc-search__tab` und `.guc-search .guc-search__clear` mit erhöhter Spezifität `(0,2,0,0)` um das Contao-Theme-Selektor `[type=button]` zu überschreiben (`background-color: #4f4f51`, `border: 2px solid #4f4f51`, `color: #fff`)
- `<div role="tablist">` statt `<nav>` um Theme-`nav`-Stile zu vermeiden
- Hover/Focus/Active vollständig überschrieben (`background-color: transparent`, `border-color: transparent`)

**Styling-Details:**
- Brand-Rot `#e30613` durchgehend: Input-Focus-Border, Tab-Indikatoren, Lade-Spinner, Clear-Button-Gradient
- Clear-Button: roter Gradient (`rgb(227,6,19)` → `rgb(238,123,169)` → `rgb(227,6,19)`), 42px Höhe, Border-Radius 4px
- Mark-Highlighting: `rgba(246, 188, 209, 0.6)` (rosa, 60% Deckkraft — nur Hintergrund, nicht Text)
- `font-size: 0.925rem` für Input, Buttons, Badges, Tab-Count

---

## 2026-06-26 — Code-Review-Fixes (C2 / C5 / C7 / C8)

### C2: enabledTypes-Filter gilt jetzt auch für Single-Type-Pfad

**Problem:** `?type=member` lieferte Resultate auch wenn `member` in der Modul-Konfiguration deaktiviert war, da der `enabledTypes`-Check nur beim gruppierten Pfad griff.

**Fix:** In `SearchApiController` wird vor dem `searchByType()`-Aufruf geprüft ob `$type` in `$enabledTypes` liegt. Falls nicht, wird `{results:[], total:0, pages:0}` zurückgegeben.

---

### C5: FAQ-Einträge ohne Reader-Seite werden übersprungen

**Problem:** FAQ-Kategorien mit `jumpTo=0` (keine Leserseite konfiguriert) erzeugten tote URLs der Form `/faq/{alias}.html`.

**Fix:** `FaqIndexer` überspringt FAQ-Einträge mit `$jumpTo === 0`.

---

### C7: Veraltete tl_search-Einträge gefiltert

**Problem:** Der `tl_search`-Query holte alle Zeilen inkl. veralteter Einträge für gelöschte/depublizierte Seiten.

**Fix:** `INNER JOIN tl_page` mit `published='1' AND type='regular'`-Bedingung — nur Seiten im aktuellen Index werden geladen.

---

### C8: 14 SQLite-Queries pro Grouped-Request → 8

**Problem:** `searchGrouped()` feuerte 7 MATCH-Queries, danach weitere 7 `countByType()`-Calls = 14 Queries pro API-Request.

**Fix:** Neue Methode `SearchRepository::countGrouped()` aggregiert alle Counts in einem einzigen `SELECT type, COUNT(*) GROUP BY type`-Query. `SearchApiController` ruft `countGrouped()` einmalig auf statt 7×`countByType()`. Total: 7 MATCH + 1 COUNT = 8 Queries.

---

## 2026-06-26 — Ergebnisseite + Cron-Job + FAQ-Indexer

### Feature: Ergebnisseite via Modul-Konfiguration

**Implementierung:**
- `SearchModuleController`: Liest `guc_search_resultsPage` (pageTree-Feld), löst URL via `PageModel::getFrontendUrl()` auf, übergibt als `resultsPageUrl` ans Template
- Template: `data-results-url` Attribut am Widget-Container (leer wenn nicht konfiguriert)
- `search.js`:
  - **Enter-Taste:** Leitet auf `resultsPageUrl?q=suchbegriff` weiter (nur wenn konfiguriert und `minChars` erfüllt)
  - **Seitenaufruf:** Liest `?q=` aus URL-Parametern → füllt Suchfeld vor und startet Suche automatisch (für Ergebnisseite)
  - **"Mehr anzeigen":** Zeigt auf `resultsPageUrl?q=...&type=...` statt auf aktuelle Seite

**Konfiguration:** Im Contao Backend unter Modul → GUC Suche → "Ergebnisseite" eine Seite auswählen, die das GUC-Suchmodul enthält.

---

## 2026-06-26 — Automatischer Cron-Job + FAQ-Indexer

### Feature: Automatisches tägliches Indexieren

**Implementierung:** Neuer `RebuildSearchIndexCron` mit Contao `#[AsCronJob('daily')]` Attribut.
- Läuft täglich via Contaos Cron-System (Pseudo-Cron bei Seitenbesuchen oder externer Cron auf `/api/cron`)
- Ruft alle registrierten Indexer nacheinander auf
- Fehler in einzelnen Indexern werden geloggt, stoppen aber nicht die übrigen

**Dateien:** `src/Cron/RebuildSearchIndexCron.php`, `config/services.yaml`

---

### Feature: FaqIndexer — Häufige Fragen aus tl_faq

**Implementierung:** Neuer `FaqIndexer` (Typ `faq`):
- Liest publizierte FAQs aus `tl_faq` + `tl_faq_category` (erfordert `contao/faq-bundle`)
- Title = `question`, Body = `answer` (HTML-Tags entfernt)
- URL = `/{reader-seite-alias}/{faq-alias}` + URL-Suffix aus Root-Seite
- Sprache über `tl_faq_category.jumpTo` → `tl_page` aufgelöst
- Badge: "FAQ" (orangebrauner Badge)
- Wenn `tl_faq` nicht existiert (Bundle nicht installiert), gibt `index()` 0 zurück

**Aktualisiert:**
- `config/services.yaml`: FaqIndexer mit Tag `guc.search.indexer` registriert
- `SearchRepository::searchGrouped()`: Typ `faq` in Typen-Liste
- `SearchApiController`: `faq` in Whitelist + badgeLabel 'FAQ'
- `SearchIndexController`: `faq` in ALLOWED_TYPES + lastIndexed-Loop
- Backend-Template: faq-Zeile in allen Typ-Schleifen
- `search.css`: Badge-Farbe für `faq` (`#fde8d0` / `#7a3500`)

---

## 2026-06-25 — MemberIndexer: Team-Mitglieder aus tl_member

**Hintergrund:** Team-Mitglieder wurden nicht gefunden, da sie in `tl_member`
(Contao-Mitgliederverwaltung) gespeichert sind, nicht als Content-Elemente auf Seiten.

**Implementierung:** Neuer `MemberIndexer` (Typ `member`):
- Liest aktive Mitglieder aus `tl_member` (nicht disabled, start/stop berücksichtigt)
- Title = `firstname + lastname`, Body = `company` (Rolle/Position)
- URL-Seite wird automatisch ermittelt: zuerst nach Modul-Typ (`member%`, `mm_%`,
  `listing`, `%team%`), Fallback: Seite mit `team`/`mitglieder`/`personal` im Alias
- Sprache und URL-Suffix über pid-Traversal zur Root-Seite
- Badge: "Team" (grüner Badge `#d0f0e8`)

**Aktualisiert:**
- `services.yaml`: MemberIndexer mit Tag `guc.search.indexer` registriert
- `SearchRepository::searchGrouped()`: Typ `member` in Typen-Liste aufgenommen
- `SearchApiController`: `member` in Whitelist + badgeLabel 'Team'
- `SearchIndexController`: `member` in ALLOWED_TYPES + lastIndexed-Loop
- Backend-Template: member-Zeile in Tabelle
- `search.css`: Badge-Farbe für `member`

**Commit:** `a41aef9`

---

## 2026-06-18 — Fixes nach erster Installation auf lernwerk.guycolle.dev

### Template nicht gefunden

- Template von `templates/frontend_module/` nach `contao/templates/frontend_module/` verschoben
- `#[AsFrontendModule(template: 'frontend_module/guc_search')]` (Slash = Twig, kein Slash = Legacy)
- `.twig-root`-Marker in `contao/templates/` für korrekte rekursive Template-Erkennung
  (ohne Marker scannt `TemplateLocator` mit `depth < 1` → falscher Identifier)

**Commits:** `476439e`, `f2cad9f`

### SQL-Fehler (Contao 5.3-Kompatibilität)

- `tl_news.text` existiert nicht in Contao 5.3 → entfernt
- `tl_news.language` existiert nicht → Sprache über `LEFT JOIN tl_page`
- `tl_calendar_events.details` existiert nicht → entfernt
- `tl_calendar_events.language` existiert nicht → Sprache über `LEFT JOIN tl_page`

**Commit:** `86486de`, `19449b3`

### Weitere Fixes

- `SearchIndexListener` hinzugefügt für automatisches Re-Indexieren via DCA-Callbacks
- Route `/contao/guc-search` erlaubt jetzt POST (Backend-Reindex-Button)

**Commit:** `323cb7b`

---

## 2026-06-18 — Initiale Implementierung

- SQLite FTS5-Datenbank (`var/search.db`), unicode61-Tokenizer
- 5 Indexer: `page`, `file`, `news`, `event`, `custom`
- CLI-Befehl `guc:search:index`
- JSON-API `GET /api/search`
- Backend-Controller `/contao/guc-search`
- Frontend-Modul `guc_search`
- Sicherheitsmassnahmen: Validierung, Sanitisierung, CSRF, ROLE_ADMIN

**Commits:** `181637f`, `e9910b7`, `2d24e6b`

---

## Schema-Versionen (`PRAGMA user_version`)

| Version | Änderung | Re-Index |
|---------|----------|----------|
| 0 | Initiales Schema (`unicode61`) | — |
| 1 | `prefix='2 3 4'` (2026-06-25) | **Ja** |
