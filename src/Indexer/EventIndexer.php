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
                SELECT e.id, e.title, e.teaser, e.details, e.alias, e.language,
                       e.startDate, e.startTime
                FROM tl_calendar_events e
                JOIN tl_calendar c ON c.id = e.pid
                WHERE e.published = '1'
                AND (e.start = '' OR e.start <= UNIX_TIMESTAMP())
                AND (e.stop = '' OR e.stop > UNIX_TIMESTAMP())
            ");
        } catch (\Exception $e) {
            $this->logger?->warning('GUC Search: EventIndexer failed - ' . $e->getMessage());
            return 0;
        }

        foreach ($events as $event) {
            $body = strip_tags($event['teaser'] ?? '') . ' ' . strip_tags($event['details'] ?? '');

            $this->searchRepository->insert([
                'id'       => 'event_' . $event['id'],
                'type'     => 'event',
                'language' => $event['language'] ?? '',
                'title'    => strip_tags($event['title']),
                'body'     => trim($body),
                'url'      => '/events/' . $event['alias'] . '.html',
                'badge'    => 'Event',
            ]);
            $count++;
        }

        $this->searchRepository->setMeta('last_index_event', date('Y-m-d H:i:s'));

        return $count;
    }
}
