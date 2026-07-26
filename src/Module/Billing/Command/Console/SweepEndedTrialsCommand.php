<?php

declare(strict_types=1);

namespace App\Module\Billing\Command\Console;

use App\Module\Billing\Service\TrialEndSweeper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the trial-end sweep once. The scheduler fires it hourly through the
 * worker; this command is the manual backstop and the dev/e2e seam. Safe to
 * run any time — every action is marker-guarded.
 */
#[AsCommand(
    name: 'app:sweep-ended-trials',
    description: 'Disable ended trials/cancellations and send survey emails. Safe for cron.',
)]
final class SweepEndedTrialsCommand extends Command
{
    public function __construct(
        private readonly TrialEndSweeper $sweeper,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->sweeper->sweep();

        $io->success(sprintf(
            'Disabled %d, churned surveys %d, subscriber surveys %d, cancel surveys %d, failed %d.',
            $result->disabled,
            $result->churnedSurveys,
            $result->subscriberSurveys,
            $result->cancelSurveys,
            $result->failed,
        ));

        return $result->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
