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

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                hideResults();
                input.blur();
            }
        });

        function doSearch(query) {
            if (query === currentQuery) return;
            currentQuery = query;

            if (abortController) {
                abortController.abort();
            }
            abortController = new AbortController();

            const url = apiUrl + '?q=' + encodeURIComponent(query) + (lang ? '&lang=' + lang : '');

            fetch(url, { signal: abortController.signal })
                .then(function (res) {
                    if (!res.ok) {
                        throw new Error('HTTP ' + res.status);
                    }
                    return res.json();
                })
                .then(function (data) { renderResults(data, query); })
                .catch(function (err) {
                    if (err.name !== 'AbortError') {
                        console.error('GUC Search error:', err);
                        results.innerHTML = '<p class="guc-search__empty">Suche nicht verfügbar.</p>';
                        showResults();
                    }
                });
        }

        function renderResults(data, query) {
            results.innerHTML = '';

            if (!data.grouped || data.grouped.length === 0) {
                results.innerHTML = '<p class="guc-search__empty">Keine Ergebnisse gefunden.</p>';
                showResults();
                return;
            }

            data.grouped.forEach(function (group, idx) {
                const groupEl = document.createElement('div');
                groupEl.className = 'guc-search__group';
                groupEl.dataset.type = group.type;

                const header = document.createElement('div');
                header.className = 'guc-search__group-header';
                header.innerHTML =
                    '<span class="guc-search__badge guc-search__badge--' + group.type + '">' + escHtml(group.label) + '</span>' +
                    '<span class="guc-search__count">' + group.total + ' Treffer</span>';

                const list = document.createElement('ul');
                list.className = 'guc-search__list';

                group.results.forEach(function (result) {
                    const li = document.createElement('li');
                    li.className = 'guc-search__item';

                    const a = document.createElement('a');
                    a.href = result.url;
                    a.className = 'guc-search__link';

                    const strong = document.createElement('strong');
                    strong.className = 'guc-search__title';
                    strong.textContent = result.title;
                    a.appendChild(strong);

                    if (result.excerpt) {
                        const span = document.createElement('span');
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
                    const more = document.createElement('a');
                    more.href = '?q=' + encodeURIComponent(query) + '&type=' + group.type;
                    more.className = 'guc-search__more';
                    more.textContent = 'Mehr anzeigen';
                    groupEl.appendChild(more);
                }

                results.appendChild(groupEl);

                if (idx < data.grouped.length - 1) {
                    const hr = document.createElement('hr');
                    hr.className = 'guc-search__divider';
                    results.appendChild(hr);
                }
            });

            showResults();
        }

        function showResults() {
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
