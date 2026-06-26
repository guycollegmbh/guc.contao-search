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

        // Build pid -> page map and resolve language + urlSuffix by walking up to root
        $allPages = $this->db->fetchAllAssociative("SELECT id, pid, type, language, urlSuffix FROM tl_page");
        $pageMap = [];
        foreach ($allPages as $p) {
            $pageMap[$p['id']] = $p;
        }

        $languageMap = [];
        $suffixMap = [];
        foreach ($pageMap as $p) {
            if ($p['type'] === 'root') {
                $languageMap[$p['id']] = $p['language'];
                $suffixMap[$p['id']] = $p['urlSuffix'] ?? '';
            }
        }

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

        $resolveSuffix = function (int $id) use (&$resolveSuffix, $pageMap, &$suffixMap): string {
            if (isset($suffixMap[$id])) {
                return $suffixMap[$id];
            }
            if (!isset($pageMap[$id])) {
                return '';
            }
            $suffix = $resolveSuffix((int) $pageMap[$id]['pid']);
            $suffixMap[$id] = $suffix;
            return $suffix;
        };

        $pages = $this->db->fetchAllAssociative("
            SELECT id, title, alias
            FROM tl_page
            WHERE published = '1'
            AND type = 'regular'
            AND (robots IS NULL OR robots NOT LIKE '%noindex%')
            AND (noSearch IS NULL OR noSearch != '1')
            AND (sitemap IS NULL OR sitemap != 'map_never')
        ");

        // Primary: Contao's own search index (includes RSCE and all custom elements).
        // C7: join tl_page so stale rows for unpublished/deleted pages are excluded.
        $searchRows = $this->db->fetchAllAssociative("
            SELECT s.pid, GROUP_CONCAT(s.text, ' ') AS body
            FROM tl_search s
            INNER JOIN tl_page p ON p.id = s.pid AND p.published = '1' AND p.type = 'regular'
            WHERE s.text != ''
            GROUP BY s.pid
        ");
        $searchByPage = array_column($searchRows, 'body', 'pid');

        // Fallback: direct tl_content read for pages not yet crawled by Contao
        $contentRows = $this->db->fetchAllAssociative("
            SELECT a.pid AS pageId, c.text, c.headline
            FROM tl_article a
            JOIN tl_content c ON c.pid = a.id AND c.ptable = 'tl_article' AND c.invisible = ''
            WHERE a.published = '1'
            AND c.type IN ('text', 'headline', 'html', 'list')
        ");
        $contentByPage = [];
        foreach ($contentRows as $row) {
            $contentByPage[(int) $row['pageId']][] = $row;
        }

        foreach ($pages as $page) {
            $pageId = (int) $page['id'];

            if (isset($searchByPage[$pageId])) {
                $body = strip_tags($searchByPage[$pageId]);
            } else {
                $body = '';
                foreach ($contentByPage[$pageId] ?? [] as $content) {
                    $body .= ' ' . strip_tags($content['text'] ?? '');
                    if (!empty($content['headline'])) {
                        $hl = @unserialize($content['headline'], ['allowed_classes' => false]);
                        if (is_array($hl) && isset($hl['value'])) {
                            $body .= ' ' . strip_tags($hl['value']);
                        }
                    }
                }
            }

            $this->searchRepository->insert([
                'id'       => 'page_' . $pageId,
                'type'     => 'page',
                'language' => $resolveLanguage($pageId),
                'title'    => strip_tags($page['title']),
                'body'     => trim($body),
                'url'      => '/' . ($page['alias'] ?? '') . $resolveSuffix($pageId),
                'badge'    => 'Seite',
            ]);
            $count++;
        }

        $this->searchRepository->setMeta('last_index_page', date('Y-m-d H:i:s'));

        return $count;
    }
}
