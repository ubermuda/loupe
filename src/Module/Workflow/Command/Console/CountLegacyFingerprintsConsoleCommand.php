<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command\Console;

use App\Module\Workflow\Command\CountLegacyFingerprintsCommand;
use App\Module\Workflow\Command\CountLegacyFingerprintsHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:workflow:count-legacy-fingerprints',
    description: 'Count the workflow rule states that still hold only the legacy fingerprint.',
)]
final class CountLegacyFingerprintsConsoleCommand extends Command
{
    public function __construct(
        private readonly CountLegacyFingerprintsHandler $countLegacyFingerprints,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = ($this->countLegacyFingerprints)(new CountLegacyFingerprintsCommand());

        new SymfonyStyle($input, $output)->definitionList(
            ['Cards read' => $count->cards],
            ['Rule states with only the legacy fingerprint' => $count->legacyOnly],
            ['Rule states with the provider fingerprint' => $count->current],
            ['Rule states with neither, because the facts changed or the rule is gone' => $count->changed],
        );

        return Command::SUCCESS;
    }
}
