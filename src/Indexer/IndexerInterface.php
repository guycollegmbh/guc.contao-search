<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Indexer;

interface IndexerInterface
{
    public function getType(): string;

    public function index(): int;
}
