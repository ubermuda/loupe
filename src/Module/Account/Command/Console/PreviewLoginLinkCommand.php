<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Console;

use App\Exception\DomainErrors;
use App\Module\Account\Command\BuildPreviewLoginLinkCommand;
use App\Module\Account\Command\BuildPreviewLoginLinkHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;

#[AsCommand(
    name: 'app:dev:preview-login-link',
    description: 'Print a signed link that signs a reader in and lands them on one page',
)]
#[When('dev')]
final class PreviewLoginLinkCommand extends Command
{
    public function __construct(
        private readonly BuildPreviewLoginLinkHandler $buildPreviewLoginLink,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Seeded account to sign in as', 'dev@loupe.test')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Absolute path to land on', '/');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $view = ($this->buildPreviewLoginLink)(new BuildPreviewLoginLinkCommand(
                email: (string) $input->getOption('email'),
                path: (string) $input->getOption('path'),
            ));
        } catch (DomainErrors $e) {
            foreach ($e->errors as $field => $translationKey) {
                $io->error($field.': '.$translationKey);
            }

            return Command::FAILURE;
        }

        $output->writeln($view->url);

        return Command::SUCCESS;
    }
}
