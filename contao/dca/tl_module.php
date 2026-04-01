<?php

$GLOBALS['TL_DCA']['tl_module']['palettes']['guc_search'] = '{title_legend},name,headline,type;{config_legend},guc_search_min_chars,guc_search_resultsPage;{template_legend:hide},customTpl;{protected_legend:hide},protected;{expert_legend:hide},guests,cssID';

$GLOBALS['TL_DCA']['tl_module']['fields']['guc_search_resultsPage'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['guc_search_resultsPage'],
    'exclude'   => true,
    'inputType' => 'pageTree',
    'eval'      => ['fieldType' => 'radio', 'tl_class' => 'w50 widget'],
    'sql'       => ['type' => 'integer', 'unsigned' => true, 'default' => 0],
];

$GLOBALS['TL_DCA']['tl_module']['fields']['guc_search_min_chars'] = [
    'label'     => &$GLOBALS['TL_LANG']['tl_module']['guc_search_min_chars'],
    'exclude'   => true,
    'inputType' => 'text',
    'eval'      => ['rgxp' => 'natural', 'minval' => 1, 'maxval' => 10, 'tl_class' => 'w50'],
    'sql'       => ['type' => 'smallint', 'unsigned' => true, 'default' => 2],
];
