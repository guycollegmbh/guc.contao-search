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
        $categoryAliases   = [];
        $categoryLabels    = [];
        $categoryColors    = [];
        $categoryLightText = [];
        try {
            $categoryRows = $this->db->fetchAllAssociative(
                "SELECT alias, title, color, lightText FROM tl_guc_category WHERE active = '1' ORDER BY title"
            );
            foreach ($categoryRows as $row) {
                $categoryAliases[]             = $row['alias'];
                $categoryLabels[$row['alias']] = $row['title'];
                $color = ltrim((string) $row['color'], '#');
                if ($color !== '') {
                    $categoryColors[$row['alias']] = '#' . $color;
                }
                if ($row['lightText'] === '1') {
                    $categoryLightText[$row['alias']] = true;
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
            // Block single-type requests for types disabled by module config
            if (!empty($enabledTypes) && !\in_array($type, $enabledTypes, true)) {
                return $this->json(['results' => [], 'total' => 0, 'page' => 1, 'pages' => 0, 'query' => $query]);
            }
            $results = $this->searchRepository->searchByType($query, $type, $language, $perPage, $offset);
            $total   = $this->searchRepository->countByType($query, $type, $language);

            // Fuzzy fallback: if no results, try Levenshtein-expanded query
            $fuzzy = null;
            if ($total === 0) {
                $fuzzy = $this->buildFuzzyFtsQuery($query);
                if ($fuzzy !== null) {
                    $results = $this->searchRepository->searchByTypeFts($fuzzy['ftsQuery'], $type, $language, $perPage, $offset);
                    $total   = $this->searchRepository->countByTypeFts($fuzzy['ftsQuery'], $type, $language);
                }
            }

            $response = [
                'results' => array_map($this->formatResult(...), $results),
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

        // Build the full type list for grouped search:
        // categories first (sorted by title), then fixed types — filtered by module config if set
        $systemTypes = array_merge($categoryAliases, $fixedTypes);
        $activeTypes = empty($enabledTypes)
            ? $systemTypes
            : array_values(array_intersect($systemTypes, $enabledTypes));

        try {
            $grouped = $this->searchRepository->searchGrouped($query, $language, $perPage, $activeTypes);
            $counts  = $this->searchRepository->countGrouped($query, $language, $activeTypes);
        } catch (\Throwable $e) {
            return $this->json(['grouped' => [], 'query' => $query, 'error' => 'search_failed']);
        }

        // Fuzzy fallback: if no results across all groups, try Levenshtein-expanded query
        $fuzzy = null;
        if (empty($grouped)) {
            $fuzzy = $this->buildFuzzyFtsQuery($query);
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
                'label'   => $badgeLabels[$groupType] ?? $groupType,
                'results' => array_map($this->formatResult(...), $results),
                'total'   => $total,
                'hasMore' => $total > $perPage,
            ];
            if (isset($categoryColors[$groupType])) {
                $entry['color'] = $categoryColors[$groupType];
            }
            if ($categoryLightText[$groupType] ?? false) {
                $entry['lightText'] = true;
            }
            $response['grouped'][] = $entry;
        }

        $jsonResponse = $this->json($response);
        $jsonResponse->setPrivate()->setMaxAge(30);

        return $jsonResponse;
    }

    /**
     * Builds a fuzzy FTS5 query by expanding each query word with Levenshtein-close
     * dictionary words. Only triggers when the word dictionary is populated.
     *
     * Returns null if no expansion is possible (empty dictionary or no close matches).
     * Returns ['ftsQuery' => string, 'suggestion' => string] on success.
     *
     * Max edit distance: 1 for words 4–6 chars, 2 for 7+ chars.
     * Words shorter than 4 chars are passed through unchanged (too many false positives).
     */
    private function buildFuzzyFtsQuery(string $query): ?array
    {
        $allWords = $this->searchRepository->getAllWords();
        if (empty($allWords)) {
            return null;
        }

        $words = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY);
        $expandedParts = [];
        $correctedWords = [];
        $anyExpanded = false;

        foreach ($words as $word) {
            $lower   = mb_strtolower($word);
            $len     = mb_strlen($lower);
            $maxDist = $len <= 6 ? 1 : 2;

            if ($len < 4) {
                $expandedParts[]  = $word;
                $correctedWords[] = $word;
                continue;
            }

            $candidates  = [$word];
            $bestWord    = $word;
            $bestDist    = PHP_INT_MAX;

            foreach ($allWords as $dictWord) {
                $dist = levenshtein($lower, $dictWord);
                if ($dist > 0 && $dist <= $maxDist) {
                    $candidates[] = $dictWord;
                    if ($dist < $bestDist) {
                        $bestDist = $dist;
                        $bestWord = $dictWord;
                    }
                }
            }

            if (count($candidates) > 1) {
                $anyExpanded      = true;
                $expandedParts[]  = '(' . implode(' OR ', $candidates) . ')';
                $correctedWords[] = $bestWord;
            } else {
                $expandedParts[]  = $word;
                $correctedWords[] = $word;
            }
        }

        if (!$anyExpanded) {
            return null;
        }

        return [
            'ftsQuery'   => implode(' ', $expandedParts),
            'suggestion' => implode(' ', $correctedWords),
        ];
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
