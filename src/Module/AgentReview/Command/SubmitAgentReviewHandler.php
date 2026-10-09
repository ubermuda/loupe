<?php

declare(strict_types=1);

namespace App\Module\AgentReview\Command;

use App\Exception\DomainErrors;
use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\AgentReview\Entity\AgentReviewFinding;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\CardPullRequests;
use App\Module\Board\Service\PullRequestUrlResolver;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Workflow\Contract\CardEvaluations;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Stores the review that a running review worker of the card made of one commit of a pull request of the card. */
final readonly class SubmitAgentReviewHandler
{
    public const string REVIEW_WORK_KIND = 'review';

    public const string NO_REVIEW_RUN = 'agent_review.error.no_review_run';
    public const string NOT_LINKED = 'agent_review.error.pull_request_not_linked';
    public const string NOT_TRACKED = 'agent_review.error.pull_request_not_tracked';

    public function __construct(
        private WorkerRunRepository $workerRuns,
        private PullRequestUrlResolver $urlResolver,
        private CardPullRequests $cardPullRequests,
        private ForgePullRequestRepository $forgePullRequests,
        private BoardAutomation $boardAutomation,
        private EntityManagerInterface $em,
        private CardEvaluations $evaluations,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(SubmitAgentReviewCommand $command): AgentReview
    {
        $card = $command->card;
        $cardId = $card->id ?? throw new \LogicException('A stored card has an id.');
        $projectId = $card->project->id ?? throw new \LogicException('A stored project has an id.');

        $run = null === $command->sessionId ? null : $this->workerRuns->findOpenOfSessionForCard($card->project, $command->sessionId, $cardId, self::REVIEW_WORK_KIND);
        if (null === $run) {
            throw new DomainErrors(['session' => self::NO_REVIEW_RUN]);
        }

        $ref = $this->urlResolver->resolve(trim($command->pullRequestUrl));
        if (null === $ref->repository || null === $ref->number || !$this->cardPullRequests->links($card, $ref->forge->value, $ref->repository, $ref->number)) {
            throw new DomainErrors(['pullRequestUrl' => self::NOT_LINKED]);
        }
        $pullRequest = $this->forgePullRequests->findByKeys($projectId, [['forge' => $ref->forge->value, 'repository' => $ref->repository, 'number' => $ref->number]])[0] ?? null;
        if (null === $pullRequest) {
            throw new DomainErrors(['pullRequestUrl' => self::NOT_TRACKED]);
        }

        $failing = $this->boardAutomation->settingsOf($card->project)->agentReviewFailingSeverities;
        $fails = array_any($command->findings, static fn (AgentReviewFinding $finding): bool => \in_array($finding->severity->value, $failing, true));

        $review = new AgentReview(
            project: $card->project,
            card: $card,
            pullRequest: $pullRequest,
            headSha: $command->headSha,
            summary: $command->summary,
            conclusion: $fails ? AgentReviewConclusion::Failure : AgentReviewConclusion::Success,
            findings: $command->findings,
            workerRunId: $run->id,
            workRequestId: $run->workRequestId,
        );
        $this->em->persist($review);
        $this->em->flush();

        $reviewId = $review->id ?? throw new \LogicException('A flushed review has an id.');
        $this->auditor->record(
            'agent_review.submitted',
            AuditOutcome::Success,
            [
                'reviewId' => (string) $reviewId,
                'cardId' => (string) $cardId,
                'projectId' => (string) $projectId,
                'pullRequestId' => (string) $pullRequest->id,
                'workerRunId' => (string) $run->id,
                'conclusion' => $review->conclusion->value,
                'findingCount' => \count($command->findings),
            ],
            new AuditSubject('agent_review', (string) $reviewId),
        );

        if ($this->evaluations->isOn()) {
            $this->evaluations->forCards([$cardId]);
        }

        return $review;
    }
}
