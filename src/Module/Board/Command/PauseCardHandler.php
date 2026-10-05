<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPauseRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Pauses a card. Answers null when the card already holds an active pause.
 * The workflow engine calls it inside its own transaction, so a malformed
 * code is a LogicException rather than a form error.
 */
final readonly class PauseCardHandler
{
    public function __construct(
        private CardPauseRepository $cardPauses,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
        private CardEventRepository $cardEvents,
    ) {
    }

    public function __invoke(PauseCardCommand $command): ?CardPause
    {
        $card = $command->card;
        $pause = new CardPause($card, $card->project, $command->reason, $command->ruleId, $command->kind, $this->clock->now());

        // The card lock serialises two pauses, so the second reads the first
        // instead of tripping the unique index, which would close the entity manager.
        $paused = $this->em->wrapInTransaction(function () use ($card, $pause): bool {
            $this->em->lock($card, LockMode::PESSIMISTIC_WRITE);
            if (null !== $this->cardPauses->findActiveForCard($card)) {
                return false;
            }
            $this->em->persist($pause);
            $this->cardEvents->record($card, CardEventKind::Paused, CardReporter::System, null, [
                'kind' => $pause->kind->value,
                'reason' => $pause->reason,
                'ruleId' => $pause->ruleId,
            ], $pause->createdAt);
            $this->em->flush();

            return true;
        });
        if (!$paused) {
            return null;
        }

        $cardId = $card->id ?? throw new \LogicException('A persisted card has an id.');
        $projectId = $card->project->id ?? throw new \LogicException('A persisted project has an id.');
        $this->auditor->record(
            'board.card_paused',
            AuditOutcome::Success,
            [
                'pauseId' => (string) $pause->id,
                'cardId' => (string) $cardId,
                'projectId' => (string) $projectId,
                'reason' => $pause->reason,
                'ruleId' => $pause->ruleId,
                'kind' => $pause->kind->value,
            ],
            new AuditSubject('card', (string) $cardId),
        );
        $this->events->dispatch(new CardChanged($projectId, $cardId, CardChanged::UPDATED, false));

        return $pause;
    }
}
