<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Search;

/**
 * Reduces a raw FTS row to the fields that may leave the server, and sanitizes
 * the snippet markup and URLs. Used by both the JSON API and the results module.
 */
class ResultFormatter
{
    public function format(array $row): array
    {
        return [
            'id'             => $row['id'],
            'type'           => $row['type'],
            // FileIndexer (file meta title) and MemberIndexer (first/lastname) store the
            // title verbatim, so it may contain markup. Strip it here rather than relying
            // on every consumer to escape — search.js uses textContent, but the results
            // template falls back to this value when the highlight snippet is empty.
            'title'          => strip_tags((string) $row['title']),
            'titleHighlight' => $this->sanitizeSnippet($row['titleHighlight'] ?? ''),
            'url'            => $this->sanitizeUrl($row['url'] ?? ''),
            'badge'          => strip_tags((string) $row['badge']),
            'excerpt'        => $this->sanitizeSnippet($row['excerpt'] ?? ''),
        ];
    }

    /** @param array<int, array> $rows */
    public function formatAll(array $rows): array
    {
        return array_map($this->format(...), $rows);
    }

    /** Strips all tags except bare <mark> (no attributes) to prevent event-handler injection via innerHTML. */
    public function sanitizeSnippet(string $html): string
    {
        return preg_replace('/<mark\b[^>]+>/i', '<mark>', strip_tags($html, '<mark>'));
    }

    /** Ensures URLs are root-relative paths; rejects javascript:, data:, and protocol-relative //host URLs. */
    public function sanitizeUrl(string $url): string
    {
        if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return '/';
        }

        return $url;
    }
}
