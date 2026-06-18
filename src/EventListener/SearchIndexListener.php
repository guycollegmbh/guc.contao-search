<?php

declare(strict_types=1);

namespace Guc\SearchBundle\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Guc\SearchBundle\Indexer\CustomTableIndexer;
use Guc\SearchBundle\Indexer\EventIndexer;
use Guc\SearchBundle\Indexer\FileIndexer;
use Guc\SearchBundle\Indexer\NewsIndexer;
use Guc\SearchBundle\Indexer\PageIndexer;

class SearchIndexListener
{
    public function __construct(
        private readonly PageIndexer $pageIndexer,
        private readonly NewsIndexer $newsIndexer,
        private readonly EventIndexer $eventIndexer,
        private readonly FileIndexer $fileIndexer,
        private readonly CustomTableIndexer $customIndexer,
    ) {}

    // --- News ---

    #[AsCallback(table: 'tl_news', target: 'config.onsubmit')]
    public function onNewsSubmit(DataContainer $dc): void
    {
        $this->newsIndexer->index();
    }

    #[AsCallback(table: 'tl_news', target: 'config.ondelete')]
    public function onNewsDelete(DataContainer $dc, int $undoId): void
    {
        $this->newsIndexer->index();
    }

    // --- Calendar Events ---

    #[AsCallback(table: 'tl_calendar_events', target: 'config.onsubmit')]
    public function onEventSubmit(DataContainer $dc): void
    {
        $this->eventIndexer->index();
    }

    #[AsCallback(table: 'tl_calendar_events', target: 'config.ondelete')]
    public function onEventDelete(DataContainer $dc, int $undoId): void
    {
        $this->eventIndexer->index();
    }

    // --- Pages & Articles ---

    #[AsCallback(table: 'tl_page', target: 'config.onsubmit')]
    #[AsCallback(table: 'tl_article', target: 'config.onsubmit')]
    public function onPageSubmit(DataContainer $dc): void
    {
        $this->pageIndexer->index();
    }

    #[AsCallback(table: 'tl_page', target: 'config.ondelete')]
    #[AsCallback(table: 'tl_article', target: 'config.ondelete')]
    public function onPageDelete(DataContainer $dc, int $undoId): void
    {
        $this->pageIndexer->index();
    }

    // --- Content elements (ptable bestimmt welcher Indexer) ---

    #[AsCallback(table: 'tl_content', target: 'config.onsubmit')]
    public function onContentSubmit(DataContainer $dc): void
    {
        match ($dc->activeRecord?->ptable ?? '') {
            'tl_article'         => $this->pageIndexer->index(),
            'tl_news'            => $this->newsIndexer->index(),
            'tl_calendar_events' => $this->eventIndexer->index(),
            default              => null,
        };
    }

    #[AsCallback(table: 'tl_content', target: 'config.ondelete')]
    public function onContentDelete(DataContainer $dc, int $undoId): void
    {
        // ptable nach Löschung nicht mehr zuverlässig bestimmbar
        $this->pageIndexer->index();
        $this->newsIndexer->index();
        $this->eventIndexer->index();
    }

    // --- Files ---

    #[AsCallback(table: 'tl_files', target: 'config.onsubmit')]
    public function onFilesSubmit(DataContainer $dc): void
    {
        $this->fileIndexer->index();
    }

    #[AsCallback(table: 'tl_files', target: 'config.ondelete')]
    public function onFilesDelete(DataContainer $dc, int $undoId): void
    {
        $this->fileIndexer->index();
    }

    // --- Custom-Tabellen-Konfiguration ---

    #[AsCallback(table: 'tl_search_config', target: 'config.onsubmit')]
    public function onSearchConfigSubmit(DataContainer $dc): void
    {
        $this->customIndexer->index();
    }

    #[AsCallback(table: 'tl_search_config', target: 'config.ondelete')]
    public function onSearchConfigDelete(DataContainer $dc, int $undoId): void
    {
        $this->customIndexer->index();
    }
}
