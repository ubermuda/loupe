<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command\Console;

use App\Module\SiteReview\Command\DrainOutboxCommand;
use App\Module\SiteReview\Command\DrainOutboxHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drains the site-review outbox once. The scheduler runs the same work every
 * five minutes through the worker; this is the manual backstop for an instance
 * whose worker has been down, and the seam for inspecting a drain by hand.
 * Safe to run alongside the worker — the claim is atomic.
 */
#[AsCommand(
    name: 'app:drain-site-review-outbox',
    description: 'Publish site-review events whose Mercure update never landed.',
)]
final class DrainSiteReviewOutboxCommand extends Command
{
    public function __construct(
        private readonly DrainOutboxHandler $drainOutbox,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Maximum number of events to claim in this pass.',
            (string) DrainOutboxCommand::DEFAULT_LIMIT,
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = (int) $input->getOption('limit');
        if ($limit < 1) {
            $io->error('The --limit option must be a positive integer.');

            return Command::INVALID;
        }

        $result = ($this->drainOutbox)(new DrainOutboxCommand($limit));

        $counts = sprintf('Published %d, still failing %d.', $result->published, $result->failed);

        if ($result->failed > 0) {
            $io->warning($counts);

            return Command::FAILURE;
        }

        $io->success($counts);

        return Command::SUCCESS;
    }
}
