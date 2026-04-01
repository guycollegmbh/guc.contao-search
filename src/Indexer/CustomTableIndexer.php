<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Indexer;

use Doctrine\DBAL\Connection;
use Guc\SearchBundle\Repository\SearchRepository;
use Psr\Log\LoggerInterface;

class CustomTableIndexer implements IndexerInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly SearchRepository $searchRepository,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function getType(): string
    {
        return 'custom';
    }

    public function index(): int
    {
        $this->searchRepository->clearType('custom');
        $count = 0;

        // Load configurations from tl_search_config
        try {
            $configs = $this->db->fetchAllAssociative("
                SELECT * FROM tl_search_config WHERE active = '1'
            ");
        } catch (\Exception $e) {
            $this->logger?->warning('GUC Search: CustomTableIndexer failed loading config - ' . $e->getMessage());
            return 0;
        }

        foreach ($configs as $config) {
            $count += $this->indexTable($config);
        }

        $this->searchRepository->setMeta('last_index_custom', date('Y-m-d H:i:s'));

        return $count;
    }

    private function indexTable(array $config): int
    {
        $count = 0;
        $table = $config['tableName'];
        $titleField = $config['titleField'];
        $bodyField = $config['bodyField'];
        $urlPattern = $config['urlPattern'];
        $badge = $config['badge'] ?: 'Inhalt';
        $aliasField = $config['aliasField'] ?: 'alias';

        // Validate identifiers to prevent SQL injection (only allow word chars and underscores)
        $validIdentifier = static fn(string $s): bool => (bool) preg_match('/^\w+$/', $s);
        if (!$validIdentifier($table) || !$validIdentifier($titleField) || !$validIdentifier($bodyField) || !$validIdentifier($aliasField)) {
            return 0;
        }

        // urlPattern must contain exactly one %s placeholder and nothing else format-string-related
        if (substr_count($urlPattern, '%') !== 1 || !str_contains($urlPattern, '%s')) {
            return 0;
        }

        try {
            $sql = "SELECT id, {$titleField}, {$bodyField}";
            if ($aliasField) {
                $sql .= ", {$aliasField}";
            }
            $sql .= " FROM {$table} WHERE published = '1'";

            $rows = $this->db->fetchAllAssociative($sql);
        } catch (\Exception $e) {
            $this->logger?->warning('GUC Search: CustomTableIndexer failed indexing table "' . $table . '" - ' . $e->getMessage());
            return 0;
        }

        foreach ($rows as $row) {
            $alias = $row[$aliasField] ?? $row['id'];
            $url = sprintf($urlPattern, $alias);

            $this->searchRepository->insert([
                'id'       => 'custom_' . $table . '_' . $row['id'],
                'type'     => 'custom',
                'language' => '',
                'title'    => strip_tags($row[$titleField] ?? ''),
                'body'     => strip_tags($row[$bodyField] ?? ''),
                'url'      => $url,
                'badge'    => $badge,
            ]);
            $count++;
        }

        return $count;
    }
}
