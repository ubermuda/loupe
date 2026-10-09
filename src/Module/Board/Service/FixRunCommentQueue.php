<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Messenger\PostFixRunComment;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\PullRequestCommentRepository;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Forge\Service\PullRequestCommenters;
use App\Module\Workflow\Contract\RuleBudgets;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Stores and queues one comment for each open fix run of a card. The run fixes a pull request of its card:
 * the link its work request names, by URL and then by a number that one link alone holds. A request that
 * names no link of the card falls back to the first open one the forge tracks, else the first one the card links.
 */
final readonly class FixRunCommentQueue
{
    private const string FIX_KIND = 'fix';

    public function __construct(
        private PullRequestCommenters $commenters,
        private PullRequestCommentRepository $pullRequestComments,
        private CardPullRequestRepository $cardPullRequests,
        private ForgePullRequestRepository $forgePullRequests,
        private WorkRequestRepository $workRequests,
        private RuleBudgets $ruleBudgets,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * A card that links no pull request on a forge with a commenter has none, so no rule waits for a comment that cannot post.
     *
     * @return list<WorkerRun> the open fix runs of the card with no comment, by id
     */
    public function uncommentedRuns(Card $card): array
    {
        if ([] === $this->commentableKeys($card)) {
            return [];
        }

        return $this->pullRequestComments->findUncommentedOpenRuns($card->id ?? throw new \LogicException('A stored card has an id.'), self::FIX_KIND);
    }

    /** Answers whether it stored a comment. */
    public function queue(Card $card): bool
    {
        $keys = $this->commentableKeys($card);
        if ([] === $keys) {
            return false;
        }
        $projectId = $card->project->id ?? throw new \LogicException('A stored card has a project id.');
        $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');
        $runs = $this->pullRequestComments->findUncommentedOpenRuns($cardId, self::FIX_KIND);
        if ([] === $runs) {
            return false;
        }

        $rows = $this->forgePullRequests->findByKeys($projectId, $keys);
        $queued = false;
        foreach ($runs as $run) {
            $queued = $this->queueRun($run, $keys, $rows) || $queued;
        }

        return $queued;
    }

    /**
     * @param non-empty-list<array{forge: string, repository: string, number: int, url: string}> $keys
     * @param list<ForgePullRequest>                                                             $rows
     */
    private function queueRun(WorkerRun $run, array $keys, array $rows): bool
    {
        $projectId = $run->project->id ?? throw new \LogicException('A persisted project has an id.');
        $runId = $run->id ?? throw new \LogicException('A persisted run has an id.');
        $cardId = $run->subjectId;
        $request = null === $run->workRequestId ? null : $this->workRequests->findOneOfSubject($run->workRequestId, $run->project, $run->subject());
        $named = self::named($keys, $request?->context->pullRequestNumber, $request?->context->pullRequestUrl);
        $key = $named ?? $keys[0];
        $tracked = null === $named ? self::firstOpen($keys, $rows) : array_find($rows, static fn (ForgePullRequest $row): bool => self::matches($row, $named));
        $forge = $tracked->forge ?? $key['forge'];
        $repository = $tracked->repository ?? $key['repository'];
        $number = $tracked->number ?? $key['number'];
        $headSha = $tracked?->headSha;
        $reason = null === $tracked ? null : self::reasonOf($tracked);
        $ruleId = $request->ruleId ?? $run->ruleId;
        $fires = null === $ruleId ? null : $this->ruleBudgets->fires($cardId, $ruleId);
        // A rule whose count started again names no round.
        $fixRound = null !== $fires && $fires > 0 ? $fires : null;

        // Forge keeps its row through a repository rename, so the post finds the pull request by id.
        $forgePullRequestId = $tracked?->id;

        // One transaction, so a message that fails to queue leaves no pending row behind.
        // It is a DBAL one, so it nests as a savepoint in the transaction of the evaluation.
        $commentId = $this->em->getConnection()->transactional(function () use ($projectId, $runId, $cardId, $forge, $repository, $number, $headSha, $reason, $fixRound, $forgePullRequestId) {
            $commentId = $this->pullRequestComments->insertIfMissing(
                $projectId,
                $runId,
                $cardId,
                $forge,
                $repository,
                $number,
                $headSha,
                $reason,
                $fixRound,
                $forgePullRequestId,
                $this->clock->now(),
            );
            if (null !== $commentId) {
                $this->bus->dispatch(new PostFixRunComment($commentId));
            }

            return $commentId;
        });
        if (null === $commentId) {
            return false;
        }

        $this->logger->info('board.fix_run_comment_queued', [
            'commentId' => (string) $commentId,
            'projectId' => (string) $projectId,
            'cardId' => (string) $cardId,
            'runId' => (string) $runId,
            'forge' => $forge,
            'repository' => $repository,
            'pullRequestNumber' => $number,
            'fixRound' => $fixRound,
        ]);

        return true;
    }

    /** @return list<array{forge: string, repository: string, number: int, url: string}> */
    private function commentableKeys(Card $card): array
    {
        return array_values(array_filter(
            $this->cardPullRequests->findNumberedKeysOfCard(
                $card->project->id ?? throw new \LogicException('A stored card has a project id.'),
                $card->id ?? throw new \LogicException('A stored card has an id.'),
            ),
            fn (array $key): bool => null !== $this->commenters->for($key['forge']),
        ));
    }

    /**
     * @param non-empty-list<array{forge: string, repository: string, number: int, url: string}> $keys
     *
     * @return array{forge: string, repository: string, number: int, url: string}|null
     */
    private static function named(array $keys, ?int $pullRequestNumber, ?string $pullRequestUrl): ?array
    {
        $url = null === $pullRequestUrl ? null : mb_strtolower($pullRequestUrl);
        $byUrl = null === $url ? null : array_find($keys, static fn (array $key): bool => mb_strtolower($key['url']) === $url);
        if (null !== $byUrl || null === $pullRequestNumber) {
            return $byUrl;
        }
        // Two repositories can share a number, so only a single match names the link.
        $byNumber = array_values(array_filter($keys, static fn (array $key): bool => $key['number'] === $pullRequestNumber));

        return 1 === \count($byNumber) ? $byNumber[0] : null;
    }

    /**
     * @param non-empty-list<array{forge: string, repository: string, number: int, url: string}> $keys
     * @param list<ForgePullRequest>                                                             $rows
     */
    private static function firstOpen(array $keys, array $rows): ?ForgePullRequest
    {
        foreach ($keys as $key) {
            foreach ($rows as $row) {
                if (PullRequestState::Open === $row->state && self::matches($row, $key)) {
                    return $row;
                }
            }
        }

        return null;
    }

    /** @param array{forge: string, repository: string, number: int, url: string} $key */
    private static function matches(ForgePullRequest $row, array $key): bool
    {
        return $row->forge === $key['forge'] && mb_strtolower($row->repository) === mb_strtolower($key['repository']) && $row->number === $key['number'];
    }

    /** The first reason a fix is due, in the order the board asks for fixes. */
    private static function reasonOf(ForgePullRequest $pullRequest): ?string
    {
        return match (true) {
            PullRequestMergeability::Conflicting === $pullRequest->mergeability => 'conflict',
            PullRequestChecks::Failed === $pullRequest->checks => 'checks-failed',
            PullRequestReview::ChangesRequested === $pullRequest->review => 'changes-requested',
            default => null,
        };
    }
}
