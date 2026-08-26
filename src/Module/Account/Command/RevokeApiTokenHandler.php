<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Event\ApiTokenRevoked;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[WithMonologChannel('app_security')]
final readonly class RevokeApiTokenHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private EventDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RevokeApiTokenCommand $command): void
    {
        $token = $command->token;

        // Idempotent: a stale double-submit of the revoke form must not re-stamp
        // revokedAt or emit a second log entry.
        if (null !== $token->revokedAt) {
            return;
        }

        $token->revoke();

        // Dispatched before the flush, so a module clearing its own reference to
        // the token is written in the same unit of work as the revocation.
        $this->dispatcher->dispatch(new ApiTokenRevoked($token));

        $this->em->flush();

        $this->logger->info('account.api_token.revoked', [
            'userId' => null !== $token->owner->id ? (string) $token->owner->id : null,
            'tokenId' => null !== $token->id ? (string) $token->id : null,
            'label' => $token->label,
        ]);
    }
}
