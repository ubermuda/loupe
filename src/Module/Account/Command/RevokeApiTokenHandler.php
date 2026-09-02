<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Event\ApiTokenRevoked;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class RevokeApiTokenHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private EventDispatcherInterface $dispatcher,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(RevokeApiTokenCommand $command): void
    {
        $token = $command->token;

        // Idempotent: a stale double-submit of the revoke form must not re-stamp
        // revokedAt or write a second record.
        if (null !== $token->revokedAt) {
            return;
        }

        $token->revoke();

        // Dispatched before the flush, so a module clearing its own reference to
        // the token is written in the same unit of work as the revocation.
        $this->dispatcher->dispatch(new ApiTokenRevoked($token));

        $this->em->flush();

        // No label: the user types it, so it is their prose about their own
        // systems and has no place in a trail with no erasure path.
        $this->auditor->record(
            'account.api_token.revoked',
            AuditOutcome::Success,
            [
                'userId' => null !== $token->owner->id ? (string) $token->owner->id : null,
                'tokenId' => null !== $token->id ? (string) $token->id : null,
            ],
            null !== $token->id ? new AuditSubject('api_token', (string) $token->id) : null,
            Auditor::CATEGORY_SECURITY,
        );
    }
}
