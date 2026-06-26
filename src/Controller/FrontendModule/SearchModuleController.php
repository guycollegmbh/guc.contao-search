<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Controller\FrontendModule;

use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsFrontendModule;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\ModuleModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AsFrontendModule(type: 'guc_search', category: 'search', template: 'frontend_module/guc_search')]
class SearchModuleController extends AbstractFrontendModuleController
{
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
        $template->set('resultsPageUrl', $resultsPageUrl);

        return $template->getResponse();
    }
}
