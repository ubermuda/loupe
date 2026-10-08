<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\SiteReviewCheckState;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Repository\SiteReviewCheckStateRepository;
use App\Module\Board\Workflow\CheckWanted;
use App\Module\Board\Workflow\SiteReviewFactProvider;
use App\Module\Forge\Service\PullRequestCheckConclusion;
use App\Module\Forge\Service\PullRequestCheckFailed;
use App\Module\Forge\Service\PullRequestCheckWriters;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Keeps the site review check of each open pull request of a card in line with the notes that verdicts carried. */
final readonly class SiteReviewCheckPublisher
{
    public const string NAME = 'Loupe site review';

    private const int MAX_LISTED_NOTES = 20;

    private const int MAX_NOTE_WIDTH = 300;

    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private SiteReviewFactProvider $facts,
        private SiteReviewCheckStateRepository $siteReviewCheckStates,
        private BoardAutomation $boardAutomation,
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
        $wanted = $this->facts->wantedChecks($card, $pullRequests);
        if ([] === $wanted) {
            return new SiteReviewWriteResult(null, false);
        }

        $optedIn = $this->boardAutomation->settingsOf($card->project)->siteReviewCheck;
        $notes = $this->facts->carriedPendingNotes($card);
        $failure = null;
        $changed = false;
        foreach ($pullRequests as $pullRequest) {
            $check = $wanted[(string) $pullRequest->id] ?? null;
            if (null === $check) {
                continue;
            }
            $state = $this->siteReviewCheckStates->findOneByPullRequest($pullRequest);
            $current = null !== $state && $state->headSha === $check->headSha && $state->conclusion === $check->wantedConclusion && $state->noteCount === $check->noteCount;
            if ($current && (!$optedIn || null !== $state->checkRunId)) {
                continue;
            }

            $runId = null;
            if ($optedIn) {
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
                        $this->summary($check, $notes),
                        $reusable ? $state->checkRunId : null,
                    );
                } catch (PullRequestCheckFailed $e) {
                    $failure ??= $e->cause;

                    continue;
                }
            }

            if (null === $state) {
                $state = new SiteReviewCheckState($pullRequest, $check->headSha, $check->wantedConclusion, $check->noteCount);
                $this->em->persist($state);
            }
            $state->headSha = $check->headSha;
            $state->conclusion = $check->wantedConclusion;
            $state->noteCount = $check->noteCount;
            $state->checkRunId = $runId;
            $state->postedAt = \DateTimeImmutable::createFromInterface($this->clock->now());
            $this->em->flush();
            $changed = true;
        }

        return new SiteReviewWriteResult($failure, $changed);
    }

    private function title(CheckWanted $check): string
    {
        return $this->translator->trans('board.site_review_check.title', ['%count%' => $check->noteCount]);
    }

    /** @param list<array{id: string, url: string, body: string, anchorCount: int}> $notes */
    private function summary(CheckWanted $check, array $notes): string
    {
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
