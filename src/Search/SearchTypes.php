<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Search;

/**
 * Immutable view of all searchable types: the six fixed ones plus the active
 * categories from tl_guc_category, together with their display metadata.
 */
final class SearchTypes
{
    public const FIXED = ['page', 'file', 'news', 'event', 'member', 'faq'];

    private const FIXED_LABELS = [
        'page'   => 'Seiten',
        'file'   => 'Dateien',
        'news'   => 'News',
        'event'  => 'Events',
        'member' => 'Team',
        'faq'    => 'FAQ',
    ];

    /**
     * @param string[]              $categoryAliases
     * @param array<string, string> $categoryLabels
     * @param array<string, string> $colors    alias => '#rrggbb'
     * @param array<string, bool>   $lightText alias => true
     */
    public function __construct(
        private readonly array $categoryAliases = [],
        private readonly array $categoryLabels = [],
        private readonly array $colors = [],
        private readonly array $lightText = [],
    ) {}

    /** Every type that may appear in a `type` parameter. */
    public function allowed(): array
    {
        return array_merge(self::FIXED, $this->categoryAliases);
    }

    public function isAllowed(string $type): bool
    {
        return \in_array($type, $this->allowed(), true);
    }

    /**
     * Display order for grouped output: categories first (sorted by title),
     * then the fixed types. Optionally narrowed to the module's enabled types.
     *
     * @param string[] $enabledTypes
     */
    public function ordered(array $enabledTypes = []): array
    {
        $all = array_merge($this->categoryAliases, self::FIXED);

        return empty($enabledTypes)
            ? $all
            : array_values(array_intersect($all, $enabledTypes));
    }

    public function label(string $type): string
    {
        return $this->categoryLabels[$type] ?? self::FIXED_LABELS[$type] ?? $type;
    }

    public function color(string $type): ?string
    {
        return $this->colors[$type] ?? null;
    }

    public function isLightText(string $type): bool
    {
        return $this->lightText[$type] ?? false;
    }

    /**
     * Filters a comma-separated `types` parameter down to known types.
     *
     * @return string[]
     */
    public function filterList(string $csv): array
    {
        if ($csv === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $csv) as $type) {
            $type = trim($type);
            if ($this->isAllowed($type)) {
                $out[] = $type;
            }
        }

        return $out;
    }
}
