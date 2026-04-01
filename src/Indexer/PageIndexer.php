<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Indexer;

use Doctrine\DBAL\Connection;
use Guc\SearchBundle\Repository\SearchRepository;

class PageIndexer implements IndexerInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly SearchRepository $searchRepository,
    ) {}

    public function getType(): string
    {
        return 'page';
    }

    public function index(): int
    {
        $this->searchRepository->clearType('page');
        $count = 0;

        // Build pid -> language map by walking up tree from root pages
        $allPages = $this->db->fetchAllAssociative("SELECT id, pid, type, language FROM tl_page");
        $pageMap = [];
        foreach ($allPages as $p) {
            $pageMap[$p['id']] = $p;
        }

        $languageMap = [];
        foreach ($pageMap as $p) {
            if ($p['type'] === 'root') {
                $languageMap[$p['id']] = $p['language'];
            }
        }
        // Resolve language for each page by walking up pid chain
        $resolveLanguage = function (int $id) use (&$resolveLanguage, $pageMap, &$languageMap): string {
            if (isset($languageMap[$id])) {
                return $languageMap[$id];
            }
            if (!isset($pageMap[$id])) {
                return '';
            }
            $lang = $resolveLanguage((int) $pageMap[$id]['pid']);
            $languageMap[$id] = $lang;
            return $lang;
        };

        $pages = $this->db->fetchAllAssociative("
            SELECT p.id, p.title, p.alias,
                   s.text as content
            FROM tl_page p
            LEFT JOIN tl_search s ON s.pid = p.id
            WHERE p.published = '1'
            AND p.type = 'regular'
            AND (p.robots IS NULL OR p.robots NOT LIKE '%noindex%')
        ");

        foreach ($pages as $page) {
            $this->searchRepository->insert([
                'id'       => 'page_' . $page['id'],
                'type'     => 'page',
                'language' => $resolveLanguage((int) $page['id']),
                'title'    => strip_tags($page['title']),
                'body'     => strip_tags($page['content'] ?? ''),
                'url'      => '/' . ($page['alias'] ?? '') . '.html',
                'badge'    => 'Seite',
            ]);
            $count++;
        }

        $this->searchRepository->setMeta('last_index_page', date('Y-m-d H:i:s'));

        return $count;
    }
}
