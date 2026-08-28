<?php

$GLOBALS['TL_LANG']['MOD']['erweiterte_suche']      = 'Extended Search';
$GLOBALS['TL_LANG']['MOD']['guc_search_index']      = ['Search index', 'Index status and manual re-indexing'];
$GLOBALS['TL_LANG']['MOD']['guc_search_categories'] = ['Categories', 'Manage search categories'];
$GLOBALS['TL_LANG']['MOD']['guc_search']            = ['GUC Search', 'Search index management'];
$GLOBALS['TL_LANG']['FMD']['guc_search'] = ['Extended Ajax Search', 'Adds an AJAX full-text search field to the page.'];
$GLOBALS['TL_LANG']['FMD']['guc_search_results'] = ['Extended Search — Results page', 'Renders search results server-side from the FTS index. Reads "keywords" and "type" from the URL.'];
$GLOBALS['TL_LANG']['MSC']['guc_search_placeholder'] = 'Search…';
$GLOBALS['TL_LANG']['MSC']['guc_search_no_results'] = 'No results found.';
$GLOBALS['TL_LANG']['MSC']['guc_search_more'] = 'Show more';
$GLOBALS['TL_LANG']['MSC']['guc_search_open'] = 'Open search';
$GLOBALS['TL_LANG']['MSC']['guc_search_close'] = 'Close search';
$GLOBALS['TL_LANG']['tl_module']['guc_search_resultsPage'] = ['Results page', 'Page with the full search results view.'];
$GLOBALS['TL_LANG']['tl_module']['guc_search_types'] = ['Search categories', 'Which categories and content types should appear in the search. Empty = all active categories.'];
$GLOBALS['TL_LANG']['tl_module']['guc_search_types_options'] = [
    '_categories' => 'Manual categories (all active)',
    'news'        => 'News',
    'event'       => 'Events',
    'member'      => 'Team',
    'faq'         => 'FAQ',
    'file'        => 'Files',
    'page'        => 'Pages',
];
$GLOBALS['TL_LANG']['tl_module']['guc_search_min_chars'] = ['Minimum characters', 'Number of characters before the search starts (default: 2).'];
$GLOBALS['TL_LANG']['tl_module']['guc_search_perPage'] = ['Results per page', 'Number of results per page, or per category in the grouped overview (default: 10).'];
$GLOBALS['TL_LANG']['tl_module']['guc_search_layout'] = ['Layout', 'Inline: field and results sit in the page content. Overlay: a magnifier opens the search on top of the whole page.'];
$GLOBALS['TL_LANG']['tl_module']['guc_search_layout_options'] = [
    'inline'  => 'Inline (in page content)',
    'overlay' => 'Overlay (magnifier, e.g. in the header)',
];
$GLOBALS['TL_LANG']['tl_module']['guc_search_trigger'] = ['Existing element as trigger', 'CSS selector of an element that already exists in the theme (e.g. ".icon-search"). Empty = the module renders its own magnifier button.'];
$GLOBALS['TL_LANG']['tl_module']['guc_search_toggleIcon'] = ['Magnifier icon', 'Image for the open button. Empty = built-in SVG icon. Ignored when a trigger is set above.'];
