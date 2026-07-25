<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\DataExport\UserDataExporterInterface;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ApiTokenRepository;

final readonly class ApiTokenExporter implements UserDataExporterInterface
{
    public function __construct(private ApiTokenRepository $apiTokens)
    {
    }

    #[\Override]
    public function filename(): string
    {
        return 'api_tokens.json';
    }

    #[\Override]
    public function export(User $user): array
    {
        $rows = [];
        foreach ($this->apiTokens->findBy(['owner' => $user]) as $token) {
            $rows[] = [
                'label' => $token->label,
                'scope' => $token->scope->value,
                'createdAt' => $token->createdAt->format(\DateTimeInterface::ATOM),
                'lastUsedAt' => $token->lastUsedAt?->format(\DateTimeInterface::ATOM),
            ];
        }

        return $rows;
    }
}
