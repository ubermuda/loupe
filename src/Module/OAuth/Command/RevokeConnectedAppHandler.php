<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\Repository\GrantRepository;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Revokes every token a client holds for the user, so the client must ask again. */
final readonly class RevokeConnectedAppHandler
{
    public function __construct(
        private GrantRepository $grants,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(RevokeConnectedAppCommand $command): void
    {
        $userId = $command->user->id ?? throw new \LogicException('a persisted user always has an id');
        $revoked = $this->grants->revokeForUserAndClient($userId, $command->clientId);

        $this->auditor->record(
            'oauth.client_revoked',
            AuditOutcome::Success,
            ['clientId' => $command->clientId, 'accessTokens' => $revoked],
            new AuditSubject('oauth_client', $command->clientId),
        );
    }
}
