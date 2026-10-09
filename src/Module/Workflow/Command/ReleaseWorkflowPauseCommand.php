<?php

declare(strict_types=1);

namespace App\Module\Workflow\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\PauseKind;
use Symfony\Component\Uid\Uuid;

/** A person or an agent ends a workflow pause, so the paused rule runs again. A null pause id names the active pause. */
final readonly class ReleaseWorkflowPauseCommand
{
    public const string REASON = 'released-by-person';

    /** A rule pause ends only on its own until, so a person cannot release it. */
    public const array RELEASABLE_KINDS = [PauseKind::Retries, PauseKind::WorkTimeout, PauseKind::WorkStopped, PauseKind::WorkLimit];

    public function __construct(
        public Card $card,
        public User $actor,
        public Actor $actorKind,
        public ?Uuid $pauseId,
    ) {
    }
}
