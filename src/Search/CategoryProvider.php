<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Search;

use Doctrine\DBAL\Connection;

/**
 * Loads the active manual categories from tl_guc_category and turns them into
 * a SearchTypes instance. Shared by the JSON API and the results module so both
 * agree on which types exist and how they are labelled.
 */
class CategoryProvider
{
    public function __construct(private readonly Connection $db) {}

    public function load(): SearchTypes
    {
        $aliases   = [];
        $labels    = [];
        $colors    = [];
        $lightText = [];

        try {
            $rows = $this->db->fetchAllAssociative(
                "SELECT alias, title, color, lightText FROM tl_guc_category WHERE active = '1' ORDER BY title"
            );
        } catch (\Throwable) {
            // tl_guc_category not yet migrated — proceed without custom categories
            return new SearchTypes();
        }

        foreach ($rows as $row) {
            $alias            = $row['alias'];
            $aliases[]        = $alias;
            $labels[$alias]   = $row['title'];

            // Contao's colorpicker stores hex without the '#' prefix
            $color = ltrim((string) $row['color'], '#');
            if ($color !== '') {
                $colors[$alias] = '#' . $color;
            }

            if ($row['lightText'] === '1') {
                $lightText[$alias] = true;
            }
        }

        return new SearchTypes($aliases, $labels, $colors, $lightText);
    }
}
