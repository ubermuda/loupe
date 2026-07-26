<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class RevokeApiTokenHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RevokeApiTokenCommand $command): void
    {
        $token = $command->token;

        $this->em->remove($token);
        $this->em->flush();

        $this->logger->info('account.api_token.revoked', [
            'userId' => null !== $token->owner->id ? (string) $token->owner->id : null,
            'tokenId' => null !== $token->id ? (string) $token->id : null,
            'label' => $token->label,
        ]);
    }
}
