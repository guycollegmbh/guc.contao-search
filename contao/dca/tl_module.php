<?php

$GLOBALS['TL_DCA']['tl_module']['palettes']['guc_search'] = '{title_legend},name,headline,type;{config_legend},guc_search_min_chars,guc_search_resultsPage,guc_search_types;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},guests,cssID';

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
