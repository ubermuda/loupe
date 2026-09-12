<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\ApiToken;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class MintApiTokenHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    /**
     * @return non-empty-string the raw token, shown to the owner exactly once
     */
    public function __invoke(MintApiTokenCommand $command): string
    {
        [$token, $raw] = ApiToken::issue($command->owner, $command->label, $command->scope);
        $this->em->persist($token);
        $this->em->flush();

        // Recorded after the commit, so no record can claim a token nobody holds.
        // No label: the owner types it, so it is their prose about their own
        // systems and has no place in a trail with no erasure path.
        $this->auditor->record(
            'account.api_token_minted',
            AuditOutcome::Success,
            [
                'userId' => null !== $command->owner->id ? (string) $command->owner->id : null,
                'tokenId' => (string) $token->id,
                'scope' => $command->scope->value,
            ],
            new AuditSubject('api_token', (string) $token->id),
            Auditor::CATEGORY_SECURITY,
        );

        return $raw;
    }
}
