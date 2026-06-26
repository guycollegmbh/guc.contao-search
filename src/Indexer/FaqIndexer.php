<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Indexer;

use Doctrine\DBAL\Connection;
use Guc\SearchBundle\Repository\SearchRepository;
use Psr\Log\LoggerInterface;

class FaqIndexer implements IndexerInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly SearchRepository $searchRepository,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function getType(): string
    {
        return 'faq';
    }

    public function index(): int
    {
        $count = 0;

        try {
            $faqs = $this->db->fetchAllAssociative("
                SELECT f.id, f.question, f.answer, f.alias,
                       fc.jumpTo, p.language
                FROM tl_faq f
                JOIN tl_faq_category fc ON fc.id = f.pid
                LEFT JOIN tl_page p ON p.id = fc.jumpTo
                WHERE f.published = '1'
            ");
        } catch (\Exception $e) {
            $this->logger?->warning('GUC Search: FaqIndexer failed - ' . $e->getMessage());
            return 0;
        }

        if (empty($faqs)) {
            return 0;
        }

        $this->searchRepository->clearType('faq');

        $allPages = $this->db->fetchAllAssociative("SELECT id, pid, type, alias, urlSuffix FROM tl_page");
        $pageMap = array_column($allPages, null, 'id');

        $suffixMap = [];
        foreach ($pageMap as $p) {
            if ($p['type'] === 'root') {
                $suffixMap[$p['id']] = $p['urlSuffix'] ?? '';
            }
        }
        $resolveSuffix = function (int $id) use (&$resolveSuffix, $pageMap, &$suffixMap): string {
            if (isset($suffixMap[$id])) return $suffixMap[$id];
            if (!isset($pageMap[$id])) return '';
            return $suffixMap[$id] = $resolveSuffix((int) $pageMap[$id]['pid']);
        };

        foreach ($faqs as $faq) {
            $jumpTo = (int) $faq['jumpTo'];
            $pageAlias = $pageMap[$jumpTo]['alias'] ?? 'faq';
            $suffix = $resolveSuffix($jumpTo);

            $this->searchRepository->insert([
                'id'       => 'faq_' . $faq['id'],
                'type'     => 'faq',
                'language' => $faq['language'] ?? '',
                'title'    => strip_tags($faq['question']),
                'body'     => strip_tags($faq['answer'] ?? ''),
                'url'      => '/' . $pageAlias . '/' . ($faq['alias'] ?? '') . $suffix,
                'badge'    => 'FAQ',
            ]);
            $count++;
        }

        $this->searchRepository->setMeta('last_index_faq', date('Y-m-d H:i:s'));

        return $count;
    }
}
