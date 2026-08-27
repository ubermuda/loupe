<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Account\Repository\ApiTokenRepository;

final readonly class ApiTokenExporter implements UserDataExporterInterface
{
    public function __construct(
        private ApiTokenRepository $apiTokens,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'api_tokens.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->apiTokens->findBy(['owner' => $user]) as $token) {
            yield [
                'label' => $token->label,
                'scope' => $token->scope->value,
                'forwardsToAgent' => $token->forwardsToAgent,
                'createdAt' => $token->createdAt->format(\DateTimeInterface::ATOM),
                'lastUsedAt' => $token->lastUsedAt?->format(\DateTimeInterface::ATOM),
                'revokedAt' => $token->revokedAt?->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
