<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Backend;

use Contao\System;
use Guc\SearchBundle\Indexer\IndexerRegistry;
use Guc\SearchBundle\Repository\SearchRepository;

/**
 * Contao backend module callback — registered via BE_MOD['erweiterte_suche']['guc_search_index'].
 * Read-only status page; re-indexing happens automatically via SearchIndexListener and cron.
 */
class SearchIndexModule
{
    public function generate(): string
    {
        $container = System::getContainer();

        /** @var SearchRepository $repo */
        $repo = $container->get(SearchRepository::class);

        /** @var IndexerRegistry $indexers */
        $indexers = $container->get(IndexerRegistry::class);

        $twig = $container->get('twig');

        $indexerTypes = [];
        foreach ($indexers as $indexer) {
            $indexerTypes[] = $indexer->getType();
        }

        $stats   = $repo->getStats();
        $dbPath  = $repo->getDbPath();
        $dbSize  = file_exists($dbPath) ? round(filesize($dbPath) / 1024, 1) : 0;

        $lastIndexed = [];
        foreach ($indexerTypes as $type) {
            $lastIndexed[$type] = $repo->getMeta('last_index_' . $type);
        }

        return $twig->render('@GucSearch/backend/search_index.html.twig', [
            'stats'        => $stats,
            'indexerTypes' => $indexerTypes,
            'lastIndexed'  => $lastIndexed,
            'dbSize'       => $dbSize,
        ]);
    }
}
