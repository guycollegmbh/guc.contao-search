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
        $this->searchRepository->clearType('news');
        $count = 0;

        try {
            $news = $this->db->fetchAllAssociative("
                SELECT n.id, n.headline, n.teaser, n.alias,
                       na.jumpTo, p.language
                FROM tl_news n
                JOIN tl_news_archive na ON na.id = n.pid
                LEFT JOIN tl_page p ON p.id = na.jumpTo
                WHERE n.published = '1'
                AND (n.start = '' OR n.start <= UNIX_TIMESTAMP())
                AND (n.stop = '' OR n.stop > UNIX_TIMESTAMP())
            ");
        } catch (\Exception $e) {
            $this->logger?->warning('GUC Search: NewsIndexer failed - ' . $e->getMessage());
            return 0;
        }

        $pageAliasMap = array_column(
            $this->db->fetchAllAssociative("SELECT id, alias FROM tl_page"),
            'alias',
            'id'
        );

        foreach ($news as $item) {
            $body = strip_tags($item['teaser'] ?? '');
            $pageAlias = $pageAliasMap[$item['jumpTo']] ?? 'news';

            $this->searchRepository->insert([
                'id'       => 'news_' . $item['id'],
                'type'     => 'news',
                'language' => $item['language'] ?? '',
                'title'    => strip_tags($item['headline']),
                'body'     => trim($body),
                'url'      => '/' . $pageAlias . '/' . $item['alias'] . '.html',
                'badge'    => 'News',
            ]);
            $count++;
        }

        $this->searchRepository->setMeta('last_index_news', date('Y-m-d H:i:s'));

        return $count;
    }
}
