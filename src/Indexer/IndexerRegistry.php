<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Indexer;

/**
 * Holds all tagged indexers as a public service so SearchIndexModule
 * can retrieve them via System::getContainer()->get(IndexerRegistry::class).
 */
class IndexerRegistry implements \IteratorAggregate
{
    /** @param IndexerInterface[] $indexers */
    public function __construct(private readonly iterable $indexers) {}

    public function getIterator(): \Traversable
    {
        yield from $this->indexers;
    }
}
