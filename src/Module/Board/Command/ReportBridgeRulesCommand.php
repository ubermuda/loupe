<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Account\Entity\User;
use Symfony\Component\Uid\Uuid;

final readonly class ReportBridgeRulesCommand
{
    public function __construct(
        public User $owner,
        /** A project id or slug. A project name does not resolve. */
        public string $handle,
        public Uuid $bridgeId,
        /** @var list<array{name: string, on: string, columns: list<string>, state: string, reason: ?string}> */
        public array $rules,
    ) {
    }
}
