<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\SiteReviewCheckState;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\SiteReviewCheckStateRepository;
use App\Module\Board\Workflow\CheckWanted;
use App\Module\Board\Workflow\SiteReviewFactProvider;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Service\PullRequestCheckConclusion;
use App\Module\Forge\Service\PullRequestCheckFailed;
use App\Module\Forge\Service\PullRequestCheckWriters;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Keeps the site review check of each open pull request of a card in line with the notes that the verdicts of every card on that pull request carried. */
final readonly class SiteReviewCheckPublisher
{
    public const string NAME = 'Loupe site review';

    private const int MAX_LISTED_NOTES = 20;

    private const int MAX_NOTE_WIDTH = 300;

    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private SiteReviewFactProvider $facts,
        private SiteReviewCheckStateRepository $siteReviewCheckStates,
        private PullRequestCheckWriters $writers,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Posts the check where it is stale. A failure on one pull request does not hold back the others.
     *
     * The result holds the cause of the first failure, and whether a state row changed.
     */
    public function publish(Card $card): SiteReviewWriteResult
    {
        $pullRequests = $this->cardPullRequests->findOpenGitHubForCard($card);
        $wanted = $this->facts->wantedChecks($pullRequests);
        if ([] === $wanted) {
            return new SiteReviewWriteResult(null, false);
        }

        $failure = null;
        $changed = false;
        foreach ($pullRequests as $pullRequest) {
            $check = $wanted[(string) $pullRequest->id] ?? null;
            if (null === $check) {
                continue;
            }
            $state = $this->siteReviewCheckStates->findOneByPullRequest($pullRequest);
            $current = null !== $state && $state->headSha === $check->headSha && $state->conclusion === $check->wantedConclusion && $state->noteCount === $check->noteCount && $state->notesDigest === $check->notesDigest;
            if ($current && null !== $state->checkRunId) {
                continue;
            }

            $writer = $this->writers->for($pullRequest->forge);
            if (null === $writer) {
                continue;
            }
            $reusable = null !== $state && $state->headSha === $check->headSha;
            try {
                $runId = $writer->publish(
                    $pullRequest,
                    self::NAME,
                    $check->headSha,
                    CheckWanted::SUCCESS === $check->wantedConclusion ? PullRequestCheckConclusion::Success : PullRequestCheckConclusion::Failure,
                    $this->title($check),
                    $this->summary($check),
                    $reusable ? $state->checkRunId : null,
                );
            } catch (PullRequestCheckFailed $e) {
                // A project with no GitHub App installation has nothing to post a check with.
                if ('no_installation' !== $e->cause) {
                    $failure ??= $e->cause;
                }

                continue;
            }

            if (null === $state) {
                $state = new SiteReviewCheckState($pullRequest, $check->headSha, $check->wantedConclusion, $check->noteCount);
                $this->em->persist($state);
            }
            $state->headSha = $check->headSha;
            $state->conclusion = $check->wantedConclusion;
            $state->noteCount = $check->noteCount;
            $state->notesDigest = $check->notesDigest;
            $state->checkRunId = $runId;
            $state->postedAt = \DateTimeImmutable::createFromInterface($this->clock->now());
            $this->em->flush();
            $changed = true;
        }

        return new SiteReviewWriteResult($failure, $changed);
    }

    /**
     * Turns the failed run of a pull request that no card links any more into a neutral one. The row of the pull request is gone, so the write uses an unsaved copy of its key.
     *
     * @return ?PullRequestCheckFailed the refusal of the forge
     */
    public function neutralizeUnlinked(Project $project, string $forge, string $repository, int $number, string $headSha, int $runId): ?PullRequestCheckFailed
    {
        $writer = $this->writers->for($forge);
        if (null === $writer) {
            return null;
        }

        // A locked untrack leaves the state, and a card that links the pull request again can rewrite that run before this write.
        $retained = $this->siteReviewCheckStates->findPostedRunByKey($project->requireId(), $forge, $repository, $number);
        if (null !== $retained && $retained->checkRunId === $runId && CheckWanted::FAILURE !== $retained->conclusion) {
            return null;
        }

        try {
            $writer->publish(
                new ForgePullRequest($project, $forge, $repository, $number),
                self::NAME,
                $headSha,
                PullRequestCheckConclusion::Neutral,
                $this->translator->trans('board.site_review_check.title_off'),
                $this->translator->trans('board.site_review_check.summary_unlinked'),
                $runId,
            );
        } catch (PullRequestCheckFailed $e) {
            return $e;
        }

        if (null !== $retained && $retained->checkRunId === $runId) {
            $this->em->remove($retained);
            $this->em->flush();
        }

        return null;
    }

    private function title(CheckWanted $check): string
    {
        return $this->translator->trans('board.site_review_check.title', ['%count%' => $check->noteCount]);
    }

    private function summary(CheckWanted $check): string
    {
        $notes = $check->notes;
        if (CheckWanted::SUCCESS === $check->wantedConclusion) {
            return $this->translator->trans('board.site_review_check.summary_clear');
        }

        $lines = [];
        foreach (\array_slice($notes, 0, self::MAX_LISTED_NOTES) as $note) {
            $body = mb_strimwidth(preg_replace('/\s+/', ' ', trim($note['body'])) ?? '', 0, self::MAX_NOTE_WIDTH, '...');
            $lines[] = \sprintf('- %s: %s', $note['url'], $body);
        }
        if (\count($notes) > self::MAX_LISTED_NOTES) {
            $lines[] = $this->translator->trans('board.site_review_check.summary_more', ['%count%' => \count($notes) - self::MAX_LISTED_NOTES]);
        }

        return implode("\n", $lines);
    }
}
