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

        // Strip FTS5 special chars from each word so parenthesis grouping stays intact.
        // Leading/trailing hyphens are dropped too: '-' is FTS5's NOT operator, and a
        // token like '***-***' would otherwise reduce to a bare '-' and raise a syntax
        // error when the expanded query is executed.
        $rawWords = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY);
        $words = array_values(array_filter(array_map(
            static fn(string $w) => trim(preg_replace(
                ['/[^\p{L}\p{N}\-]/u', '/^-+|-+$/u'],
                '',
                $w
            )),
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
                $expandedParts[]  = $this->quote($word);
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
                $expandedParts[]  = '(' . implode(' OR ', array_map($this->quote(...), $candidates)) . ')';
                $correctedWords[] = $bestWord;
            } else {
                $expandedParts[]  = $this->quote($word);
                $correctedWords[] = $word;
            }
        }

        if (!$anyExpanded) {
            return null;
        }

        return [
            // Explicit AND: FTS5's implicit AND does not span a parenthesised group,
            // so "wort (a OR b)" is a syntax error while "wort AND (a OR b)" is not.
            'ftsQuery'   => implode(' AND ', $expandedParts),
            'suggestion' => implode(' ', $correctedWords),
        ];
    }

    /**
     * Wraps a term as an FTS5 string literal. Dictionary words are letters only, but
     * the user's own words reach the query unchanged and may be hyphenated or spell
     * a bare operator (AND/OR/NOT/NEAR) — both are syntax errors when unquoted.
     */
    private function quote(string $term): string
    {
        return '"' . str_replace('"', '""', $term) . '"';
    }
}
