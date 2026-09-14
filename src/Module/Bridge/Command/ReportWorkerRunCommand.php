<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use Symfony\Component\Uid\Uuid;

final readonly class ReportWorkerRunCommand
{
    public function __construct(
        public User $owner,
        /** A project id or slug. A project name does not resolve. */
        public string $handle,
        public Uuid $bridgeId,
        public Uuid $cardId,
        public int $cardNumber,
        public string $ruleName,
        public \DateTimeImmutable $startedAt,
        public \DateTimeImmutable $endedAt,
        public ?int $exitCode,
        public ?string $failureReason,
        public string $output,
    ) {
    }
}
