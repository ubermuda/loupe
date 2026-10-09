<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Messenger\RecomputeBucketTimes;
use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Entity\ProposalKind;
use App\Module\Insights\Entity\ProposalState;
use App\Module\Insights\Proposal\ProposalCard;
use App\Module\Insights\Proposal\ProposalCardCreatorInterface;
use App\Module\Insights\Repository\ProposalRepository;
use App\Module\Insights\Service\BucketRuleWriter;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Turns a proposed card into a backlog card, or a proposed bucket rule into a
 * rule of the project. The result and the created proposal commit in one
 * transaction, under a lock on the proposal row, so a failure leaves neither
 * and a second accept that races this one waits and is refused.
 */
final readonly class AcceptProposalHandler
{
    public const string NOT_PROPOSED = 'insights.proposal.error.not_proposed';
    public const string BUCKET_RULE_INVALID = 'insights.proposal.error.bucket_rule_invalid';
    public const string BUCKET_RULE_LIMIT = 'insights.proposal.error.bucket_rule_limit';

    public function __construct(
        private ProposalRepository $proposals,
        private ProposalCardCreatorInterface $cards,
        private EntityManagerInterface $em,
        private BucketRuleWriter $ruleWriter,
        private MessageBusInterface $bus,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(AcceptProposalCommand $command): Proposal
    {
        $proposalId = $command->proposal->id ?? throw new \LogicException('A stored proposal has an id.');
        // The card transaction nests as a savepoint. A refusal leaves as a
        // value, because a throw closes the EntityManager.
        $accepted = $this->em->wrapInTransaction(function () use ($proposalId): Proposal|DomainErrors {
            $proposal = $this->proposals->findOneLocked($proposalId);
            if (null === $proposal || ProposalState::Proposed !== $proposal->state) {
                return new DomainErrors(['proposal' => self::NOT_PROPOSED]);
            }
            if (ProposalKind::BucketRule === $proposal->kind) {
                $refusal = $this->createRule($proposal);
                if (null !== $refusal) {
                    return $refusal;
                }
            } else {
                try {
                    $proposal->cardId = $this->cards->createBacklogCard($proposal->analysis->project, new ProposalCard($proposal->title, $proposal->body, $proposal->analysis->documentId));
                } catch (DomainErrors $refusal) {
                    return $refusal;
                }
            }
            $proposal->state = ProposalState::Created;

            return $proposal;
        });
        if ($accepted instanceof DomainErrors) {
            throw $accepted;
        }

        $projectId = (string) $accepted->analysis->project->id;
        $this->auditor->record(
            'insights.proposal_accepted',
            AuditOutcome::Success,
            [
                'proposalId' => (string) $proposalId,
                'analysisId' => (string) $accepted->analysis->id,
                'projectId' => $projectId,
                'kind' => $accepted->kind->value,
                'cardId' => null === $accepted->cardId ? null : (string) $accepted->cardId,
            ],
            new AuditSubject('proposal', (string) $proposalId),
        );
        if (ProposalKind::BucketRule === $accepted->kind) {
            $this->bus->dispatch(new RecomputeBucketTimes($projectId));
        }

        return $accepted;
    }

    /** The caller runs in a transaction. A refusal leaves as a value, because a throw closes the EntityManager. */
    private function createRule(Proposal $proposal): ?DomainErrors
    {
        $pattern = $proposal->payload['pattern'] ?? null;
        $bucket = $proposal->payload['bucket'] ?? null;
        if (!\is_string($pattern) || !\is_string($bucket) || [] !== BucketRuleWriter::errors($pattern, $bucket)) {
            return new DomainErrors(['proposal' => self::BUCKET_RULE_INVALID]);
        }

        $project = $proposal->analysis->project;
        // The same lock the rule handlers take, so an accept and a create cannot pass the limit together.
        $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
        if ($this->ruleWriter->append($project, $pattern, $bucket) instanceof DomainErrors) {
            return new DomainErrors(['proposal' => self::BUCKET_RULE_LIMIT]);
        }

        return null;
    }
}
