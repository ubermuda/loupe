<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Marks each unfinished card that links the pull request as synced. Forge calls
 * this inside the transaction of its read, after the project lock.
 */
final readonly class RecordPullRequestSyncHandler
{
    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private CardEventRepository $cardEvents,
        private EntityManagerInterface $em,
        private EventDispatcherInterface $events,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecordPullRequestSyncCommand $command): void
    {
        $pullRequest = $command->pullRequest;
        $forge = Forge::tryFrom($pullRequest->forge);
        $projectId = $pullRequest->project->id ?? throw new \LogicException('A stored pull request has a project id.');
        $this->logger->info('board.pull_request_synced', [
            'pullRequestId' => (string) $pullRequest->id,
            'projectId' => (string) $projectId,
            'forge' => $pullRequest->forge,
            'repository' => $pullRequest->repository,
            'pullRequestNumber' => $pullRequest->number,
            'headSha' => $pullRequest->headSha,
        ]);
        if (null === $forge) {
            return;
        }

        /** @var array<string, Card> $cards */
        $cards = [];
        foreach ($this->cardPullRequests->findForPullRequest($projectId, $forge, $pullRequest->repository, $pullRequest->number) as $link) {
            if (!$link->card->column->terminal) {
                $cards[(string) $link->card->id] ??= $link->card;
            }
        }
        if ([] === $cards) {
            return;
        }

        $now = $this->clock->now();
        $this->em->wrapInTransaction(function () use ($cards, $pullRequest, $now): void {
            foreach ($cards as $card) {
                $this->cardEvents->record($card, CardEventKind::Synced, CardReporter::System, null, ['pullRequest' => $pullRequest->number], $now);
                $this->em->flush();
            }
        });

        // Inside the transaction of Forge, so `updated` alone: a rollback costs a page one needless refetch.
        foreach ($cards as $card) {
            $this->events->dispatch(new CardChanged(
                $projectId,
                $card->id ?? throw new \LogicException('A linked card has an id.'),
                CardChanged::UPDATED,
                false,
            ));
        }
    }
}
