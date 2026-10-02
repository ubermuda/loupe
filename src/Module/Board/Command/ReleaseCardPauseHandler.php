<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Event\CardChanged;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Lifts a card pause. Answers false when the pause was already released. */
final readonly class ReleaseCardPauseHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(ReleaseCardPauseCommand $command): bool
    {
        $pause = $command->pause;

        $released = $this->em->wrapInTransaction(function () use ($pause, $command): bool {
            // Locked and read fresh, so of two releases only the first one counts.
            $this->em->refresh($pause, LockMode::PESSIMISTIC_WRITE);
            if (!$pause->release($command->reason, $this->clock->now())) {
                return false;
            }
            $this->em->flush();

            return true;
        });
        if (!$released) {
            return false;
        }

        $cardId = $pause->card->id ?? throw new \LogicException('A persisted card has an id.');
        $projectId = $pause->project->id ?? throw new \LogicException('A persisted project has an id.');
        $this->auditor->record(
            'board.card_pause_released',
            AuditOutcome::Success,
            [
                'pauseId' => (string) $pause->id,
                'cardId' => (string) $cardId,
                'projectId' => (string) $projectId,
                'reason' => $pause->releaseReason,
                'kind' => $pause->kind->value,
            ],
            new AuditSubject('card', (string) $cardId),
        );
        $this->events->dispatch(new CardChanged($projectId, $cardId, CardChanged::UPDATED, false));

        return true;
    }
}
