(function () {
    'use strict';

    function initSearch() {
        document.querySelectorAll('.guc-search').forEach(function (widget) {
            const input = widget.querySelector('.guc-search__input');
            const results = widget.querySelector('.guc-search__results');
            const clearBtn = widget.querySelector('.guc-search__clear');

            const apiUrl = widget.dataset.apiUrl || '/api/search';
            const minChars = parseInt(widget.dataset.minChars || '2', 10);
            const debounce = parseInt(widget.dataset.debounce || '400', 10);
            const lang = widget.dataset.lang || '';
            const resultsUrl = widget.dataset.resultsUrl || '';
            const typesFilter = widget.dataset.types || '';

            let timer = null;
            let currentQuery = '';
            let abortController = null;

            input.addEventListener('input', function () {
                const query = input.value.trim();
                clearBtn.hidden = query.length === 0;

                clearTimeout(timer);

                if (query.length < minChars) {
                    hideResults();
                    return;
                }

                timer = setTimeout(function () {
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
                    hideResults();
                    input.blur();
                } else if (e.key === 'ArrowDown' && !results.hidden) {
                    e.preventDefault();
                    var first = results.querySelector('.guc-search__link, .guc-search__more');
                    if (first) first.focus();
                }
            });

            results.addEventListener('keydown', function (e) {
                var activePanel = results.querySelector('.guc-search__list:not([hidden])');
                var focusable = Array.prototype.slice.call(
                    (activePanel || results).querySelectorAll('.guc-search__link')
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
                if (!widget.contains(e.target)) {
                    hideResults();
                }
            });

            function doSearch(query) {
                if (query === currentQuery) return;
                currentQuery = query;

                if (abortController) {
                    abortController.abort();
                }
                abortController = new AbortController();

                results.innerHTML = '<div class="guc-search__loading" role="status" aria-label="Suche läuft…"></div>';
                results.hidden = false;

                var url = apiUrl + '?q=' + encodeURIComponent(query) + (lang ? '&lang=' + lang : '') + (typesFilter ? '&types=' + encodeURIComponent(typesFilter) : '');

                fetch(url, { signal: abortController.signal })
                    .then(function (res) {
                        if (!res.ok) throw new Error('HTTP ' + res.status);
                        return res.json();
                    })
                    .then(function (data) { renderResults(data, query); })
                    .catch(function (err) {
                        if (err.name !== 'AbortError') {
                            console.error('GUC Search error:', err);
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

                var tabs = document.createElement('div');
                tabs.className = 'guc-search__tabs';
                tabs.setAttribute('role', 'tablist');
                tabs.setAttribute('aria-label', 'Kategorien');

                var panelsEl = document.createElement('div');
                panelsEl.className = 'guc-search__panels';

                data.grouped.forEach(function (group, idx) {
                    var panelId = 'guc-panel-' + group.type;
                    var isFirst = idx === 0;

                    // Tab
                    var tab = document.createElement('button');
                    tab.type = 'button';
                    tab.className = 'guc-search__tab' + (isFirst ? ' guc-search__tab--active' : '');
                    tab.dataset.type = group.type;
                    tab.setAttribute('role', 'tab');
                    tab.setAttribute('aria-selected', isFirst ? 'true' : 'false');
                    tab.setAttribute('aria-controls', panelId);

                    var badge = document.createElement('span');
                    badge.className = 'guc-search__badge guc-search__badge--' + group.type;
                    badge.textContent = group.label;
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
                    document.getElementById(tab.getAttribute('aria-controls')).hidden = false;
                });

                results.appendChild(tabs);
                results.appendChild(panelsEl);
                results.hidden = false;
            }

            function hideResults() {
                results.hidden = true;
                currentQuery = '';
            }

            function escHtml(str) {
                return String(str)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;');
            }
        }); // end forEach
    } // end initSearch

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSearch);
    } else {
        initSearch();
    }
}());
