<?php

declare(strict_types=1);

namespace App\Module\OAuth\Scheduler;

use App\Module\OAuth\Command\PurgeExpiredOAuthTokensCommand;
use App\Module\OAuth\Command\PurgeExpiredOAuthTokensHandler;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

#[AsCronTask('17 * * * *')]
final readonly class PurgeExpiredOAuthTokensTask
{
    public function __construct(
        private PurgeExpiredOAuthTokensHandler $purge,
    ) {
    }

    public function __invoke(): void
    {
        ($this->purge)(new PurgeExpiredOAuthTokensCommand());
    }
}
