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
        $count = 0;

        // Load active categories: id → ['alias' => ..., 'title' => ...]
        $categoryMap = [];
        try {
            $rows = $this->db->fetchAllAssociative(
                "SELECT id, alias, title FROM tl_guc_category WHERE active = '1'"
            );
            foreach ($rows as $row) {
                $categoryMap[(int) $row['id']] = ['alias' => $row['alias'], 'title' => $row['title']];
            }
        } catch (\Throwable) {
            // tl_guc_category not yet migrated — fall back to generic page type
        }

        // Aggregate article categories per page: pageId → [catId => true]
        $pageCategories = [];
        if (!empty($categoryMap)) {
            $articleRows = $this->db->fetchAllAssociative(
                "SELECT pid, guc_categories FROM tl_article
                 WHERE published = '1' AND guc_categories IS NOT NULL AND guc_categories != ''"
            );
            foreach ($articleRows as $row) {
                $cats = @unserialize((string) $row['guc_categories'], ['allowed_classes' => false]);
                if (!is_array($cats)) {
                    continue;
                }
                foreach ($cats as $catId) {
                    $catId = (int) $catId;
                    if (isset($categoryMap[$catId])) {
                        $pageCategories[(int) $row['pid']][$catId] = true;
                    }
                }
            }
        }

        // Build pid -> page map and resolve language + urlSuffix by walking up to root.
        // Null sentinel in the memoization maps breaks potential circular pid references.
        $allPages = $this->db->fetchAllAssociative("SELECT id, pid, type, language, urlSuffix FROM tl_page");
        $pageMap  = [];
        foreach ($allPages as $p) {
            $pageMap[$p['id']] = $p;
        }

        $languageMap = [];
        $suffixMap   = [];
        foreach ($pageMap as $p) {
            if ($p['type'] === 'root') {
                $languageMap[$p['id']] = $p['language'];
                $suffixMap[$p['id']]   = $p['urlSuffix'] ?? '';
            }
        }

        $resolveLanguage = function (int $id) use (&$resolveLanguage, $pageMap, &$languageMap): string {
            if (array_key_exists($id, $languageMap)) {
                return $languageMap[$id] ?? '';
            }
            if (!isset($pageMap[$id])) {
                return '';
            }
            $languageMap[$id] = null; // cycle sentinel
            $lang = $resolveLanguage((int) $pageMap[$id]['pid']);
            $languageMap[$id] = $lang;
            return $lang;
        };

        $resolveSuffix = function (int $id) use (&$resolveSuffix, $pageMap, &$suffixMap): string {
            if (array_key_exists($id, $suffixMap)) {
                return $suffixMap[$id] ?? '';
            }
            if (!isset($pageMap[$id])) {
                return '';
            }
            $suffixMap[$id] = null; // cycle sentinel
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

        // Wrap clear + inserts in a transaction so an aborted run never leaves an empty index.
        $this->searchRepository->beginTransaction();
        try {
            // Clear ALL page-related entries (type may now be a category alias, not just 'page')
            $this->searchRepository->clearByIdPrefix('page_');
            $allText = [];

            foreach ($pages as $page) {
                $pageId   = (int) $page['id'];
                $language = $resolveLanguage($pageId);
                $url      = '/' . ($page['alias'] ?? '') . $resolveSuffix($pageId);
                $title    = strip_tags($page['title']);

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

                $pageCats = $pageCategories[$pageId] ?? [];

                $bodyClean = trim($body);

                if (empty($pageCats)) {
                    // No categories assigned — index as generic page
                    $this->searchRepository->insert([
                        'id'       => 'page_' . $pageId,
                        'type'     => 'page',
                        'language' => $language,
                        'title'    => $title,
                        'body'     => $bodyClean,
                        'url'      => $url,
                        'badge'    => 'Seite',
                    ]);
                    $count++;
                    $allText[] = $title . ' ' . $bodyClean;
                    continue;
                }

                // Index once per assigned category
                foreach (array_keys($pageCats) as $catId) {
                    $cat = $categoryMap[$catId];
                    $this->searchRepository->insert([
                        'id'       => 'page_' . $pageId . '_cat_' . $catId,
                        'type'     => $cat['alias'],
                        'language' => $language,
                        'title'    => $title,
                        'body'     => $bodyClean,
                        'url'      => $url,
                        'badge'    => $cat['title'],
                    ]);
                    $count++;
                }
                $allText[] = $title . ' ' . $bodyClean;
            }

            $this->searchRepository->upsertWords(SearchRepository::extractWords(implode(' ', $allText)));
            $this->searchRepository->setMeta('last_index_page', date('Y-m-d H:i:s'));
            $this->searchRepository->commit();
        } catch (\Throwable $e) {
            $this->searchRepository->rollback();
            throw $e;
        }

        return $count;
    }
}
