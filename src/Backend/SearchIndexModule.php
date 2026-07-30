<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Backend;

use Guc\SearchBundle\Indexer\IndexerInterface;
use Guc\SearchBundle\Repository\SearchRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Contao backend module callback — registered via BE_MOD['erweiterte_suche']['guc_search_index'].
 * Contao instantiates this via System::importStatic() (DI-aware in Contao 5) and calls generate().
 * The returned HTML is embedded by BackendController into the full backend layout.
 */
class SearchIndexModule
{
    /** @param IndexerInterface[] $indexers */
    public function __construct(
        private readonly SearchRepository $searchRepository,
        private readonly iterable $indexers,
        private readonly Environment $twig,
        private readonly RequestStack $requestStack,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {}

    public function generate(): string
    {
        $indexerTypes = [];
        foreach ($this->indexers as $indexer) {
            $indexerTypes[] = $indexer->getType();
        }
        $allowedTypes = array_merge(['all'], $indexerTypes);
        $message = null;

        $request = $this->requestStack->getCurrentRequest();
        if ($request?->isMethod('POST')) {
            $token = new CsrfToken('guc_search_reindex', (string) $request->request->get('_token', ''));
            if ($this->csrfTokenManager->isTokenValid($token)) {
                $reindexType = (string) $request->request->get('reindex', '');
                if (\in_array($reindexType, $allowedTypes, true)) {
                    $errors = [];
                    foreach ($this->indexers as $indexer) {
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

        $stats = $this->searchRepository->getStats();
        $dbPath = $this->searchRepository->getDbPath();
        $dbSize = file_exists($dbPath) ? round(filesize($dbPath) / 1024, 1) : 0;

        $lastIndexed = [];
        foreach ($indexerTypes as $type) {
            $lastIndexed[$type] = $this->searchRepository->getMeta('last_index_' . $type);
        }

        return $this->twig->render('@GucSearch/backend/search_index.html.twig', [
            'stats'        => $stats,
            'indexerTypes' => $indexerTypes,
            'lastIndexed'  => $lastIndexed,
            'dbSize'       => $dbSize,
            'message'      => $message,
        ]);
    }
}
