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
                var focusable = Array.prototype.slice.call(
                    results.querySelectorAll('.guc-search__link, .guc-search__more')
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
                if (!isPageMode && !widget.contains(e.target)) {
                    hideResults();
                }
            });

            // Results page mode: ?q= in URL → inline layout, no click-outside-close
            var urlQ = new URLSearchParams(window.location.search).get('q');
            var isPageMode = urlQ && urlQ.length >= minChars;
            if (isPageMode) {
                widget.classList.add('guc-search--page');
                input.value = urlQ;
                clearBtn.hidden = false;
                doSearch(urlQ);
            }

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

                data.grouped.forEach(function (group, idx) {
                    var groupEl = document.createElement('div');
                    groupEl.className = 'guc-search__group';
                    groupEl.dataset.type = group.type;

                    var header = document.createElement('div');
                    header.className = 'guc-search__group-header';
                    header.innerHTML =
                        '<span class="guc-search__badge guc-search__badge--' + group.type + '">' + escHtml(group.label) + '</span>' +
                        '<span class="guc-search__count">' + group.total + ' Treffer</span>';

                    var list = document.createElement('ul');
                    list.className = 'guc-search__list';

                    group.results.forEach(function (result) {
                        var li = document.createElement('li');
                        li.className = 'guc-search__item';

                        var a = document.createElement('a');
                        a.href = result.url;
                        a.className = 'guc-search__link';

                        var strong = document.createElement('strong');
                        strong.className = 'guc-search__title';
                        if (result.titleHighlight) {
                            // Server guarantees only <mark> tags
                            strong.innerHTML = result.titleHighlight;
                        } else {
                            strong.textContent = result.title;
                        }
                        a.appendChild(strong);

                        if (result.excerpt) {
                            var span = document.createElement('span');
                            span.className = 'guc-search__excerpt';
                            // Server guarantees only <mark> tags in excerpt
                            span.innerHTML = result.excerpt;
                            a.appendChild(span);
                        }

                        li.appendChild(a);
                        list.appendChild(li);
                    });

                    groupEl.appendChild(header);
                    groupEl.appendChild(list);

                    if (group.hasMore) {
                        var more = document.createElement('a');
                        var moreBase = resultsUrl || window.location.pathname;
                        more.href = moreBase + '?q=' + encodeURIComponent(query) + '&type=' + group.type;
                        more.className = 'guc-search__more';
                        more.textContent = 'Mehr anzeigen';
                        groupEl.appendChild(more);
                    }

                    results.appendChild(groupEl);

                    if (idx < data.grouped.length - 1) {
                        var hr = document.createElement('hr');
                        hr.className = 'guc-search__divider';
                        results.appendChild(hr);
                    }
                });

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
