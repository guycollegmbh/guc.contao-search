<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Indexer;

use Doctrine\DBAL\Connection;
use Guc\SearchBundle\Repository\SearchRepository;

class FileIndexer implements IndexerInterface
{
    private array $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];

    public function __construct(
        private readonly Connection $db,
        private readonly SearchRepository $searchRepository,
    ) {}

    public function getType(): string
    {
        return 'file';
    }

    public function index(): int
    {
        $placeholders = implode(',', array_fill(0, count($this->allowedExtensions), '?'));

        $files = $this->db->fetchAllAssociative(
            "SELECT id, name, path, meta FROM tl_files WHERE type = 'file' AND extension IN ($placeholders)",
            $this->allowedExtensions
        );

        $count = 0;
        $allText = [];
        $this->searchRepository->beginTransaction();
        try {
            $this->searchRepository->clearType('file');

            foreach ($files as $file) {
                $meta = !empty($file['meta']) ? (unserialize($file['meta'], ['allowed_classes' => false]) ?: []) : [];
                $title = $this->extractMetaTitle($meta, $file['name']);
                $body = $this->extractMetaDescription($meta);

                $this->searchRepository->insert([
                    'id'       => 'file_' . $file['id'],
                    'type'     => 'file',
                    'language' => '',
                    'title'    => $title,
                    'body'     => $body,
                    'url'      => '/' . $file['path'],
                    'badge'    => strtoupper(pathinfo($file['path'], PATHINFO_EXTENSION)),
                ]);
                $count++;
                $allText[] = $title . ' ' . $body;
            }

            $this->searchRepository->upsertWords(SearchRepository::extractWords(implode(' ', $allText)));
            $this->searchRepository->setMeta('last_index_file', date('Y-m-d H:i:s'));
            $this->searchRepository->commit();
        } catch (\Throwable $e) {
            $this->searchRepository->rollback();
            throw $e;
        }

        return $count;
    }

    private function extractMetaTitle(array $meta, string $fallback): string
    {
        foreach ($meta as $lang => $data) {
            if (!empty($data['title'])) {
                return $data['title'];
            }
        }
        return pathinfo($fallback, PATHINFO_FILENAME);
    }

    private function extractMetaDescription(array $meta): string
    {
        foreach ($meta as $lang => $data) {
            if (!empty($data['caption'])) {
                return $data['caption'];
            }
        }
        return '';
    }
}
