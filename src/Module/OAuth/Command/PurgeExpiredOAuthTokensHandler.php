<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\Repository\GrantRepository;
use Psr\Log\LoggerInterface;

final readonly class PurgeExpiredOAuthTokensHandler
{
    public function __construct(
        private GrantRepository $grants,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PurgeExpiredOAuthTokensCommand $command): int
    {
        $deleted = $this->grants->deleteExpired();
        $this->logger->info('oauth.expired_tokens_purged', ['rows' => $deleted]);

        return $deleted;
    }
}
