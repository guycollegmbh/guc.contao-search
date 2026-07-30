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
        $now = time();
        try {
            $events = $this->db->fetchAllAssociative("
                SELECT e.id, e.title, e.teaser, e.alias,
                       e.startDate, e.startTime, c.jumpTo, p.language
                FROM tl_calendar_events e
                JOIN tl_calendar c ON c.id = e.pid
                LEFT JOIN tl_page p ON p.id = c.jumpTo
                WHERE e.published = '1'
                AND (e.start = '' OR e.start <= :now)
                AND (e.stop = '' OR e.stop > :now)
            ", ['now' => $now]);
        } catch (\Exception $e) {
            $this->logger?->warning('GUC Search: EventIndexer failed - ' . $e->getMessage());
            return 0;
        }

        if (empty($events)) {
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
            SELECT c.pid AS eventId, c.text, c.headline
            FROM tl_content c
            WHERE c.ptable = 'tl_calendar_events'
            AND c.invisible = ''
            AND c.type IN ('text', 'headline', 'html', 'list')
        ");
        $contentByEvent = [];
        foreach ($contentRows as $row) {
            $contentByEvent[(int) $row['eventId']][] = $row;
        }

        $count = 0;
        $this->searchRepository->beginTransaction();
        try {
            $this->searchRepository->clearType('event');

            foreach ($events as $event) {
                $body = strip_tags($event['teaser'] ?? '');
                foreach ($contentByEvent[(int) $event['id']] ?? [] as $content) {
                    $body .= ' ' . strip_tags($content['text'] ?? '');
                    if (!empty($content['headline'])) {
                        $hl = @unserialize($content['headline'], ['allowed_classes' => false]);
                        if (is_array($hl) && isset($hl['value'])) {
                            $body .= ' ' . strip_tags($hl['value']);
                        }
                    }
                }
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
                    'badge'    => 'Events',
                ]);
                $count++;
            }

            $this->searchRepository->setMeta('last_index_event', date('Y-m-d H:i:s'));
            $this->searchRepository->commit();
        } catch (\Throwable $e) {
            $this->searchRepository->rollback();
            throw $e;
        }

        return $count;
    }
}
