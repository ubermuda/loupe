<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Command\ReleaseWorkflowPauseCommand;
use App\Module\Workflow\Command\ReleaseWorkflowPauseHandler;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\PauseReleaseHandler;
use App\Module\Workflow\Contract\PauseView;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/** Lets another module end a workflow pause without importing the command and its handler. */
#[AsAlias(PauseReleaseHandler::class)]
final readonly class WorkflowPauseReleaseHandler implements PauseReleaseHandler
{
    public function __construct(
        private ReleaseWorkflowPauseHandler $release,
    ) {
    }

    #[\Override]
    public function release(CardSnapshot $card, Uuid $actorUserId, Actor $actor, ?Uuid $pauseId): PauseView
    {
        return ($this->release)(new ReleaseWorkflowPauseCommand($card, $actorUserId, $actor, $pauseId));
    }
}
