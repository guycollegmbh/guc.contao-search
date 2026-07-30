<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Backend;

use Contao\System;
use Guc\SearchBundle\Indexer\IndexerRegistry;
use Guc\SearchBundle\Repository\SearchRepository;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Contao backend module callback — registered via BE_MOD['erweiterte_suche']['guc_search_index'].
 *
 * Contao calls generate() either via System::importStatic() (DI-aware) or via new() fallback.
 * To be safe in both cases, dependencies are pulled from the container inside generate()
 * instead of via constructor injection.
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

        /** @var Environment $twig */
        $twig = $container->get('twig');

        /** @var CsrfTokenManagerInterface $csrf */
        $csrf = $container->get('security.csrf.token_manager');

        $request = $container->get('request_stack')->getCurrentRequest();

        $indexerTypes = [];
        foreach ($indexers as $indexer) {
            $indexerTypes[] = $indexer->getType();
        }
        $allowedTypes = array_merge(['all'], $indexerTypes);
        $message = null;

        if ($request?->isMethod('POST')) {
            $token = new CsrfToken('guc_search_reindex', (string) $request->request->get('_token', ''));
            if ($csrf->isTokenValid($token)) {
                $reindexType = (string) $request->request->get('reindex', '');
                if (\in_array($reindexType, $allowedTypes, true)) {
                    $errors = [];
                    foreach ($indexers as $indexer) {
                        if ($reindexType === 'all' || $indexer->getType() === $reindexType) {
                            try {
                                $indexer->index();
                            } catch (\Throwable $e) {
                                $errors[] = sprintf('"%s": %s', $indexer->getType(), $e->getMessage());
                            }
                        }
                    }
                    $message = !empty($errors)
                        ? 'Fehler beim Indexieren: ' . implode('; ', $errors)
                        : ($reindexType === 'all'
                            ? 'Gesamter Index wurde neu aufgebaut.'
                            : sprintf('Index für "%s" wurde neu aufgebaut.', $reindexType));
                }
            }
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
            'message'      => $message,
        ]);
    }
}
