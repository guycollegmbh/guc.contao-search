<?php

declare(strict_types=1);

use Contao\DataContainer;
use Contao\DC_Table;

$GLOBALS['TL_DCA']['tl_guc_category'] = [
    'config' => [
        'dataContainer'    => DC_Table::class,
        'enableVersioning' => true,
        'sql'              => [
            'keys' => [
                'id'    => 'primary',
                'alias' => 'index',
            ],
        ],
    ],
    'list' => [
        'sorting' => [
            'mode'        => DataContainer::MODE_SORTED,
            'fields'      => ['title ASC'],
            'flag'        => DataContainer::SORT_INITIAL_LETTER_ASC,
            'panelLayout' => 'search,limit',
        ],
        'label' => [
            'fields' => ['title', 'alias'],
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
            'delete' => [
                'href'       => 'act=delete',
                'icon'       => 'delete.svg',
                'attributes' => 'onclick="if(!confirm(\'' . ($GLOBALS['TL_LANG']['MSC']['deleteConfirm'] ?? 'Wirklich löschen?') . '\'))return false;Backend.getScrollOffset()"',
            ],
            'toggle' => [
                'href'          => 'act=toggle&field=active',
                'icon'          => 'visible.svg',
                'reverseToggle' => 'invisible.svg',
            ],
        ],
    ],
    'palettes' => [
        'default' => '{title_legend},title,alias,color,lightText;{active_legend},active',
    ],
    'fields' => [
        'id'     => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'default' => 0]],
        'title'  => [
            'label'     => &$GLOBALS['TL_LANG']['tl_guc_category']['title'],
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['mandatory' => true, 'maxlength' => 128, 'tl_class' => 'w50'],
            'sql'       => ['type' => 'string', 'length' => 128, 'default' => ''],
        ],
        'alias'  => [
            'label'         => &$GLOBALS['TL_LANG']['tl_guc_category']['alias'],
            'exclude'       => true,
            'inputType'     => 'text',
            'eval'          => ['rgxp' => 'alias', 'doNotCopy' => true, 'maxlength' => 128, 'tl_class' => 'w50', 'unique' => true],
            'save_callback' => [
                // Auto-generate alias from title if left empty
                static function (string $value, \Contao\DataContainer $dc): string {
                    if ($value !== '') {
                        return $value;
                    }
                    $title = (string) ($dc->activeRecord->title ?? '');
                    $alias = mb_strtolower($title);
                    $alias = preg_replace('/[^\w]+/u', '-', $alias) ?? '';
                    $alias = trim($alias, '-');
                    return $alias !== '' ? $alias : 'category-' . $dc->id;
                },
            ],
            'sql'           => ['type' => 'string', 'length' => 128, 'default' => ''],
        ],
        'color'  => [
            'label'     => &$GLOBALS['TL_LANG']['tl_guc_category']['color'],
            'exclude'   => true,
            'inputType' => 'text',
            'eval'      => ['maxlength' => 7, 'colorpicker' => true, 'isHexColor' => true, 'decodeEntities' => true, 'tl_class' => 'w50 wizard'],
            'sql'       => ['type' => 'string', 'length' => 7, 'default' => ''],
        ],
        'lightText' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_guc_category']['lightText'],
            'exclude'   => true,
            'inputType' => 'checkbox',
            'eval'      => ['tl_class' => 'w50 m12'],
            'sql'       => ['type' => 'string', 'length' => 1, 'fixed' => true, 'default' => ''],
        ],
        'active' => [
            'label'     => &$GLOBALS['TL_LANG']['tl_guc_category']['active'],
            'exclude'   => true,
            'inputType' => 'checkbox',
            'eval'      => ['doNotCopy' => true, 'tl_class' => 'w50 m12'],
            'sql'       => ['type' => 'string', 'length' => 1, 'fixed' => true, 'default' => ''],
        ],
    ],
];
