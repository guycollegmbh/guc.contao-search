<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Indexer;

use Doctrine\DBAL\Connection;
use Guc\SearchBundle\Repository\SearchRepository;
use Psr\Log\LoggerInterface;

class EventIndexer implements IndexerInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly SearchRepository $searchRepository,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function getType(): string
    {
        return 'event';
    }

    public function index(): int
    {
        $this->searchRepository->clearType('event');
        $count = 0;

        try {
            $events = $this->db->fetchAllAssociative("
                SELECT e.id, e.title, e.teaser, e.alias,
                       e.startDate, e.startTime, c.jumpTo, p.language
                FROM tl_calendar_events e
                JOIN tl_calendar c ON c.id = e.pid
                LEFT JOIN tl_page p ON p.id = c.jumpTo
                WHERE e.published = '1'
                AND (e.start = '' OR e.start <= UNIX_TIMESTAMP())
                AND (e.stop = '' OR e.stop > UNIX_TIMESTAMP())
            ");
        } catch (\Exception $e) {
            $this->logger?->warning('GUC Search: EventIndexer failed - ' . $e->getMessage());
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
        $resolveSuffix = function (int $id) use (&$resolveSuffix, $pageMap, &$suffixMap): string {
            if (isset($suffixMap[$id])) return $suffixMap[$id];
            if (!isset($pageMap[$id])) return '';
            return $suffixMap[$id] = $resolveSuffix((int) $pageMap[$id]['pid']);
        };

        foreach ($events as $event) {
            $body = strip_tags($event['teaser'] ?? '');
            $jumpTo = (int) $event['jumpTo'];
            $pageAlias = $pageMap[$jumpTo]['alias'] ?? 'events';
            $suffix = $resolveSuffix($jumpTo);

            $this->searchRepository->insert([
                'id'       => 'event_' . $event['id'],
                'type'     => 'event',
                'language' => $event['language'] ?? '',
                'title'    => strip_tags($event['title']),
                'body'     => trim($body),
                'url'      => '/' . $pageAlias . '/' . $event['alias'] . $suffix,
                'badge'    => 'Event',
            ]);
            $count++;
        }

        $this->searchRepository->setMeta('last_index_event', date('Y-m-d H:i:s'));

        return $count;
    }
}
