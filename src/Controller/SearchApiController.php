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
        $allowedTypes = ['page', 'file', 'news', 'event', 'member', 'faq', 'custom'];
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
                'results' => array_map($this->formatResult(...), $results),
                'total'   => $total,
                'page'    => $page,
                'pages'   => (int) ceil($total / $perPage),
                'query'   => $query,
            ]);
        }

        try {
            $grouped = $this->searchRepository->searchGrouped($query, $language, $perPage);
        } catch (\Throwable $e) {
            return $this->json(['grouped' => [], 'query' => $query, 'error' => 'search_failed']);
        }

        $badgeLabels = [
            'page'   => 'Seite',
            'file'   => 'Datei',
            'news'   => 'News',
            'event'  => 'Event',
            'member' => 'Team',
            'faq'    => 'FAQ',
            'custom' => 'Inhalt',
        ];

        $response = ['grouped' => [], 'query' => $query];

        foreach ($grouped as $type => $results) {
            try {
                $total = $this->searchRepository->countByType($query, $type, $language);
            } catch (\Throwable) {
                $total = count($results);
            }
            $response['grouped'][] = [
                'type'    => $type,
                'label'   => $badgeLabels[$type] ?? $type,
                'results' => array_map($this->formatResult(...), $results),
                'total'   => $total,
                'hasMore' => $total > $perPage,
            ];
        }

        $jsonResponse = $this->json($response);
        $jsonResponse->setPrivate()->setMaxAge(30);

        return $jsonResponse;
    }

    private function formatResult(array $row): array
    {
        return [
            'id'             => $row['id'],
            'type'           => $row['type'],
            'title'          => $row['title'],
            'titleHighlight' => strip_tags($row['titleHighlight'] ?? '', '<mark>'),
            'url'            => $row['url'],
            'badge'          => $row['badge'],
            'excerpt'        => strip_tags($row['excerpt'] ?? '', '<mark>'),
        ];
    }
}
