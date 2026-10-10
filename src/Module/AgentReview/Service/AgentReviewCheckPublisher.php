<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Service;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewCategory;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\AgentReview\Entity\AgentReviewSeverity;
use App\Module\AgentReview\Repository\AgentReviewRepository;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardPullRequestRepository;
use App\Module\Board\Service\AgentReviewCheck;
use App\Module\Board\Service\SiteReviewWriteResult;
use App\Module\Forge\Service\PullRequestCheckConclusion;
use App\Module\Forge\Service\PullRequestCheckFailed;
use App\Module\Forge\Service\PullRequestCheckWriters;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Posts each stored agent review that no check shows yet, on the open GitHub pull requests of the card.
 *
 * Each review gets a new run: the forge appends the notes of every update to a run and cannot remove them.
 * For the same reason, a run that took only part of the notes keeps its id, and the retry sends it the rest.
 */
#[AsAlias(AgentReviewCheck::class)]
final readonly class AgentReviewCheckPublisher implements AgentReviewCheck
{
    public const string NAME = 'loupe/agent-review';

    public const int MAX_LISTED_FINDINGS = 20;

    private const int MAX_BODY_WIDTH = 300;

    public function __construct(
        private CardPullRequestRepository $cardPullRequests,
        private AgentReviewRepository $agentReviews,
        private PullRequestCheckWriters $writers,
        private AgentReviewAnnotations $annotations,
        private TranslatorInterface $translator,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /** A failure on one review does not hold back the others. */
    #[\Override]
    public function publish(Card $card): SiteReviewWriteResult
    {
        $failure = null;
        $changed = false;
        foreach ($this->agentReviews->findUnpostedOfCard($card, $this->cardPullRequests->findOpenGitHubForCard($card)) as $review) {
            $writer = $this->writers->for($review->pullRequest->forge);
            if (null === $writer) {
                continue;
            }
            $annotations = $this->annotations->of($review);
            try {
                $runId = $writer->publish(
                    $review->pullRequest,
                    self::NAME,
                    $review->headSha,
                    AgentReviewConclusion::Failure === $review->conclusion ? PullRequestCheckConclusion::Failure : PullRequestCheckConclusion::Success,
                    $this->title($review),
                    $this->summary($review),
                    $review->checkRunId,
                    \array_slice($annotations, $review->annotationsPosted),
                );
            } catch (PullRequestCheckFailed $e) {
                // A project with no GitHub App installation has nothing to post a check with.
                if ('no_installation' !== $e->cause) {
                    $failure ??= $e->cause;
                }
                if (null !== $e->runId) {
                    $review->checkRunId = $e->runId;
                    $review->annotationsPosted += $e->annotationsSent;
                    $this->em->flush();
                }

                continue;
            }

            $review->checkRunId = $runId;
            $review->annotationsPosted = \count($annotations);
            $review->postedAt = \DateTimeImmutable::createFromInterface($this->clock->now());
            $this->em->flush();
            $changed = true;
        }

        return new SiteReviewWriteResult($failure, $changed);
    }

    private function title(AgentReview $review): string
    {
        $counts = [];
        foreach ($review->findings() as $finding) {
            $counts[$finding->severity->value] = ($counts[$finding->severity->value] ?? 0) + 1;
        }

        $parts = [];
        foreach (AgentReviewSeverity::cases() as $severity) {
            if (isset($counts[$severity->value])) {
                $parts[] = $this->translator->trans('agent_review.check.count.'.$severity->value, ['%count%' => $counts[$severity->value]]);
            }
        }

        return [] === $parts
            ? $this->translator->trans('agent_review.check.title_clear')
            : $this->translator->trans('agent_review.check.title', ['%findings%' => implode(', ', $parts)]);
    }

    private function summary(AgentReview $review): string
    {
        $lines = [$review->summary];
        $findings = $review->findings();
        usort($findings, static fn (AgentReviewFinding $a, AgentReviewFinding $b): int => (AgentReviewCategory::Spec === $b->category) <=> (AgentReviewCategory::Spec === $a->category));
        $listed = \array_slice($findings, 0, self::MAX_LISTED_FINDINGS);
        $headed = array_any($findings, static fn (AgentReviewFinding $finding): bool => AgentReviewCategory::Spec === $finding->category);
        $heading = null;
        foreach ($listed as $finding) {
            if ($finding->category !== $heading) {
                $heading = $finding->category;
                $lines[] = '';
                if ($headed) {
                    $lines[] = $this->translator->trans('agent_review.check.heading.'.$finding->category->value);
                }
            }
            $lines[] = $this->line($finding);
        }
        if (\count($findings) > self::MAX_LISTED_FINDINGS) {
            $lines[] = $this->translator->trans('agent_review.check.summary_more', ['%count%' => \count($findings) - self::MAX_LISTED_FINDINGS]);
        }

        return implode("\n", $lines);
    }

    private function line(AgentReviewFinding $finding): string
    {
        $place = '';
        if (null !== $finding->path) {
            $range = $finding->startLine === $finding->endLine ? (string) $finding->startLine : $finding->startLine.'-'.$finding->endLine;
            $place = $finding->path.':'.$range.' ';
        }
        $line = \sprintf('- [%s] %s%s', $finding->severity->value, $place, $finding->title);
        $body = mb_strimwidth(trim(preg_replace('/\s+/', ' ', $finding->body) ?? ''), 0, self::MAX_BODY_WIDTH, '...');

        return '' === $body ? $line : $line.': '.$body;
    }
}
