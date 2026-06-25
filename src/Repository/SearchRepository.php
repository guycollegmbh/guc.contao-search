<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Repository;

class SearchRepository
{
    private \PDO $pdo;
    private string $dbPath;

    public function __construct(string $projectDir)
    {
        $this->dbPath = $projectDir . '/var/search.db';
        $this->connect();
    }

    private function connect(): void
    {
        $this->pdo = new \PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("PRAGMA journal_mode=WAL");
        $this->pdo->exec("PRAGMA synchronous=NORMAL");
        $this->pdo->exec("PRAGMA cache_size=-10000");
        $this->createTables();
    }

    private function createTables(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS search_meta (
                key TEXT PRIMARY KEY,
                value TEXT
            )
        ");

        // Schema v1: prefix='2 3 4' for faster prefix queries; rebuild required
        $version = (int) $this->pdo->query("PRAGMA user_version")->fetchColumn();
        if ($version < 1) {
            $this->pdo->exec("DROP TABLE IF EXISTS search_index");
        }

        $this->pdo->exec("
            CREATE VIRTUAL TABLE IF NOT EXISTS search_index USING fts5(
                id UNINDEXED,
                type UNINDEXED,
                language UNINDEXED,
                title,
                body,
                url UNINDEXED,
                badge UNINDEXED,
                updated UNINDEXED,
                tokenize='unicode61',
                prefix='2 3 4'
            )
        ");

        if ($version < 1) {
            $this->pdo->exec("PRAGMA user_version = 1");
        }
    }

    public function search(string $query, string $language = '', int $limit = 10, int $offset = 0): array
    {
        $query = $this->sanitizeQuery($query);
        if (empty($query)) {
            return [];
        }

        $ftsQuery = $query . '*';

        if ($language !== '') {
            $sql = "
                SELECT id, type, language, title, url, badge,
                       snippet(search_index, 4, '<mark>', '</mark>', '…', 32) AS excerpt,
                       snippet(search_index, 3, '<mark>', '</mark>', '', 20) AS titleHighlight
                FROM search_index
                WHERE search_index MATCH :query
                AND (language = :language OR language = '')
                ORDER BY bm25(search_index, 0.0, 0.0, 0.0, 10.0, 1.0, 0.0, 0.0, 0.0)
                LIMIT :limit OFFSET :offset
            ";
            $params = [':query' => $ftsQuery, ':language' => $language];
        } else {
            $sql = "
                SELECT id, type, language, title, url, badge,
                       snippet(search_index, 4, '<mark>', '</mark>', '…', 32) AS excerpt,
                       snippet(search_index, 3, '<mark>', '</mark>', '', 20) AS titleHighlight
                FROM search_index
                WHERE search_index MATCH :query
                ORDER BY bm25(search_index, 0.0, 0.0, 0.0, 10.0, 1.0, 0.0, 0.0, 0.0)
                LIMIT :limit OFFSET :offset
            ";
            $params = [':query' => $ftsQuery];
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function searchGrouped(string $query, string $language = '', int $perGroup = 10): array
    {
        $types = ['page', 'file', 'event', 'news', 'custom'];
        $groups = [];

        foreach ($types as $type) {
            $results = $this->searchByType($query, $type, $language, $perGroup);
            if (!empty($results)) {
                $groups[$type] = $results;
            }
        }

        return $groups;
    }

    public function searchByType(string $query, string $type, string $language = '', int $limit = 10, int $offset = 0): array
    {
        $query = $this->sanitizeQuery($query);
        if (empty($query)) {
            return [];
        }

        $ftsQuery = $query . '*';

        $params = [':query' => $ftsQuery, ':type' => $type];

        if ($language !== '') {
            $sql = "
                SELECT id, type, language, title, url, badge,
                       snippet(search_index, 4, '<mark>', '</mark>', '…', 32) AS excerpt,
                       snippet(search_index, 3, '<mark>', '</mark>', '', 20) AS titleHighlight
                FROM search_index
                WHERE search_index MATCH :query
                AND type = :type
                AND (language = :language OR language = '')
                ORDER BY bm25(search_index, 0.0, 0.0, 0.0, 10.0, 1.0, 0.0, 0.0, 0.0)
                LIMIT :limit OFFSET :offset
            ";
            $params[':language'] = $language;
        } else {
            $sql = "
                SELECT id, type, language, title, url, badge,
                       snippet(search_index, 4, '<mark>', '</mark>', '…', 32) AS excerpt,
                       snippet(search_index, 3, '<mark>', '</mark>', '', 20) AS titleHighlight
                FROM search_index
                WHERE search_index MATCH :query
                AND type = :type
                ORDER BY bm25(search_index, 0.0, 0.0, 0.0, 10.0, 1.0, 0.0, 0.0, 0.0)
                LIMIT :limit OFFSET :offset
            ";
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function countByType(string $query, string $type, string $language = ''): int
    {
        $query = $this->sanitizeQuery($query);
        if (empty($query)) {
            return 0;
        }

        $ftsQuery = $query . '*';

        $params = [':query' => $ftsQuery, ':type' => $type];

        if ($language !== '') {
            $sql = "SELECT COUNT(*) FROM search_index WHERE search_index MATCH :query AND type = :type AND (language = :language OR language = '')";
            $params[':language'] = $language;
        } else {
            $sql = "SELECT COUNT(*) FROM search_index WHERE search_index MATCH :query AND type = :type";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function clearType(string $type): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM search_index WHERE type = :type");
        $stmt->execute([':type' => $type]);
    }

    public function clearAll(): void
    {
        $this->pdo->exec("DELETE FROM search_index");
    }

    public function insert(array $record): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO search_index (id, type, language, title, body, url, badge, updated)
            VALUES (:id, :type, :language, :title, :body, :url, :badge, :updated)
        ");
        $stmt->execute([
            ':id'       => $record['id'],
            ':type'     => $record['type'],
            ':language' => $record['language'] ?? '',
            ':title'    => $record['title'] ?? '',
            ':body'     => $record['body'] ?? '',
            ':url'      => $record['url'] ?? '',
            ':badge'    => $record['badge'] ?? '',
            ':updated'  => $record['updated'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    public function setMeta(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare("INSERT OR REPLACE INTO search_meta (key, value) VALUES (:key, :value)");
        $stmt->execute([':key' => $key, ':value' => $value]);
    }

    public function getMeta(string $key): ?string
    {
        $stmt = $this->pdo->prepare("SELECT value FROM search_meta WHERE key = :key");
        $stmt->execute([':key' => $key]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : null;
    }

    public function getStats(): array
    {
        $stmt = $this->pdo->query("SELECT type, COUNT(*) as count FROM search_index GROUP BY type");
        $stats = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $stats[$row['type']] = (int) $row['count'];
        }
        return $stats;
    }

    public function getDbPath(): string
    {
        return $this->dbPath;
    }

    private function sanitizeQuery(string $query): string
    {
        // Remove FTS5 special characters that could break queries
        $query = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $query);
        return trim($query);
    }
}
