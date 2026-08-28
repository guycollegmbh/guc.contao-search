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
        $this->pdo->exec("PRAGMA busy_timeout=5000");
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

        // Schema v1: prefix='2 3 4' for faster prefix queries; rebuild required on upgrade
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

        // Word dictionary for fuzzy search fallback
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS search_words (
                word TEXT NOT NULL PRIMARY KEY,
                frequency INTEGER NOT NULL DEFAULT 1
            )
        ");

        if ($version < 1) {
            $this->pdo->exec("PRAGMA user_version = 1");
        }
    }

    // --- Transaction support ---

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    // --- Word dictionary (fuzzy search) ---

    /**
     * Extracts indexable words (≥4 Unicode letters) from plain or HTML text.
     * Used by indexers to populate the word dictionary.
     */
    public static function extractWords(string $text): array
    {
        $text = mb_strtolower(strip_tags($text));
        preg_match_all('/\p{L}{4,}/u', $text, $matches);
        return array_unique($matches[0]);
    }

    /**
     * Inserts or increments frequency for each word.
     * Call inside the indexer transaction so a failed index also rolls back word changes.
     */
    public function upsertWords(array $words): void
    {
        if (empty($words)) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO search_words (word, frequency) VALUES (:word, 1)
             ON CONFLICT(word) DO UPDATE SET frequency = frequency + 1'
        );
        foreach ($words as $word) {
            $stmt->execute([':word' => $word]);
        }
    }

    /** Removes all words — call before a full index rebuild so stale words are cleaned up. */
    public function clearWords(): void
    {
        $this->pdo->exec('DELETE FROM search_words');
    }

    /** Returns the most frequent words — used for Levenshtein comparison in fuzzy fallback. */
    public function getAllWords(int $limit = 10000): array
    {
        $stmt = $this->pdo->prepare('SELECT word FROM search_words ORDER BY frequency DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** Returns words starting with $prefix ordered by frequency — used for autocomplete suggestions. */
    public function getSuggestions(string $prefix, int $limit = 8): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT word FROM search_words WHERE word LIKE :prefix ORDER BY frequency DESC LIMIT :limit'
        );
        $stmt->bindValue(':prefix', $prefix . '%');
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    // --- Search ---

    public function searchGrouped(string $query, string $language = '', int $perGroup = 10, array $enabledTypes = []): array
    {
        $clean = $this->sanitizeQuery($query);
        if ('' === $clean) {
            return [];
        }
        return $this->searchGroupedFts($this->buildFtsQuery($clean), $language, $perGroup, $enabledTypes);
    }

    /** Same as searchGrouped but accepts a pre-built FTS5 query — used for fuzzy expansion. */
    public function searchGroupedFts(string $ftsQuery, string $language = '', int $perGroup = 10, array $enabledTypes = []): array
    {
        $types = empty($enabledTypes) ? $this->getDistinctTypes() : $enabledTypes;
        $groups = [];
        foreach ($types as $type) {
            $results = $this->runSearch($ftsQuery, $type, $language, $perGroup, 0);
            if (!empty($results)) {
                $groups[$type] = $results;
            }
        }
        return $groups;
    }

    public function searchByType(string $query, string $type, string $language = '', int $limit = 10, int $offset = 0): array
    {
        $clean = $this->sanitizeQuery($query);
        if ('' === $clean) {
            return [];
        }
        return $this->runSearch($this->buildFtsQuery($clean), $type, $language, $limit, $offset);
    }

    /** Same as searchByType but accepts a pre-built FTS5 query — used for fuzzy expansion. */
    public function searchByTypeFts(string $ftsQuery, string $type, string $language = '', int $limit = 10, int $offset = 0): array
    {
        return $this->runSearch($ftsQuery, $type, $language, $limit, $offset);
    }

    public function countByType(string $query, string $type, string $language = ''): int
    {
        $clean = $this->sanitizeQuery($query);
        if ('' === $clean) {
            return 0;
        }
        return $this->runCount($this->buildFtsQuery($clean), $type, $language);
    }

    /** Same as countByType but accepts a pre-built FTS5 query — used for fuzzy expansion. */
    public function countByTypeFts(string $ftsQuery, string $type, string $language = ''): int
    {
        return $this->runCount($ftsQuery, $type, $language);
    }

    public function countGrouped(string $query, string $language = '', array $enabledTypes = []): array
    {
        $clean = $this->sanitizeQuery($query);
        if ('' === $clean) {
            return [];
        }
        return $this->runCountGrouped($this->buildFtsQuery($clean), $language, $enabledTypes);
    }

    /** Same as countGrouped but accepts a pre-built FTS5 query — used for fuzzy expansion. */
    public function countGroupedFts(string $ftsQuery, string $language = '', array $enabledTypes = []): array
    {
        return $this->runCountGrouped($ftsQuery, $language, $enabledTypes);
    }

    // --- Private SQL helpers ---

    private function runSearch(string $ftsQuery, string $type, string $language, int $limit, int $offset): array
    {
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

    private function runCount(string $ftsQuery, string $type, string $language): int
    {
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

    private function runCountGrouped(string $ftsQuery, string $language, array $enabledTypes): array
    {
        $params = [':query' => $ftsQuery];
        $conditions = ['search_index MATCH :query'];

        if ($language !== '') {
            $conditions[] = "(language = :language OR language = '')";
            $params[':language'] = $language;
        }

        if (!empty($enabledTypes)) {
            $placeholders = implode(',', array_map(static fn(int $i) => ':etype' . $i, array_keys($enabledTypes)));
            $conditions[] = 'type IN (' . $placeholders . ')';
            foreach ($enabledTypes as $i => $t) {
                $params[':etype' . $i] = $t;
            }
        }

        $sql = 'SELECT type, COUNT(*) AS cnt FROM search_index WHERE ' . implode(' AND ', $conditions) . ' GROUP BY type';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[$row['type']] = (int) $row['cnt'];
        }

        return $counts;
    }

    // --- Write ---

    public function clearType(string $type): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM search_index WHERE type = :type");
        $stmt->execute([':type' => $type]);
    }

    // Deletes all entries whose id starts with $prefix — used by PageIndexer to clear
    // all page-related entries regardless of which category type they were indexed under.
    // Two-step via rowid because FTS5 GLOB on UNINDEXED columns is unreliable in DELETE.
    public function clearByIdPrefix(string $prefix): void
    {
        $stmt = $this->pdo->prepare("SELECT rowid FROM search_index WHERE id GLOB :pattern");
        $stmt->execute([':pattern' => $prefix . '*']);
        $rowids = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($rowids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($rowids), '?'));
        $this->pdo->prepare("DELETE FROM search_index WHERE rowid IN ($placeholders)")
            ->execute($rowids);
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
            ':title'    => $this->cleanText($record['title'] ?? ''),
            ':body'     => $this->cleanText($record['body'] ?? ''),
            ':url'      => $record['url'] ?? '',
            ':badge'    => $record['badge'] ?? '',
            ':updated'  => $record['updated'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x{00AD}\x{200B}\x{FEFF}]/u', '', $text);
        return trim($text);
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

    private function getDistinctTypes(): array
    {
        $stmt = $this->pdo->query("SELECT DISTINCT type FROM search_index ORDER BY type");
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function sanitizeQuery(string $query): string
    {
        $query = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $query);

        // Hyphens carry no meaning on their own — drop them unless they sit inside a
        // word, so "a -" does not become a token that matches nothing.
        $query = preg_replace('/(?<![\p{L}\p{N}])-+|-+(?![\p{L}\p{N}])/u', '', $query);

        return trim(preg_replace('/\s+/u', ' ', $query));
    }

    /**
     * Turns a sanitized query into an FTS5 expression.
     *
     * Every token is wrapped in double quotes so it is parsed as a string literal
     * rather than as syntax. Without this, perfectly ordinary input raises
     * "fts5: syntax error" and the request dies with a 500:
     *   - hyphenated words ("Bewerbungsdossier-Werkstatt", "e-mail")
     *   - the bare operators AND, OR, NOT, NEAR
     * The trailing '*' keeps the prefix match on the last token.
     */
    private function buildFtsQuery(string $clean): string
    {
        $tokens = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);

        $quoted = array_map(
            static fn(string $t): string => '"' . str_replace('"', '""', $t) . '"',
            $tokens
        );

        return implode(' ', $quoted) . '*';
    }
}
