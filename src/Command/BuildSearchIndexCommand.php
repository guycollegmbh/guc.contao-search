<?php

declare(strict_types=1);

namespace Guc\SearchBundle\Command;

use Guc\SearchBundle\Indexer\IndexerInterface;
use Guc\SearchBundle\Repository\SearchRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'guc:search:index',
    description: 'Build the search index for the GUC frontend search',
)]
class BuildSearchIndexCommand extends Command
{
    /** @param IndexerInterface[] $indexers */
    public function __construct(
        private readonly iterable $indexers,
        private readonly SearchRepository $searchRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('type', 't', InputOption::VALUE_OPTIONAL, 'Only index a specific type (page, file, news, event, member, faq)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('GUC Search Index Builder');

        $typeFilter = $input->getOption('type');

        // Full rebuild: clear the word dictionary first so stale words are removed
        if (!$typeFilter) {
            $this->searchRepository->clearWords();
        }

        foreach ($this->indexers as $indexer) {
            if ($typeFilter && $indexer->getType() !== $typeFilter) {
                continue;
            }

            $io->write(sprintf('Indexing <info>%s</info>... ', $indexer->getType()));
            $count = $indexer->index();
            $io->writeln(sprintf('<comment>%d</comment> records', $count));
        }

        $io->success('Search index built successfully.');

        return Command::SUCCESS;
    }
}
