<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardAutomationRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\CardEventCause;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moves a card back to the Backlog, as the system, once every pull request it
 * links is closed and none merged. It runs some minutes after the last close,
 * so it reads everything again under the project lock: a link that is now
 * open, merged or unread, a move to a terminal column, or an open child
 * cancels it.
 * Each close queues a move, and only the newest one acts.
 */
final readonly class ReturnAbandonedCardHandler
{
    public function __construct(
        private CardRepository $cards,
        private CardPullRequestRepository $cardPullRequests,
        private BoardColumnRepository $boardColumns,
        private BoardAutomation $boardAutomation,
        private ForgePullRequestRepository $forgePullRequests,
        private EntityManagerInterface $em,
        private UpdateCardHandler $updateCard,
        private CardAutomationRepository $cardAutomations,
    ) {
    }

    public function __invoke(ReturnAbandonedCardCommand $command): void
    {
        $card = $this->cards->find($command->cardId);
        if (null === $card) {
            return;
        }

        $this->em->wrapInTransaction(function () use ($card, $command): void {
            $project = $card->project;
            $projectId = $project->id ?? throw new \LogicException('A stored card has a project id.');

            // Empty also when the card was deleted.
            $links = $this->cardPullRequests->findCurrentKeys($card);
            if ([] === $links) {
                return;
            }
            $keys = [];
            foreach ($links as $link) {
                if (null === $link['repository'] || null === $link['number']) {
                    return;
                }
                $keys[] = ['forge' => $link['forge'], 'repository' => $link['repository'], 'number' => $link['number']];
            }
            // A Forge read locks its row, then the project. Taking both in that order waits
            // out a read that reopens a pull request, and reads the state it committed.
            $states = $this->forgePullRequests->findCurrentStatesByKeys($projectId, $keys, forUpdate: true);
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
            if (!$this->boardAutomation->settingsOf($project)->enabled) {
                return;
            }

            $this->cards->refreshColumn($card);
            $this->boardColumns->findForProjectFresh($project);
            if ($card->column->terminal || $card->column->backlog) {
                return;
            }
            // A link added or removed while this waited was read with no lock.
            if ($this->cardPullRequests->findCurrentKeys($card) !== $links) {
                return;
            }

            $automation = $this->cardAutomations->findOrCreateForUpdate($card);
            if (!$command->token->equals($automation->abandonedMoveToken)) {
                return;
            }
            // Consumed now, so a redelivered message cannot undo a later move by a person.
            $automation->abandonedMoveToken = null;
            foreach ($keys as $key) {
                $state = $states[ForgePullRequestRepository::stateKey($key['forge'], $key['repository'], $key['number'])] ?? null;
                if (PullRequestState::Closed !== $state) {
                    return;
                }
            }

            // The system skips the open-child refusal, so this check stands in.
            if ([] !== $this->cards->openChildNumbers($card)) {
                return;
            }

            $backlog = $this->boardColumns->findBacklogForProjectId((string) $projectId);
            if (null === $backlog) {
                return;
            }

            ($this->updateCard)(new UpdateCardCommand(card: $card, actor: CardReporter::System, column: $backlog, onlyFromColumn: $card->column, cause: CardEventCause::abandoned()));
        });
    }
}
