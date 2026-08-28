<?php

$GLOBALS['TL_DCA']['tl_module']['palettes']['guc_search'] = '{title_legend},name,headline,type;{config_legend},guc_search_layout,guc_search_min_chars,guc_search_resultsPage,guc_search_types;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},guests,cssID';

$GLOBALS['TL_DCA']['tl_module']['subpalettes']['guc_search_layout_overlay'] = 'guc_search_trigger,guc_search_toggleIcon';

$GLOBALS['TL_DCA']['tl_module']['palettes']['guc_search_results'] = '{title_legend},name,headline,type;{config_legend},guc_search_perPage,guc_search_types;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},guests,cssID';

$GLOBALS['TL_DCA']['tl_module']['fields']['guc_search_perPage'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['guc_search_perPage'],
    'exclude'   => true,
    'inputType' => 'text',
    'eval'      => ['rgxp' => 'natural', 'minval' => 1, 'maxval' => 100, 'tl_class' => 'w50'],
    'sql'       => ['type' => 'smallint', 'unsigned' => true, 'default' => 10],
];

$GLOBALS['TL_DCA']['tl_module']['fields']['guc_search_layout'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['guc_search_layout'],
    'exclude'   => true,
    'inputType' => 'select',
    'options'   => ['inline', 'overlay'],
    'reference' => &$GLOBALS['TL_LANG']['tl_module']['guc_search_layout_options'],
    'eval'      => ['submitOnChange' => true, 'tl_class' => 'w50'],
    'sql'       => ['type' => 'string', 'length' => 16, 'default' => 'inline'],
];

// CSS selector of an element that already exists in the theme (e.g. a magnifier in
// the navigation template) which should open the overlay. Set = the module renders
// no button of its own, and guc_search_toggleIcon is ignored.
// Resolved per instance by walking up from the widget, so a theme may render the
// module more than once with one trigger each.
$GLOBALS['TL_DCA']['tl_module']['fields']['guc_search_trigger'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['guc_search_trigger'],
    'exclude'   => true,
    'inputType' => 'text',
    'eval'      => ['maxlength' => 128, 'decodeEntities' => true, 'tl_class' => 'w50'],
    'sql'       => ['type' => 'string', 'length' => 128, 'default' => ''],
];

// Only used by the overlay layout when no trigger selector is set — the magnifier
// that opens the overlay. Empty falls back to the inline SVG in the template.
$GLOBALS['TL_DCA']['tl_module']['fields']['guc_search_toggleIcon'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['guc_search_toggleIcon'],
    'exclude'   => true,
    'inputType' => 'fileTree',
    'eval'      => [
        'filesOnly'  => true,
        'fieldType'  => 'radio',
        'extensions' => 'svg,png,jpg,jpeg,gif,webp',
        'tl_class'   => 'w50 clr',
    ],
    'sql'       => ['type' => 'binary', 'length' => 16, 'fixed' => true, 'notnull' => false],
];

$GLOBALS['TL_DCA']['tl_module']['fields']['guc_search_resultsPage'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['guc_search_resultsPage'],
    'exclude'   => true,
    'inputType' => 'pageTree',
    'eval'      => ['fieldType' => 'radio', 'tl_class' => 'w50 widget'],
    'sql'       => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
];


$GLOBALS['TL_DCA']['tl_module']['fields']['guc_search_types'] = [
    'label'            => &$GLOBALS['TL_LANG']['tl_module']['guc_search_types'],
    'exclude'          => true,
    'inputType'        => 'checkbox',
    // _categories is a placeholder — SearchModuleController expands it to all active category aliases at render time
    'options_callback' => static function (): array {
        return [
            'page'        => $GLOBALS['TL_LANG']['tl_module']['guc_search_types_options']['page']         ?? 'Seiten',
            'news'        => $GLOBALS['TL_LANG']['tl_module']['guc_search_types_options']['news']         ?? 'News',
            'event'       => $GLOBALS['TL_LANG']['tl_module']['guc_search_types_options']['event']        ?? 'Events',
            'member'      => $GLOBALS['TL_LANG']['tl_module']['guc_search_types_options']['member']       ?? 'Team',
            'faq'         => $GLOBALS['TL_LANG']['tl_module']['guc_search_types_options']['faq']          ?? 'FAQ',
            'file'        => $GLOBALS['TL_LANG']['tl_module']['guc_search_types_options']['file']         ?? 'Dateien',
            '_categories' => $GLOBALS['TL_LANG']['tl_module']['guc_search_types_options']['_categories'] ?? 'Manuelle Kategorien',
        ];
    },
    'eval'             => ['multiple' => true, 'tl_class' => 'clr'],
    'sql'              => ['type' => 'blob', 'notnull' => false],
];

$GLOBALS['TL_DCA']['tl_module']['fields']['guc_search_min_chars'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['guc_search_min_chars'],
    'exclude'   => true,
    'inputType' => 'text',
    'eval'      => ['rgxp' => 'natural', 'minval' => 1, 'maxval' => 10, 'tl_class' => 'w50'],
    'sql'       => ['type' => 'smallint', 'unsigned' => true, 'default' => 2],
];
