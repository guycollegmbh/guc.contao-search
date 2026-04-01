<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Controller;

use Guc\SearchBundle\Repository\SearchRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/search', name: 'guc_search_api', methods: ['GET'])]
class SearchApiController extends AbstractController
{
    public function __construct(
        private readonly SearchRepository $searchRepository,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $query = trim($request->query->get('q', ''));
        $language = $request->query->get('lang', '');
        $type = $request->query->get('type', '');
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 10;
        $offset = ($page - 1) * $perPage;

        $queryLen = mb_strlen($query);
        if ($queryLen < 1 || $queryLen > 200) {
            return $this->json(['results' => [], 'grouped' => [], 'query' => '']);
        }

        // Whitelist validation
        $allowedTypes = ['page', 'file', 'news', 'event', 'custom'];
        if ($type !== '' && !\in_array($type, $allowedTypes, true)) {
            $type = '';
        }
        if ($language !== '' && !preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $language)) {
            $language = '';
        }

        if ($type !== '') {
            $results = $this->searchRepository->searchByType($query, $type, $language, $perPage, $offset);
            $total = $this->searchRepository->countByType($query, $type, $language);

            return $this->json([
                'results' => $results,
                'total'   => $total,
                'page'    => $page,
                'pages'   => (int) ceil($total / $perPage),
                'query'   => $query,
            ]);
        }

        $grouped = $this->searchRepository->searchGrouped($query, $language, $perPage);

        $response = [
            'grouped' => [],
            'query'   => $query,
        ];

        $badgeLabels = [
            'page'   => 'Seite',
            'file'   => 'Datei',
            'news'   => 'News',
            'event'  => 'Event',
            'custom' => 'Inhalt',
        ];

        foreach ($grouped as $type => $results) {
            $total = $this->searchRepository->countByType($query, $type, $language);
            $response['grouped'][] = [
                'type'    => $type,
                'label'   => $badgeLabels[$type] ?? $type,
                'results' => $results,
                'total'   => $total,
                'hasMore' => $total > $perPage,
            ];
        }

        return $this->json($response);
    }
}
