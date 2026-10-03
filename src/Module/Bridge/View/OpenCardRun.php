<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use Symfony\Component\Uid\Uuid;

/** The open run that best says what happens on a card now. */
final readonly class OpenCardRun
{
    public function __construct(
        public Uuid $cardId,
        public WorkerRunState $state,
        public WorkerRunKind $kind,
        public ?string $workKind,
        /** When the run started, or when it began to wait for a start. */
        public \DateTimeImmutable $since,
    ) {
    }
}
