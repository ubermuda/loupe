<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use App\Module\Bridge\Entity\BridgeCommand;

/**
 * The one action a worker run row offers, and the notice beside it. No state
 * is both resumable and stoppable, so one action per row is enough.
 */
final readonly class WorkerRunControl
{
    /** False when the row shows the action and the bridge cannot take it now. */
    public bool $enabled;

    /**
     * @param array<string, string> $disabledParameters
     * @param array<string, string> $labelParameters
     */
    public function __construct(
        public ?WorkerRunAction $action,
        public ?string $disabledReason = null,
        public array $disabledParameters = [],
        public ?string $label = null,
        public array $labelParameters = [],
        public bool $labelWarns = false,
        /** The command that waits on the run, when the action is a cancel. */
        public ?BridgeCommand $pendingCommand = null,
    ) {
        $this->enabled = null !== $action && null === $disabledReason;
    }
}
