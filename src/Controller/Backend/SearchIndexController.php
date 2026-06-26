<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Controller\Backend;

use Guc\SearchBundle\Indexer\IndexerInterface;
use Guc\SearchBundle\Repository\SearchRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

#[Route('/contao/guc-search', name: 'guc_search_backend')]
#[IsGranted('ROLE_ADMIN')]
class SearchIndexController extends AbstractController
{
    private const ALLOWED_TYPES = ['all', 'page', 'file', 'news', 'event', 'member', 'faq', 'custom'];

    /** @param IndexerInterface[] $indexers */
    public function __construct(
        private readonly SearchRepository $searchRepository,
        private readonly iterable $indexers,
        private readonly Environment $twig,
    ) {}

    public function __invoke(Request $request): Response
    {
        $message = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('guc_search_reindex', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $reindexType = $request->request->get('reindex', '');
            if (\in_array($reindexType, self::ALLOWED_TYPES, true)) {
                foreach ($this->indexers as $indexer) {
                    if ($reindexType === 'all' || $indexer->getType() === $reindexType) {
                        $indexer->index();
                    }
                }
                $message = $reindexType === 'all'
                    ? 'Gesamter Index wurde neu aufgebaut.'
                    : sprintf('Index für "%s" wurde neu aufgebaut.', $reindexType);
            }
        }

        $stats = $this->searchRepository->getStats();
        $dbPath = $this->searchRepository->getDbPath();
        $dbSize = file_exists($dbPath) ? round(filesize($dbPath) / 1024, 1) : 0;

        $lastIndexed = [];
        foreach (['page', 'file', 'news', 'event', 'member', 'faq', 'custom'] as $type) {
            $lastIndexed[$type] = $this->searchRepository->getMeta('last_index_' . $type);
        }

        return new Response($this->twig->render('@GucSearch/backend/search_index.html.twig', [
            'stats'       => $stats,
            'lastIndexed' => $lastIndexed,
            'dbSize'      => $dbSize,
            'message'     => $message,
        ]));
    }
}
