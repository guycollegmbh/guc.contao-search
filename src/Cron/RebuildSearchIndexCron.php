<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Guc\SearchBundle\Indexer\IndexerInterface;
use Psr\Log\LoggerInterface;

#[AsCronJob('daily')]
class RebuildSearchIndexCron
{
    /** @param IndexerInterface[] $indexers */
    public function __construct(
        private readonly iterable $indexers,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function __invoke(): void
    {
        foreach ($this->indexers as $indexer) {
            try {
                $count = $indexer->index();
                $this->logger?->info(sprintf('GUC Search: Indexed %d %s records', $count, $indexer->getType()));
            } catch (\Throwable $e) {
                $this->logger?->error(sprintf('GUC Search: Failed to index %s - %s', $indexer->getType(), $e->getMessage()));
            }
        }
    }
}
