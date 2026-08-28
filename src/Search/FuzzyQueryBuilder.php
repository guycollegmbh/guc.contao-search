<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Search;

use Guc\SearchBundle\Repository\SearchRepository;

/**
 * Builds a fuzzy FTS5 query by expanding each query word with Levenshtein-close
 * words from the search_words dictionary. Only triggers when the dictionary is
 * populated.
 *
 * Max edit distance: 1 for words 4–6 chars, 2 for 7+ chars.
 * Words shorter than 4 chars are passed through unchanged (too many false positives).
 */
class FuzzyQueryBuilder
{
    public function __construct(private readonly SearchRepository $searchRepository) {}

    /**
     * @return array{ftsQuery: string, suggestion: string}|null
     *         null if no expansion is possible (empty dictionary or no close matches)
     */
    public function build(string $query): ?array
    {
        $allWords = $this->searchRepository->getAllWords();
        if (empty($allWords)) {
            return null;
        }

        // Strip FTS5 special chars from each word so parenthesis grouping stays intact
        $rawWords = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_values(array_filter(array_map(
            static fn(string $w) => trim(preg_replace('/[^\p{L}\p{N}\-]/u', '', $w)),
            $rawWords
        ), static fn(string $w) => $w !== ''));

        $expandedParts  = [];
        $correctedWords = [];
        $anyExpanded    = false;

        foreach ($words as $word) {
            $lower   = mb_strtolower($word);
            $len     = mb_strlen($lower);
            $maxDist = $len <= 6 ? 1 : 2;

            if ($len < 4) {
                $expandedParts[]  = $word;
                $correctedWords[] = $word;
                continue;
            }

            $candidates = [$word];
            $bestWord   = $word;
            $bestDist   = PHP_INT_MAX;

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
}
