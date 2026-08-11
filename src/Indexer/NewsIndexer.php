<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Indexer;

use Doctrine\DBAL\Connection;
use Guc\SearchBundle\Repository\SearchRepository;
use Psr\Log\LoggerInterface;

class NewsIndexer implements IndexerInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly SearchRepository $searchRepository,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function getType(): string
    {
        return 'news';
    }

    public function index(): int
    {
        $now = time();
        try {
            $news = $this->db->fetchAllAssociative("
                SELECT n.id, n.headline, n.teaser, n.alias,
                       na.jumpTo, p.language
                FROM tl_news n
                JOIN tl_news_archive na ON na.id = n.pid
                LEFT JOIN tl_page p ON p.id = na.jumpTo
                WHERE n.published = '1'
                AND (n.start = '' OR n.start <= :now)
                AND (n.stop = '' OR n.stop > :now)
            ", ['now' => $now]);
        } catch (\Exception $e) {
            $this->logger?->warning('GUC Search: NewsIndexer failed - ' . $e->getMessage());
            return 0;
        }

        if (empty($news)) {
            return 0;
        }

        $allPages = $this->db->fetchAllAssociative("SELECT id, pid, type, alias, urlSuffix FROM tl_page");
        $pageMap = array_column($allPages, null, 'id');

        $suffixMap = [];
        foreach ($pageMap as $p) {
            if ($p['type'] === 'root') {
                $suffixMap[$p['id']] = $p['urlSuffix'] ?? '';
            }
        }

        // Null sentinel breaks potential circular pid references
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

        $contentRows = $this->db->fetchAllAssociative("
            SELECT c.pid AS newsId, c.text, c.headline
            FROM tl_content c
            WHERE c.ptable = 'tl_news'
            AND c.invisible = ''
            AND c.type IN ('text', 'headline', 'html', 'list')
        ");
        $contentByNews = [];
        foreach ($contentRows as $row) {
            $contentByNews[(int) $row['newsId']][] = $row;
        }

        $count = 0;
        $allText = [];
        $this->searchRepository->beginTransaction();
        try {
            $this->searchRepository->clearType('news');

            foreach ($news as $item) {
                $body = strip_tags($item['teaser'] ?? '');
                foreach ($contentByNews[(int) $item['id']] ?? [] as $content) {
                    $body .= ' ' . strip_tags($content['text'] ?? '');
                    if (!empty($content['headline'])) {
                        $hl = @unserialize($content['headline'], ['allowed_classes' => false]);
                        if (is_array($hl) && isset($hl['value'])) {
                            $body .= ' ' . strip_tags($hl['value']);
                        }
                    }
                }
                $jumpTo = (int) $item['jumpTo'];
                $pageAlias = $pageMap[$jumpTo]['alias'] ?? 'news';
                $suffix = $resolveSuffix($jumpTo);
                $title = strip_tags($item['headline']);
                $bodyClean = trim($body);

                $this->searchRepository->insert([
                    'id'       => 'news_' . $item['id'],
                    'type'     => 'news',
                    'language' => $item['language'] ?? '',
                    'title'    => $title,
                    'body'     => $bodyClean,
                    'url'      => '/' . $pageAlias . '/' . $item['alias'] . $suffix,
                    'badge'    => 'News',
                ]);
                $count++;
                $allText[] = $title . ' ' . $bodyClean;
            }

            $this->searchRepository->upsertWords(SearchRepository::extractWords(implode(' ', $allText)));
            $this->searchRepository->setMeta('last_index_news', date('Y-m-d H:i:s'));
            $this->searchRepository->commit();
        } catch (\Throwable $e) {
            $this->searchRepository->rollback();
            throw $e;
        }

        return $count;
    }
}
