<?php

declare(strict_types=1);

use Contao\CoreBundle\DataContainer\PaletteManipulator;

// Add guc_categories field — options loaded dynamically from tl_guc_category
$GLOBALS['TL_DCA']['tl_article']['fields']['guc_categories'] = [
    'label'            => &$GLOBALS['TL_LANG']['tl_article']['guc_categories'],
    'exclude'          => true,
    'inputType'        => 'checkboxWizard',
    'options_callback' => static function (): array {
        $result = \Contao\Database::getInstance()->execute(
            "SELECT id, title FROM tl_guc_category WHERE active='1' ORDER BY title"
        );
        $options = [];
        while ($result->next()) {
            $options[$result->id] = $result->title;
        }
        return $options;
    },
    'eval'             => ['multiple' => true, 'tl_class' => 'clr'],
    'sql'              => ['type' => 'blob', 'notnull' => false],
];

// Inject into palette — new legend before publish options
PaletteManipulator::create()
    ->addLegend('guc_search_legend', 'published_legend', PaletteManipulator::POSITION_BEFORE)
    ->addField('guc_categories', 'guc_search_legend', PaletteManipulator::POSITION_APPEND)
    ->applyToPalette('default', 'tl_article');
