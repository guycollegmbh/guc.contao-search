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
