<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Controller;

use Guc\SearchBundle\Repository\SearchRepository;
use Guc\SearchBundle\Search\CategoryProvider;
use Guc\SearchBundle\Search\FuzzyQueryBuilder;
use Guc\SearchBundle\Search\ResultFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/search', name: 'guc_search_api', methods: ['GET'])]
class SearchApiController extends AbstractController
{
    public function __construct(
        private readonly SearchRepository $searchRepository,
        private readonly CategoryProvider $categoryProvider,
        private readonly FuzzyQueryBuilder $fuzzyQueryBuilder,
        private readonly ResultFormatter $formatter,
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

        $types = $this->categoryProvider->load();

        // Validate type parameter against dynamic whitelist
        if ($type !== '' && !$types->isAllowed($type)) {
            $type = '';
        }

        // Optional type filter from module config (comma-separated)
        $enabledTypes = $types->filterList($request->query->get('types', ''));

        if ($language !== '' && !preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $language)) {
            $language = '';
        }

        if ($type !== '') {
            // Block single-type requests for types disabled by module config
            if (!empty($enabledTypes) && !\in_array($type, $enabledTypes, true)) {
                return $this->json(['results' => [], 'total' => 0, 'page' => 1, 'pages' => 0, 'query' => $query]);
            }
            // A malformed FTS5 query must degrade to "no results", not to a 500.
            try {
                $results = $this->searchRepository->searchByType($query, $type, $language, $perPage, $offset);
                $total   = $this->searchRepository->countByType($query, $type, $language);
            } catch (\Throwable) {
                return $this->json(['results' => [], 'total' => 0, 'page' => 1, 'pages' => 0, 'query' => $query, 'error' => 'search_failed']);
            }

            // Fuzzy fallback: if no results, try Levenshtein-expanded query
            $fuzzy = null;
            if ($total === 0) {
                $fuzzy = $this->fuzzyQueryBuilder->build($query);
                if ($fuzzy !== null) {
                    try {
                        $results = $this->searchRepository->searchByTypeFts($fuzzy['ftsQuery'], $type, $language, $perPage, $offset);
                        $total   = $this->searchRepository->countByTypeFts($fuzzy['ftsQuery'], $type, $language);
                    } catch (\Throwable) {
                        $results = [];
                        $total   = 0;
                        $fuzzy   = null;
                    }
                }
            }

            $response = [
                'results' => $this->formatter->formatAll($results),
                'total'   => $total,
                'page'    => $page,
                'pages'   => (int) ceil($total / $perPage),
                'query'   => $query,
            ];
            if ($fuzzy !== null && $total > 0) {
                $response['fuzzy']      = true;
                $response['suggestion'] = $fuzzy['suggestion'];
            }

            return $this->json($response);
        }

        // Grouped search: categories first (sorted by title), then fixed types
        $activeTypes = $types->ordered($enabledTypes);

        try {
            $grouped = $this->searchRepository->searchGrouped($query, $language, $perPage, $activeTypes);
            $counts  = $this->searchRepository->countGrouped($query, $language, $activeTypes);
        } catch (\Throwable) {
            return $this->json(['grouped' => [], 'query' => $query, 'error' => 'search_failed']);
        }

        // Fuzzy fallback: if no results across all groups, try Levenshtein-expanded query
        $fuzzy = null;
        if (empty($grouped)) {
            $fuzzy = $this->fuzzyQueryBuilder->build($query);
            if ($fuzzy !== null) {
                try {
                    $grouped = $this->searchRepository->searchGroupedFts($fuzzy['ftsQuery'], $language, $perPage, $activeTypes);
                    $counts  = $this->searchRepository->countGroupedFts($fuzzy['ftsQuery'], $language, $activeTypes);
                } catch (\Throwable) {
                    $grouped = [];
                    $counts  = [];
                    $fuzzy   = null;
                }
            }
        }

        $response = ['grouped' => [], 'query' => $query];
        if ($fuzzy !== null && !empty($grouped)) {
            $response['fuzzy']      = true;
            $response['suggestion'] = $fuzzy['suggestion'];
        }

        foreach ($grouped as $groupType => $results) {
            $total = $counts[$groupType] ?? count($results);
            $entry = [
                'type'    => $groupType,
                'label'   => $types->label($groupType),
                'results' => $this->formatter->formatAll($results),
                'total'   => $total,
                'hasMore' => $total > $perPage,
            ];
            if (null !== $color = $types->color($groupType)) {
                $entry['color'] = $color;
            }
            if ($types->isLightText($groupType)) {
                $entry['lightText'] = true;
            }
            $response['grouped'][] = $entry;
        }

        $jsonResponse = $this->json($response);
        $jsonResponse->setPrivate()->setMaxAge(30);

        return $jsonResponse;
    }
}
