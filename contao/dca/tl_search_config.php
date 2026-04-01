<?php

use Contao\DataContainer;
use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_search_config'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        'enableVersioning' => true,
        'sql'              => [
            'keys' => [
                'id' => 'primary',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode'        => DataContainer::MODE_SORTABLE,
            'fields'      => ['tableName'],
            'flag'        => DataContainer::SORT_INITIAL_LETTER_ASC,
            'panelLayout' => 'filter;search,limit',
        ],
        'label' => [
            'fields' => ['tableName', 'badge'],
            'format' => '%s <span style="color:#999">[%s]</span>',
        ],
        'global_operations' => [
            'all' => [
                'href'       => 'act=selectAll',
                'class'      => 'header_edit_all',
                'attributes' => 'onclick="Backend.getScrollOffset()" accesskey="e"',
            ],
        ],
        'operations' => [
            'edit'   => ['href' => 'act=edit', 'icon' => 'edit.svg'],
            'delete' => ['href' => 'act=delete', 'icon' => 'delete.svg', 'attributes' => 'onclick="if(!confirm(\'Wirklich löschen?\'))return false;Backend.getScrollOffset()"'],
            'toggle' => [
                'href'         => 'act=toggle&amp;field=active',
                'icon'         => 'visible.svg',
                'reverseToggle'=> 'invisible.svg',
            ],
        ],
    ],
    'palettes' => [
        'default' => '{config_legend},tableName,titleField,bodyField,aliasField,urlPattern,badge,active',
    ],
    'fields' => [
        'id'        => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp'    => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'tableName' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_search_config']['tableName'],
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['mandatory' => true, 'maxlength' => 64, 'tl_class' => 'w50'],
            'sql'       => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
        'titleField' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_search_config']['titleField'],
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['mandatory' => true, 'maxlength' => 64, 'tl_class' => 'w50'],
            'sql'       => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
        'bodyField' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_search_config']['bodyField'],
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['mandatory' => true, 'maxlength' => 64, 'tl_class' => 'w50'],
            'sql'       => ['type' => 'string', 'length' => 64, 'default' => ''],
        ],
        'aliasField' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_search_config']['aliasField'],
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['maxlength' => 64, 'tl_class' => 'w50'],
            'sql'       => ['type' => 'string', 'length' => 64, 'default' => 'alias'],
        ],
        'urlPattern' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_search_config']['urlPattern'],
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['mandatory' => true, 'maxlength' => 255, 'tl_class' => 'w50'],
            'sql'       => ['type' => 'string', 'length' => 255, 'default' => '/%s.html'],
        ],
        'badge' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_search_config']['badge'],
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['maxlength' => 32, 'tl_class' => 'w50'],
            'sql'       => ['type' => 'string', 'length' => 32, 'default' => ''],
        ],
        'active' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_search_config']['active'],
            'exclude'   => true,
            'inputType' => 'checkbox',
            'eval'      => ['doNotCopy' => true, 'tl_class' => 'w50 m12'],
            'sql'       => ['type' => 'string', 'length' => 1, 'fixed' => true, 'default' => ''],
        ],
    ],
];
