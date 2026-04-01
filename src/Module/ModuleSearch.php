<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Module;

use Contao\Module;
use Contao\System;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ModuleSearch extends Module
{
    protected $strTemplate = 'mod_guc_search';

    public function generate(): string
    {
        if (System::getContainer()->get('contao.routing.scope_matcher')->isBackendRequest(System::getContainer()->get('request_stack')->getCurrentRequest())) {
            $objTemplate = new \Contao\BackendTemplate('be_wildcard');
            $objTemplate->wildcard = '### GUC SUCHE ###';
            $objTemplate->title = $this->headline;
            $objTemplate->id = $this->id;
            $objTemplate->link = $this->name;
            $objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;
            return $objTemplate->parse();
        }

        return parent::generate();
    }

    protected function compile(): void
    {
        $page = $GLOBALS['objPage'] ?? null;
        $language = $page ? $page->language : '';

        $this->Template->language = $language;
        $this->Template->apiUrl = '/api/search';
        $this->Template->minChars = 2;
        $this->Template->debounce = 400;
        $this->Template->placeholder = $GLOBALS['TL_LANG']['MSC']['guc_search_placeholder'] ?? 'Suchen…';
    }
}
