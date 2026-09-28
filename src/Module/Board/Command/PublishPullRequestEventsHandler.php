<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\BoardEventType;
use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\BoardFixStrategy;
use App\Module\Board\Entity\BoardMergeStrategy;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardAutomationAction;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Repository\CardAutomationRepository;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\FailedCheckNames;
use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\ForgeEventType;
use App\Outbox\OutboxWriter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns a change in the state of a pull request into outbox rows for each card
 * that links it. A fact goes to every card. A decision goes only to a card that
 * is not finished, and only while the automation of the board is on.
 *
 * Forge calls this inside the transaction that stores the new state, so a
 * throw here rolls that state back and the next read diffs again.
 *
 * @phpstan-type Fact array{type: string, fields: array<string, mixed>, resets: bool, fixReason: ?string}
 */
final readonly class PublishPullRequestEventsHandler
{
    /** A bridge quiet for longer is not trusted to hold the session a fix resumes. */
    public const int RESUME_BRIDGE_SECONDS = 300;

    /** The shapes the bridge accepts. It refuses the whole event on one bad field, so a bad value is left out. */
    private const string REPOSITORY_PATTERN = '#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)+$#';
    private const string URL_PATTERN = '#^https://[A-Za-z0-9._~:/?\#\[\]@!$&()*+,;=%-]+$#';
    private const string SHA_PATTERN = '#^[0-9a-f]{7,64}$#';
    private const int MAX_URL_LENGTH = 2000;

    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private CardAutomationRepository $cardAutomations,
        private BoardAutomation $boardAutomation,
        private WorkerRunRepository $workerRuns,
        private BridgeRepository $bridges,
        private OutboxWriter $outbox,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PublishPullRequestEventsCommand $command): void
    {
        $pullRequest = $command->pullRequest;
        $forge = Forge::tryFrom($pullRequest->forge);
        $facts = $this->facts($command);
        $readyToMerge = !$command->previous->readyToMerge && $command->current->readyToMerge;
        if (null === $forge || ([] === $facts && !$readyToMerge)) {
            return;
        }

        $project = $pullRequest->project;
        $links = [];
        foreach ($this->cardPullRequests->findForPullRequest($project->id ?? throw new \LogicException('A stored pull request has a project id.'), $forge, $pullRequest->repository, $pullRequest->number) as $link) {
            // Two spellings of one URL on one card are two links, and the card must hear once.
            $links[(string) $link->card->id] ??= $link;
        }
        if ([] === $links) {
            return;
        }

        $settings = $this->boardAutomation->settingsOf($project);
        // Each change to a row flushes at once, because the next locked read of the row refreshes it and drops unsaved counts.
        $this->em->wrapInTransaction(function () use ($links, $facts, $readyToMerge, $settings, $command): void {
            foreach ($links as $link) {
                $card = $link->card;
                $decides = $settings->enabled && !$card->column->terminal;
                foreach ($facts as $fact) {
                    $this->write($link, $command->current->headSha, $fact['type'], $fact['fields']);
                    if ($fact['resets']) {
                        $this->cardAutomations->reset($card);
                    }
                    if ($decides && null !== $fact['fixReason']) {
                        $this->requestFix($link, $command->current->headSha, $settings, $fact['fixReason']);
                        $this->em->flush();
                    }
                }
                if ($decides && $readyToMerge && BoardMergeStrategy::Worker === $settings->mergeStrategy) {
                    $automation = $this->cardAutomations->findOrCreateForUpdate($card);
                    $automation->lastAction = CardAutomationAction::ReadyToMerge;
                    $automation->lastActionAt = $this->clock->now();
                    $this->write($link, $command->current->headSha, BoardEventType::PULL_REQUEST_READY_TO_MERGE);
                    $this->em->flush();
                }
            }
        });
    }

    /** @return list<Fact> */
    private function facts(PublishPullRequestEventsCommand $command): array
    {
        $previous = $command->previous;
        $current = $command->current;
        $facts = [];

        if ($command->reviewed) {
            $verdict = match ($current->review) {
                PullRequestReview::Approved, PullRequestReview::ChangesRequested => $current->review->value,
                default => null,
            };
            if (null !== $verdict) {
                $facts[] = $this->fact(ForgeEventType::REVIEW_SUBMITTED, ['verdict' => $verdict], true, PullRequestReview::ChangesRequested === $current->review ? 'changes-requested' : null);
            }

            return $facts;
        }

        $concluded = PullRequestChecks::Passed === $current->checks || PullRequestChecks::Failed === $current->checks;
        if ($concluded && ($previous->checks !== $current->checks || $previous->checksSha !== $current->checksSha)) {
            $failed = PullRequestChecks::Failed === $current->checks;
            $facts[] = $this->fact(
                ForgeEventType::CHECKS_CONCLUDED,
                ['conclusion' => $current->checks->value, 'failedChecks' => $failed ? FailedCheckNames::clean($current->failedChecks) : []],
                !$failed,
                $failed ? 'checks-failed' : null,
            );
        }
        if ($previous->mergeability !== $current->mergeability) {
            if (PullRequestMergeability::Conflicting === $current->mergeability) {
                $facts[] = $this->fact(ForgeEventType::CONFLICTED, fixReason: 'conflict');
            }
            if (PullRequestMergeability::Behind === $current->mergeability) {
                $facts[] = $this->fact(ForgeEventType::BEHIND);
            }
        }
        if ($previous->state !== $current->state) {
            if (PullRequestState::Merged === $current->state) {
                $facts[] = $this->fact(ForgeEventType::MERGED);
            }
            if (PullRequestState::Closed === $current->state) {
                $facts[] = $this->fact(ForgeEventType::CLOSED);
            }
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return Fact
     */
    private function fact(string $type, array $fields = [], bool $resets = false, ?string $fixReason = null): array
    {
        return ['type' => $type, 'fields' => $fields, 'resets' => $resets, 'fixReason' => $fixReason];
    }

    private function requestFix(CardPullRequest $link, ?string $headSha, BoardAutomationSettings $settings, string $reason): void
    {
        $card = $link->card;
        $automation = $this->cardAutomations->findOrCreateForUpdate($card);
        $automation->lastActionAt = $this->clock->now();

        if ($automation->fixRounds >= $settings->loopLimit || null !== $automation->blockedReason) {
            $automation->blockedReason ??= $reason;
            $automation->lastAction = CardAutomationAction::Stopped;
            $this->logger->info('board.pull_request_fix_stopped', [
                'cardId' => (string) $card->id,
                'projectId' => (string) $card->project->id,
                'reason' => $reason,
                'blockedReason' => $automation->blockedReason,
                'fixRounds' => $automation->fixRounds,
            ]);

            return;
        }

        ++$automation->fixRounds;
        $automation->lastAction = CardAutomationAction::FixRequested;
        $fields = ['reason' => $reason];
        if (BoardFixStrategy::Resume === $settings->fixStrategy) {
            $fields += $this->resumableSession($card);
        }
        $this->write($link, $headSha, BoardEventType::PULL_REQUEST_FIX_REQUESTED, $fields);
    }

    /**
     * The session and the bridge come together or not at all. Only the named
     * bridge takes the event, so a quiet one would leave the fix with nobody.
     *
     * @return array{sessionId?: string, bridgeId?: string}
     */
    private function resumableSession(Card $card): array
    {
        $run = $this->workerRuns->findLatestSessionOfCard($card->project, $card->id ?? throw new \LogicException('A linked card has an id.'));
        if (null === $run?->sessionId || null === $run->bridgeId) {
            return [];
        }

        $bridge = $this->bridges->findOneByOwnerAndId($card->project->owner, $run->bridgeId);
        $freshSince = $this->clock->now()->modify(\sprintf('-%d seconds', self::RESUME_BRIDGE_SECONDS));
        if (null === $bridge || $bridge->lastSeenAt < $freshSince) {
            return [];
        }

        return ['sessionId' => $run->sessionId->toRfc4122(), 'bridgeId' => $run->bridgeId->toRfc4122()];
    }

    /** @param array<string, mixed> $fields */
    private function write(CardPullRequest $link, ?string $headSha, string $type, array $fields = []): void
    {
        $card = $link->card;
        $project = $card->project;
        $cardId = (string) $card->id;
        $headSha = null === $headSha ? null : mb_strtolower($headSha);

        // Identifiers only, and the actor is `system`: the fact came from the forge and nobody here judged the card.
        $payload = [
            'type' => $type,
            'subject' => ['type' => 'card', 'id' => $cardId],
            'projectId' => (string) $project->id,
            'cardId' => $cardId,
            'cardNumber' => $card->number,
            'forge' => $link->forge->value,
        ];
        if (null !== $link->repository && 1 === preg_match(self::REPOSITORY_PATTERN, $link->repository)) {
            $payload['repository'] = $link->repository;
        }
        $payload['pullRequestNumber'] = $link->number;
        if (\strlen($link->url) <= self::MAX_URL_LENGTH && 1 === preg_match(self::URL_PATTERN, $link->url)) {
            $payload['pullRequestUrl'] = $link->url;
        }
        if (null !== $headSha && 1 === preg_match(self::SHA_PATTERN, $headSha)) {
            $payload['headSha'] = $headSha;
        }
        $payload['actor'] = CardReporter::System->value;

        $this->outbox->write($project, $type, $payload + $fields);
    }
}
