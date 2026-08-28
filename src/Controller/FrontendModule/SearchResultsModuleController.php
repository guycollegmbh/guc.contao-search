<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Controller\FrontendModule;

use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\ModuleModel;
use Contao\StringUtil;
use Guc\SearchBundle\Repository\SearchRepository;
use Guc\SearchBundle\Search\CategoryProvider;
use Guc\SearchBundle\Search\FuzzyQueryBuilder;
use Guc\SearchBundle\Search\ResultFormatter;
use Guc\SearchBundle\Search\SearchTypes;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side results page for the FTS index.
 *
 * Reads `keywords` and `type` from the query string — the same parameters the
 * live widget writes when linking here — and renders the matching results
 * without JavaScript.
 *
 * Two modes:
 *  - no `type`   → grouped overview, `perPage` hits per group, each with a link
 *                  to its filtered view
 *  - with `type` → flat, paginated list of that single type
 */
#[AsFrontendModule(type: 'guc_search_results', category: 'search', template: 'frontend_module/guc_search_results')]
class SearchResultsModuleController extends AbstractFrontendModuleController
{
    public function __construct(
        private readonly SearchRepository $searchRepository,
        private readonly CategoryProvider $categoryProvider,
        private readonly FuzzyQueryBuilder $fuzzyQueryBuilder,
        private readonly ResultFormatter $formatter,
    ) {}

    protected function getResponse(FragmentTemplate $template, ModuleModel $model, Request $request): Response
    {
        $GLOBALS['TL_CSS'][] = 'bundles/gucsearch/search.css|static';

        $pageModel = $request->attributes->get('pageModel');
        $language  = $pageModel?->language ?? '';

        $perPage   = max(1, (int) ($model->guc_search_perPage ?: 10));
        $pageParam = 'page_s' . $model->id;

        $query = trim((string) $request->query->get('keywords', ''));

        $template->set('query', $query);
        $template->set('searchAction', $request->getPathInfo());
        $template->set('pageParam', $pageParam);
        $template->set('fuzzy', null);

        // Nothing searched yet, or an implausibly long query — render the bare form
        if ($query === '' || mb_strlen($query) > 200) {
            $template->set('mode', 'empty');

            return $template->getResponse();
        }

        $types        = $this->categoryProvider->load();
        $enabledTypes = $this->configuredTypes($model, $types);

        $type = (string) $request->query->get('type', '');
        if ($type !== '' && (!$types->isAllowed($type) || (!empty($enabledTypes) && !\in_array($type, $enabledTypes, true)))) {
            $type = '';
        }

        $template->set('activeType', $type);
        $template->set('activeLabel', $type !== '' ? $types->label($type) : '');
        $template->set('resetUrl', $this->buildUrl($request, $query, '', 1, $pageParam));

        if ($type !== '') {
            $this->renderFiltered($template, $request, $types, $query, $type, $language, $perPage, $pageParam);
        } else {
            $this->renderGrouped($template, $request, $types, $query, $language, $perPage, $enabledTypes, $pageParam);
        }

        return $template->getResponse();
    }

    /** Flat, paginated list for a single type. */
    private function renderFiltered(
        FragmentTemplate $template,
        Request $request,
        SearchTypes $types,
        string $query,
        string $type,
        string $language,
        int $perPage,
        string $pageParam,
    ): void {
        $page   = max(1, (int) $request->query->get($pageParam, 1));
        $offset = ($page - 1) * $perPage;

        // A malformed FTS5 query must degrade to "no results", not to a 500 page.
        try {
            $results = $this->searchRepository->searchByType($query, $type, $language, $perPage, $offset);
            $total   = $this->searchRepository->countByType($query, $type, $language);
        } catch (\Throwable) {
            $results = [];
            $total   = 0;
        }

        if ($total === 0) {
            $fuzzy = $this->fuzzyQueryBuilder->build($query);
            if ($fuzzy !== null) {
                try {
                    $results = $this->searchRepository->searchByTypeFts($fuzzy['ftsQuery'], $type, $language, $perPage, $offset);
                    $total   = $this->searchRepository->countByTypeFts($fuzzy['ftsQuery'], $type, $language);

                    if ($total > 0) {
                        $template->set('fuzzy', $fuzzy['suggestion']);
                    }
                } catch (\Throwable) {
                    $results = [];
                    $total   = 0;
                }
            }
        }

        $template->set('mode', 'filtered');
        $template->set('results', $this->formatter->formatAll($results));
        $template->set('total', $total);
        $template->set('color', $types->color($type));
        $template->set('lightText', $types->isLightText($type));
        $template->set('pagination', $this->buildPagination($request, $query, $type, $page, $total, $perPage, $pageParam));
    }

    /** Grouped overview across all enabled types. */
    private function renderGrouped(
        FragmentTemplate $template,
        Request $request,
        SearchTypes $types,
        string $query,
        string $language,
        int $perPage,
        array $enabledTypes,
        string $pageParam,
    ): void {
        $activeTypes = $types->ordered($enabledTypes);

        try {
            $grouped = $this->searchRepository->searchGrouped($query, $language, $perPage, $activeTypes);
            $counts  = $this->searchRepository->countGrouped($query, $language, $activeTypes);
        } catch (\Throwable) {
            $template->set('mode', 'grouped');
            $template->set('groups', []);
            $template->set('total', 0);

            return;
        }

        if (empty($grouped)) {
            $fuzzy = $this->fuzzyQueryBuilder->build($query);
            if ($fuzzy !== null) {
                try {
                    $grouped = $this->searchRepository->searchGroupedFts($fuzzy['ftsQuery'], $language, $perPage, $activeTypes);
                    $counts  = $this->searchRepository->countGroupedFts($fuzzy['ftsQuery'], $language, $activeTypes);

                    if (!empty($grouped)) {
                        $template->set('fuzzy', $fuzzy['suggestion']);
                    }
                } catch (\Throwable) {
                    $grouped = [];
                    $counts  = [];
                }
            }
        }

        $groups = [];
        $total  = 0;

        foreach ($grouped as $groupType => $results) {
            $groupTotal = $counts[$groupType] ?? count($results);
            $total     += $groupTotal;

            $groups[] = [
                'type'      => $groupType,
                'label'     => $types->label($groupType),
                'results'   => $this->formatter->formatAll($results),
                'total'     => $groupTotal,
                'hasMore'   => $groupTotal > $perPage,
                'color'     => $types->color($groupType),
                'lightText' => $types->isLightText($groupType),
                'url'       => $this->buildUrl($request, $query, $groupType, 1, $pageParam),
            ];
        }

        $template->set('mode', 'grouped');
        $template->set('groups', $groups);
        $template->set('total', $total);
    }

    /**
     * @return array{pages: int, current: int, items: array<int, array{page: int, url: string, current: bool}>, prev: ?string, next: ?string}|null
     */
    private function buildPagination(
        Request $request,
        string $query,
        string $type,
        int $current,
        int $total,
        int $perPage,
        string $pageParam,
    ): ?array {
        $pages = (int) ceil($total / $perPage);
        if ($pages < 2) {
            return null;
        }

        $items = [];
        for ($i = 1; $i <= $pages; ++$i) {
            $items[] = [
                'page'    => $i,
                'url'     => $this->buildUrl($request, $query, $type, $i, $pageParam),
                'current' => $i === $current,
            ];
        }

        return [
            'pages'   => $pages,
            'current' => $current,
            'items'   => $items,
            'prev'    => $current > 1 ? $this->buildUrl($request, $query, $type, $current - 1, $pageParam) : null,
            'next'    => $current < $pages ? $this->buildUrl($request, $query, $type, $current + 1, $pageParam) : null,
        ];
    }

    private function buildUrl(Request $request, string $query, string $type, int $page, string $pageParam): string
    {
        $params = ['keywords' => $query];

        if ($type !== '') {
            $params['type'] = $type;
        }

        if ($page > 1) {
            $params[$pageParam] = $page;
        }

        return $request->getPathInfo() . '?' . http_build_query($params);
    }

    /**
     * Resolves the module's type selection, expanding the `_categories`
     * placeholder to all active category aliases.
     *
     * @return string[]
     */
    private function configuredTypes(ModuleModel $model, SearchTypes $types): array
    {
        $configured = StringUtil::deserialize($model->guc_search_types, true);

        if (\in_array('_categories', $configured, true)) {
            $configured = array_filter($configured, static fn(string $t) => $t !== '_categories');
            $configured = array_merge($configured, array_diff($types->allowed(), SearchTypes::FIXED));
        }

        return $types->filterList(implode(',', array_unique($configured)));
    }
}
