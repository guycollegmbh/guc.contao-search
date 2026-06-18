# STATUS

Zuletzt aktualisiert: 2026-06-18

## Entwicklungsstand

**Phase:** Initiale Implementierung abgeschlossen — noch nicht produktiv eingesetzt.

## Was funktioniert

- [x] SQLite FTS5 Datenbank mit automatischer Schema-Erstellung
- [x] Alle 5 Indexer implementiert (page, file, news, event, custom)
- [x] CLI-Befehl `guc:search:index`
- [x] JSON-API `/api/search` mit Gruppierung, Paginierung, Sprachfilter
- [x] Backend-Verwaltung mit manueller Re-Indexierung pro Typ
- [x] Frontend-Modul mit Live-Suche (Debounce, AbortController)
- [x] Custom-Tabellen-Konfiguration via Backend (tl_search_config)
- [x] Input-Validierung in API und CustomTableIndexer
- [x] Zweisprachige Labels (de/en)
- [x] PageIndexer liest direkt aus tl_content/tl_article (unabhängig von Contaos Crawler)
- [x] News- und Event-URLs dynamisch über jumpTo-Seite aufgelöst

## Bekannte Probleme

### Kritisch

1. ~~**Route erlaubt kein POST**~~ — behoben 2026-06-18

### Mittel

2. ~~**Hard-codierte URLs in NewsIndexer/EventIndexer**~~ — behoben 2026-06-18

3. ~~**PageIndexer abhängig von Contaos Suchindex**~~ — behoben 2026-06-18 (liest jetzt tl_content)

4. **`guc_search_resultsPage` nicht implementiert**:
   Das DCA-Feld im Frontend-Modul ist definiert,
   wird aber von `SearchModuleController` nicht ausgewertet.

### Niedrig

5. ~~**Toter Code**~~ — bereinigt 2026-06-18:
   - `src/ContaoManagerPlugin.php` ✓
   - `contao/config.php` ✓
   - `templates/search_module.html.twig` ✓
   - `templates/search_results.html.twig` ✓
   - `src/Module/ModuleSearch.php` ✓
   - `contao/templates/mod_guc_search.html5` ✓

## Noch nicht implementiert / Nächste Schritte

- [ ] **Automatische Indexierung** via Contao-Hook oder Symfony-Event beim Speichern
- [ ] **Volltext-Extraktion** für PDFs (aktuell nur Metadaten aus tl_files)
- [ ] **Ergebnisseite** für gefilterte Suche (guc_search_resultsPage auswerten)
- [ ] **Pagination im Frontend** (JS für "Mehr laden" auf der Ergebnisseite)
- [ ] **Tests** (PHPUnit/Pest für Indexer und Repository)
- [ ] **Fehlende EN-Labels** für tl_search_config

## Abhängigkeiten / Voraussetzungen

- Contao ^5.3 muss installiert und konfiguriert sein
- PHP SQLite-Extension (`pdo_sqlite`) muss aktiviert sein
- `var/`-Verzeichnis muss schreibbar sein (für `search.db`)
- Für News-/Events-Indexer: `contao/news-bundle` bzw. `contao/calendar-bundle`
