<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Controller;

use Doctrine\DBAL\Connection;
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
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $query    = trim($request->query->get('q', ''));
        $language = $request->query->get('lang', '');
        $type     = $request->query->get('type', '');
        $page     = max(1, (int) $request->query->get('page', 1));
        $perPage  = 10;
        $offset   = ($page - 1) * $perPage;

        $queryLen = mb_strlen($query);
        if ($queryLen < 1 || $queryLen > 200) {
            return $this->json(['results' => [], 'grouped' => [], 'query' => '']);
        }

        // Load active categories from tl_guc_category for dynamic type resolution
        $categoryAliases = [];
        $categoryLabels  = [];
        $categoryColors  = [];
        try {
            $categoryRows = $this->db->fetchAllAssociative(
                "SELECT alias, title, color FROM tl_guc_category WHERE active = '1' ORDER BY title"
            );
            foreach ($categoryRows as $row) {
                $categoryAliases[]             = $row['alias'];
                $categoryLabels[$row['alias']] = $row['title'];
                if ($row['color'] !== '') {
                    $categoryColors[$row['alias']] = $row['color'];
                }
            }
        } catch (\Throwable) {
            // tl_guc_category not yet migrated — proceed without custom categories
        }

        // Fixed types + active category aliases
        $fixedTypes   = ['page', 'file', 'news', 'event', 'member', 'faq'];
        $allowedTypes = array_merge($fixedTypes, $categoryAliases);

        $badgeLabels = array_merge([
            'page'   => 'Seiten',
            'file'   => 'Dateien',
            'news'   => 'News',
            'event'  => 'Events',
            'member' => 'Team',
            'faq'    => 'FAQ',
        ], $categoryLabels);

        // Validate type parameter against dynamic whitelist
        if ($type !== '' && !\in_array($type, $allowedTypes, true)) {
            $type = '';
        }

        // Optional type filter from module config (comma-separated)
        $typesParam   = $request->query->get('types', '');
        $enabledTypes = [];
        if ($typesParam !== '') {
            foreach (explode(',', $typesParam) as $t) {
                $t = trim($t);
                if (\in_array($t, $allowedTypes, true)) {
                    $enabledTypes[] = $t;
                }
            }
        }

        if ($language !== '' && !preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $language)) {
            $language = '';
        }

        if ($type !== '') {
            // C2: block single-type requests for types disabled by module config
            if (!empty($enabledTypes) && !\in_array($type, $enabledTypes, true)) {
                return $this->json(['results' => [], 'total' => 0, 'page' => 1, 'pages' => 0, 'query' => $query]);
            }
            $results = $this->searchRepository->searchByType($query, $type, $language, $perPage, $offset);
            $total   = $this->searchRepository->countByType($query, $type, $language);

            return $this->json([
                'results' => array_map($this->formatResult(...), $results),
                'total'   => $total,
                'page'    => $page,
                'pages'   => (int) ceil($total / $perPage),
                'query'   => $query,
            ]);
        }

        // Build the full type list for grouped search:
        // categories first (sorted by title), then fixed types — filtered by module config if set
        $systemTypes = array_merge($categoryAliases, $fixedTypes);
        $activeTypes = empty($enabledTypes)
            ? $systemTypes
            : array_values(array_intersect($systemTypes, $enabledTypes));

        try {
            $grouped = $this->searchRepository->searchGrouped($query, $language, $perPage, $activeTypes);
            // C8: single COUNT query instead of one countByType() call per type
            $counts  = $this->searchRepository->countGrouped($query, $language, $activeTypes);
        } catch (\Throwable $e) {
            return $this->json(['grouped' => [], 'query' => $query, 'error' => 'search_failed']);
        }

        $response = ['grouped' => [], 'query' => $query];

        foreach ($grouped as $groupType => $results) {
            $total = $counts[$groupType] ?? count($results);
            $entry = [
                'type'    => $groupType,
                'label'   => $badgeLabels[$groupType] ?? $groupType,
                'results' => array_map($this->formatResult(...), $results),
                'total'   => $total,
                'hasMore' => $total > $perPage,
            ];
            if (isset($categoryColors[$groupType])) {
                $entry['color'] = $categoryColors[$groupType];
            }
            $response['grouped'][] = $entry;
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
            'titleHighlight' => $this->sanitizeSnippet($row['titleHighlight'] ?? ''),
            'url'            => $this->sanitizeUrl($row['url'] ?? ''),
            'badge'          => $row['badge'],
            'excerpt'        => $this->sanitizeSnippet($row['excerpt'] ?? ''),
        ];
    }

    /** Strips all tags except bare <mark> (no attributes) to prevent event-handler injection via innerHTML. */
    private function sanitizeSnippet(string $html): string
    {
        return preg_replace('/<mark\b[^>]+>/i', '<mark>', strip_tags($html, '<mark>'));
    }

    /** Ensures URLs are relative paths; rejects javascript: and other non-path schemes. */
    private function sanitizeUrl(string $url): string
    {
        return str_starts_with($url, '/') ? $url : '/';
    }
}
