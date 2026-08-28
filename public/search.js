(function () {
    'use strict';

    function initSearch() {
        // The overlay layer also carries `guc-search` (for CSS scoping) but is not
        // a widget of its own — skip it.
        document.querySelectorAll('.guc-search:not(.guc-search__layer)').forEach(function (widget, widgetIndex) {
            // Themes may render the widget more than once (e.g. desktop + mobile header).
            // Prefix every generated DOM id so the instances cannot collide.
            const uid         = 'guc-s' + widgetIndex + '-';
            const input       = widget.querySelector('.guc-search__input');
            const results     = widget.querySelector('.guc-search__results');
            const clearBtn    = widget.querySelector('.guc-search__clear');

            // Overlay layout only — null for the inline layout.
            const overlay     = widget.querySelector('.guc-search__layer');
            const closeBtn    = widget.querySelector('.guc-search__close');
            const triggerSel  = widget.dataset.trigger || '';

            // Either the module's own button, or an element that already exists in
            // the theme (e.g. a magnifier in the navigation template).
            const toggleBtn   = triggerSel
                ? findExternalTrigger(triggerSel)
                : widget.querySelector('.guc-search__toggle');

            const apiUrl      = widget.dataset.apiUrl || '/api/search';
            const suggestUrl  = (widget.dataset.apiUrl || '/api/search').replace(/\/search$/, '/search/suggestions');
            const minChars    = parseInt(widget.dataset.minChars || '2', 10);
            const debounce    = parseInt(widget.dataset.debounce || '400', 10);
            const lang        = widget.dataset.lang || '';
            const resultsUrl  = widget.dataset.resultsUrl || '';
            const typesFilter = widget.dataset.types || '';

            let searchTimer    = null;
            let suggestTimer   = null;
            let currentQuery   = '';
            let abortSearch    = null;
            let abortSuggest   = null;

            input.addEventListener('input', function () {
                var query = input.value.trim();
                clearBtn.hidden = query.length === 0;

                clearTimeout(searchTimer);
                clearTimeout(suggestTimer);

                if (query.length < minChars) {
                    hideResults();
                    return;
                }

                // Suggestions: fast, 150 ms debounce
                suggestTimer = setTimeout(function () {
                    doSuggest(query);
                }, 150);

                // Full search: slower debounce
                searchTimer = setTimeout(function () {
                    clearTimeout(suggestTimer);
                    doSearch(query);
                }, debounce);
            });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    var query = input.value.trim();
                    if (query.length >= minChars && resultsUrl) {
                        e.preventDefault();
                        window.location.href = resultsUrl + '?keywords=' + encodeURIComponent(query);
                    }
                } else if (e.key === 'Escape') {
                    if (overlay) {
                        closeOverlay();
                    } else {
                        hideResults();
                        input.blur();
                    }
                } else if (e.key === 'ArrowDown' && !results.hidden) {
                    e.preventDefault();
                    var first = results.querySelector('.guc-search__suggestion, .guc-search__link, .guc-search__more');
                    if (first) first.focus();
                }
            });

            results.addEventListener('keydown', function (e) {
                var focusable = Array.prototype.slice.call(
                    results.querySelectorAll('.guc-search__suggestion, .guc-search__link, .guc-search__more')
                );
                var idx = focusable.indexOf(document.activeElement);

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (idx < focusable.length - 1) focusable[idx + 1].focus();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (idx > 0) focusable[idx - 1].focus();
                    else input.focus();
                } else if (e.key === 'Escape') {
                    hideResults();
                    input.focus();
                }
            });

            clearBtn.addEventListener('click', function () {
                input.value = '';
                clearBtn.hidden = true;
                hideResults();
                input.focus();
            });

            document.addEventListener('click', function (e) {
                // The overlay lives outside the widget once it has been moved to
                // <body>, so it needs to be checked separately.
                if (widget.contains(e.target)) return;
                if (overlay && overlay.contains(e.target)) return;
                hideResults();
            });

            // ── Fullscreen overlay ────────────────────────────────────────────

            let scrollLock = '';

            if (overlay && toggleBtn) {
                // position:fixed resolves against the nearest ancestor with a
                // transform/filter/perspective, which a sticky header may well
                // have. Reparenting to <body> sidesteps that entirely.
                overlay.id = uid + 'overlay';
                toggleBtn.setAttribute('aria-controls', overlay.id);
                toggleBtn.setAttribute('aria-expanded', 'false');
                document.body.appendChild(overlay);

                // A theme element (often an <img>) is neither focusable nor
                // operable by keyboard — make it behave like a button.
                if (toggleBtn.tagName !== 'BUTTON') {
                    toggleBtn.setAttribute('role', 'button');
                    toggleBtn.setAttribute('tabindex', '0');
                    if (!toggleBtn.getAttribute('aria-label')) {
                        toggleBtn.setAttribute('aria-label', widget.dataset.openLabel || 'Suche öffnen');
                    }
                    toggleBtn.style.cursor = 'pointer';
                    toggleBtn.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            openOverlay();
                        }
                    });
                }

                toggleBtn.addEventListener('click', openOverlay);
                closeBtn.addEventListener('click', closeOverlay);

                overlay.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') {
                        closeOverlay();
                    } else if (e.key === 'Tab') {
                        trapFocus(e);
                    }
                });

                // Click on the backdrop, i.e. next to the inner container
                overlay.addEventListener('mousedown', function (e) {
                    if (e.target === overlay) closeOverlay();
                });
            } else if (overlay && triggerSel) {
                // Fail loudly instead of leaving a search that silently never opens.
                window.console && console.warn(
                    'guc-search: no element matching "' + triggerSel + '" found near the widget — the overlay cannot be opened.'
                );
            }

            function openOverlay() {
                overlay.hidden = false;
                toggleBtn.setAttribute('aria-expanded', 'true');
                scrollLock = document.body.style.overflow;
                document.body.style.overflow = 'hidden';
                input.focus();
            }

            function closeOverlay() {
                if (overlay.hidden) return;
                overlay.hidden = true;
                toggleBtn.setAttribute('aria-expanded', 'false');
                document.body.style.overflow = scrollLock;
                input.value = '';
                clearBtn.hidden = true;
                hideResults();
                toggleBtn.focus();
            }

            /**
             * Finds the trigger belonging to *this* instance: walk up from the widget
             * and take the first ancestor that contains a match. A theme rendering the
             * module twice (desktop + mobile) thus gets one trigger each instead of
             * both instances binding to the first match in the document.
             */
            function findExternalTrigger(selector) {
                var node;
                try {
                    node = widget.parentElement;
                } catch (e) {
                    return null;
                }

                for (var depth = 0; node && node !== document.body && depth < 6; depth++) {
                    var hit;
                    try {
                        hit = node.querySelector(selector);
                    } catch (e) {
                        return null; // invalid selector from the module config
                    }
                    if (hit) return hit;
                    node = node.parentElement;
                }

                return null;
            }

            function trapFocus(e) {
                var focusable = Array.prototype.filter.call(
                    overlay.querySelectorAll('a[href], button:not([disabled]), input, [tabindex="0"]'),
                    function (el) { return !el.hidden && el.offsetParent !== null; }
                );
                if (focusable.length === 0) return;

                var first = focusable[0];
                var last  = focusable[focusable.length - 1];

                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }

            // ── Autocomplete suggestions ──────────────────────────────────────

            function doSuggest(query) {
                if (abortSuggest) abortSuggest.abort();
                abortSuggest = new AbortController();

                var url = suggestUrl + '?q=' + encodeURIComponent(query);
                fetch(url, { signal: abortSuggest.signal })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        // Only show if still in "waiting for full results" phase
                        if (input.value.trim() === query && currentQuery !== query) {
                            renderSuggestions(data.suggestions || [], query);
                        }
                    })
                    .catch(function () {});
            }

            function renderSuggestions(suggestions, query) {
                if (suggestions.length === 0) return;

                results.innerHTML = '';
                var list = document.createElement('ul');
                list.className = 'guc-search__suggestions';
                list.setAttribute('role', 'listbox');
                list.setAttribute('aria-label', 'Vorschläge');

                suggestions.forEach(function (word) {
                    var li = document.createElement('li');
                    li.setAttribute('role', 'option');

                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'guc-search__suggestion';
                    // Highlight matching prefix
                    var match = word.substring(0, query.length);
                    var rest  = word.substring(query.length);
                    btn.innerHTML = '<mark>' + escText(match) + '</mark>' + escText(rest);

                    btn.addEventListener('click', function () {
                        input.value = word;
                        clearBtn.hidden = false;
                        clearTimeout(searchTimer);
                        clearTimeout(suggestTimer);
                        doSearch(word);
                    });

                    li.appendChild(btn);
                    list.appendChild(li);
                });

                results.appendChild(list);
                results.hidden = false;
            }

            // ── Full search ───────────────────────────────────────────────────

            function doSearch(query) {
                if (query === currentQuery) return;
                currentQuery = query;

                if (abortSearch) abortSearch.abort();
                abortSearch = new AbortController();

                results.innerHTML = '<div class="guc-search__loading" role="status" aria-label="Suche läuft…"></div>';
                results.hidden = false;

                var url = apiUrl + '?q=' + encodeURIComponent(query)
                    + (lang ? '&lang=' + lang : '')
                    + (typesFilter ? '&types=' + encodeURIComponent(typesFilter) : '');

                fetch(url, { signal: abortSearch.signal })
                    .then(function (res) {
                        if (!res.ok) throw new Error('HTTP ' + res.status);
                        return res.json();
                    })
                    .then(function (data) { renderResults(data, query); })
                    .catch(function (err) {
                        if (err.name !== 'AbortError') {
                            results.innerHTML = '<p class="guc-search__empty">Suche nicht verfügbar.</p>';
                            results.hidden = false;
                        }
                    });
            }

            function renderResults(data, query) {
                results.innerHTML = '';

                if (!data.grouped || data.grouped.length === 0) {
                    results.innerHTML = '<p class="guc-search__empty">Keine Ergebnisse gefunden.</p>';
                    results.hidden = false;
                    return;
                }

                // "Meinten Sie X?" banner when fuzzy fallback was used
                if (data.fuzzy && data.suggestion) {
                    var hint = document.createElement('p');
                    hint.className = 'guc-search__fuzzy-hint';
                    hint.innerHTML = 'Keine Treffer für «' + escText(query) + '» — '
                        + 'Ergebnisse für «<strong>' + escText(data.suggestion) + '</strong>»';
                    results.appendChild(hint);
                }

                var tabs = document.createElement('div');
                tabs.className = 'guc-search__tabs';
                tabs.setAttribute('role', 'tablist');
                tabs.setAttribute('aria-label', 'Kategorien');

                var panelsEl = document.createElement('div');
                panelsEl.className = 'guc-search__panels';

                data.grouped.forEach(function (group, idx) {
                    var tabId   = uid + 'tab-' + group.type;
                    var panelId = uid + 'panel-' + group.type;
                    var isFirst = idx === 0;

                    // Tab
                    var tab = document.createElement('button');
                    tab.type = 'button';
                    tab.id   = tabId;
                    tab.className = 'guc-search__tab' + (isFirst ? ' guc-search__tab--active' : '');
                    tab.dataset.type = group.type;
                    tab.setAttribute('role', 'tab');
                    tab.setAttribute('aria-selected', isFirst ? 'true' : 'false');
                    tab.setAttribute('aria-controls', panelId);
                    tab.dataset.index = idx;

                    var badge = document.createElement('span');
                    badge.className = 'guc-search__badge guc-search__badge--' + group.type;
                    badge.textContent = group.label;
                    if (group.color)     badge.style.backgroundColor = group.color;
                    if (group.lightText) badge.style.color = '#ffffff';
                    tab.appendChild(badge);

                    var cnt = document.createElement('span');
                    cnt.className = 'guc-search__tab-count';
                    cnt.textContent = group.total;
                    tab.appendChild(cnt);

                    tabs.appendChild(tab);

                    // Panel
                    var list = document.createElement('ul');
                    list.className = 'guc-search__list';
                    list.id = panelId;
                    list.setAttribute('role', 'tabpanel');
                    list.setAttribute('aria-labelledby', tabId);
                    list.setAttribute('tabindex', '0');
                    if (!isFirst) list.hidden = true;

                    group.results.forEach(function (result) {
                        var li = document.createElement('li');
                        li.className = 'guc-search__item';

                        var a = document.createElement('a');
                        a.href = result.url;
                        a.className = 'guc-search__link';

                        var strong = document.createElement('strong');
                        strong.className = 'guc-search__title';
                        if (result.titleHighlight) {
                            strong.innerHTML = result.titleHighlight;
                        } else {
                            strong.textContent = result.title;
                        }
                        a.appendChild(strong);

                        if (result.excerpt) {
                            var span = document.createElement('span');
                            span.className = 'guc-search__excerpt';
                            span.innerHTML = result.excerpt;
                            a.appendChild(span);
                        }

                        li.appendChild(a);
                        list.appendChild(li);
                    });

                    // "Mehr anzeigen" link
                    if (group.hasMore && resultsUrl) {
                        var moreLi = document.createElement('li');
                        moreLi.className = 'guc-search__item guc-search__item--more';
                        var more = document.createElement('a');
                        more.className = 'guc-search__more';
                        more.href = resultsUrl + '?keywords=' + encodeURIComponent(query) + '&type=' + encodeURIComponent(group.type);
                        more.textContent = 'Alle ' + group.total + ' Ergebnisse anzeigen →';
                        moreLi.appendChild(more);
                        list.appendChild(moreLi);
                    }

                    panelsEl.appendChild(list);
                });

                // Tab-Switch
                tabs.addEventListener('click', function (e) {
                    var tab = e.target.closest('.guc-search__tab');
                    if (!tab) return;
                    tabs.querySelectorAll('.guc-search__tab').forEach(function (t) {
                        t.classList.remove('guc-search__tab--active');
                        t.setAttribute('aria-selected', 'false');
                    });
                    panelsEl.querySelectorAll('.guc-search__list').forEach(function (p) {
                        p.hidden = true;
                    });
                    tab.classList.add('guc-search__tab--active');
                    tab.setAttribute('aria-selected', 'true');
                    // Resolve the panel by position within this widget, not by a
                    // document-wide id lookup — several widgets may be on the page.
                    var panel = panelsEl.children[parseInt(tab.dataset.index, 10)];
                    if (panel) panel.hidden = false;
                });

                results.appendChild(tabs);
                results.appendChild(panelsEl);
                results.hidden = false;
            }

            function hideResults() {
                results.hidden = true;
                currentQuery = '';
            }

            function escText(str) {
                return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }
        }); // end forEach
    } // end initSearch

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSearch);
    } else {
        initSearch();
    }
}());
