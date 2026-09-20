<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command\Console;

use App\Module\OAuth\Command\PurgeExpiredOAuthTokensCommand;
use App\Module\OAuth\Command\PurgeExpiredOAuthTokensHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The manual backstop for the hourly purge. Use it instead of the bundle's
 * league:oauth2-server:clear-expired-tokens, which unlinks live refresh tokens
 * from their user.
 */
#[AsCommand(
    name: 'app:oauth:purge-expired-tokens',
    description: 'Delete expired OAuth tokens and authorization codes.',
)]
final class PurgeOAuthTokensConsoleCommand extends Command
{
    public function __construct(
        private readonly PurgeExpiredOAuthTokensHandler $purgeExpiredOAuthTokens,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $purged = ($this->purgeExpiredOAuthTokens)(new PurgeExpiredOAuthTokensCommand());

        new SymfonyStyle($input, $output)->success(\sprintf('Purged %d expired OAuth row(s).', $purged));

        return Command::SUCCESS;
    }
}
