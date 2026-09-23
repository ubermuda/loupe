<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\ValueObject\WorkerRunState;
use Symfony\Component\Validator\Constraints as Assert;

/** One run a bridge holds. The bridge holds a run only while it is open. */
final class BridgeRunInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $runId = null,

        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $projectId = null,

        #[Assert\Choice(callback: 'openStates')]
        #[Assert\NotBlank]
        public ?string $state = null,
    ) {
    }

    /** @return list<string> */
    public static function openStates(): array
    {
        return array_map(static fn (WorkerRunState $state): string => $state->value, WorkerRunState::openStates());
    }
}
