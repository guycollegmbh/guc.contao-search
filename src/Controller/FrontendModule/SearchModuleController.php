<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Controller\FrontendModule;

use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\FilesModel;
use Contao\ModuleModel;
use Contao\System;
use Guc\SearchBundle\Search\CategoryProvider;
use Guc\SearchBundle\Search\SearchTypes;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AsFrontendModule(type: 'guc_search', category: 'search', template: 'frontend_module/guc_search')]
class SearchModuleController extends AbstractFrontendModuleController
{
    public function __construct(private readonly CategoryProvider $categoryProvider) {}

    protected function getResponse(FragmentTemplate $template, ModuleModel $model, Request $request): Response
    {
        $page = $request->attributes->get('pageModel');
        $language = $page?->language ?? '';

        $minChars = max(1, (int) ($model->guc_search_min_chars ?: 2));

        $resultsPageUrl = '';
        if ($model->guc_search_resultsPage) {
            $resultsPage = \Contao\PageModel::findById((int) $model->guc_search_resultsPage);
            if ($resultsPage !== null) {
                $resultsPageUrl = $resultsPage->getFrontendUrl();
            }
        }

        $GLOBALS['TL_CSS'][]        = 'bundles/gucsearch/search.css|static';
        $GLOBALS['TL_JAVASCRIPT'][] = 'bundles/gucsearch/search.js';

        $template->set('language', $language);
        $template->set('apiUrl', '/api/search');
        $template->set('minChars', $minChars);
        $template->set('debounce', 400);
        $template->set('placeholder', $GLOBALS['TL_LANG']['MSC']['guc_search_placeholder'] ?? 'Suchen…');
        $template->set('openLabel', $GLOBALS['TL_LANG']['MSC']['guc_search_open'] ?? 'Suche öffnen');
        $template->set('closeLabel', $GLOBALS['TL_LANG']['MSC']['guc_search_close'] ?? 'Suche schliessen');

        $layout = 'overlay' === $model->guc_search_layout ? 'overlay' : 'inline';
        $template->set('layout', $layout);
        $template->set('toggleIcon', 'overlay' === $layout ? $this->resolveToggleIcon($model) : '');

        $rawTypes = \Contao\StringUtil::deserialize($model->guc_search_types, true);

        // Expand the '_categories' placeholder to all active category aliases from tl_guc_category
        if (\in_array('_categories', $rawTypes, true)) {
            $types    = $this->categoryProvider->load();
            $rawTypes = array_filter($rawTypes, static fn(string $t) => $t !== '_categories');
            $rawTypes = array_merge($rawTypes, array_diff($types->allowed(), SearchTypes::FIXED));
            $rawTypes = array_values(array_unique($rawTypes));
        }

        $template->set('resultsPageUrl', $resultsPageUrl);
        $template->set('searchTypes', implode(',', $rawTypes));

        return $template->getResponse();
    }

    /**
     * Resolves the configured magnifier image to a root-relative path.
     *
     * Returns '' when nothing is configured or the file is gone — the template
     * then falls back to its inline SVG.
     */
    private function resolveToggleIcon(ModuleModel $model): string
    {
        if (!$model->guc_search_toggleIcon) {
            return '';
        }

        $file = FilesModel::findByUuid($model->guc_search_toggleIcon);

        if (null === $file || !is_file(System::getContainer()->getParameter('kernel.project_dir').'/'.$file->path)) {
            return '';
        }

        return '/'.ltrim($file->path, '/');
    }
}
