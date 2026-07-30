<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Indexer;

use Doctrine\DBAL\Connection;
use Guc\SearchBundle\Repository\SearchRepository;
use Psr\Log\LoggerInterface;

class MemberIndexer implements IndexerInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly SearchRepository $searchRepository,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function getType(): string
    {
        return 'member';
    }

    public function index(): int
    {
        $now = time();
        try {
            $members = $this->db->fetchAllAssociative("
                SELECT id, firstname, lastname, company
                FROM tl_member
                WHERE disable = ''
                AND (start = '' OR start <= :now)
                AND (stop = '' OR stop > :now)
            ", ['now' => $now]);
        } catch (\Exception $e) {
            $this->logger?->warning('GUC Search: MemberIndexer failed - ' . $e->getMessage());
            return 0;
        }

        if (empty($members)) {
            return 0;
        }

        // Build page tree for URL suffix and language resolution before clearing the index
        $allPages = $this->db->fetchAllAssociative(
            "SELECT id, pid, type, alias, language, urlSuffix FROM tl_page"
        );
        $pageMap = array_column($allPages, null, 'id');

        $languageMap = [];
        $suffixMap = [];
        foreach ($pageMap as $p) {
            if ($p['type'] === 'root') {
                $languageMap[$p['id']] = $p['language'];
                $suffixMap[$p['id']] = $p['urlSuffix'] ?? '';
            }
        }

        // Null sentinel breaks potential circular pid references
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

        $memberPage = $this->findMemberPage();
        $memberPageId = $memberPage ? (int) $memberPage['id'] : 0;
        $memberPageAlias = $memberPage ? $memberPage['alias'] : 'team';
        $language = $memberPageId ? $resolveLanguage($memberPageId) : '';
        $suffix = $memberPageId ? $resolveSuffix($memberPageId) : '';

        $count = 0;
        $this->searchRepository->beginTransaction();
        try {
            $this->searchRepository->clearType('member');

            foreach ($members as $member) {
                $title = trim(($member['firstname'] ?? '') . ' ' . ($member['lastname'] ?? ''));
                if (empty($title)) {
                    continue;
                }

                $this->searchRepository->insert([
                    'id'       => 'member_' . $member['id'],
                    'type'     => 'member',
                    'language' => $language,
                    'title'    => $title,
                    'body'     => strip_tags($member['company'] ?? ''),
                    'url'      => '/' . $memberPageAlias . $suffix,
                    'badge'    => 'Team',
                ]);
                $count++;
            }

            $this->searchRepository->setMeta('last_index_member', date('Y-m-d H:i:s'));
            $this->searchRepository->commit();
        } catch (\Throwable $e) {
            $this->searchRepository->rollback();
            throw $e;
        }

        return $count;
    }

    private function findMemberPage(): ?array
    {
        // Page with a Contao module that renders members (memberList, listing, mm_*, etc.)
        try {
            $row = $this->db->fetchAssociative("
                SELECT p.id, p.alias
                FROM tl_page p
                JOIN tl_article a ON a.pid = p.id AND a.published = '1'
                JOIN tl_content c ON c.pid = a.id AND c.ptable = 'tl_article'
                    AND c.invisible = '' AND c.type = 'module'
                JOIN tl_module m ON m.id = c.module
                WHERE p.published = '1' AND p.type = 'regular'
                AND (m.type LIKE 'member%' OR m.type LIKE 'mm_%'
                     OR m.type = 'listing' OR m.type LIKE '%team%')
                LIMIT 1
            ");
            if ($row) {
                return $row;
            }
        } catch (\Exception) {}

        // Fallback: page with 'team' or 'mitglieder' in alias
        try {
            $row = $this->db->fetchAssociative("
                SELECT id, alias FROM tl_page
                WHERE published = '1' AND type = 'regular'
                AND (alias LIKE '%team%' OR alias LIKE '%mitglieder%' OR alias LIKE '%personal%')
                ORDER BY id ASC
                LIMIT 1
            ");
            if ($row) {
                return $row;
            }
        } catch (\Exception) {}

        return null;
    }
}
