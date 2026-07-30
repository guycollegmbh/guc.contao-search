<?php

// Frontend module registration is handled via
// #[AsFrontendModule] attribute on SearchModuleController.

// Backend module group "Erweiterte Suche"
$GLOBALS['BE_MOD']['erweiterte_suche'] = [
    'guc_search_categories' => [
        'tables' => ['tl_guc_category'],
    ],
];
