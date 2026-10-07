<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Entity\ProposalKind;
use App\Module\Insights\Entity\ProposalState;
use App\Module\Insights\Proposal\ProposalCard;
use App\Module\Insights\Proposal\ProposalCardCreatorInterface;
use App\Module\Insights\Repository\ProposalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Turns a proposed card into a backlog card. The card and the created proposal
 * commit in one transaction, under a lock on the proposal row, so a failure
 * leaves neither and a second accept that races this one waits and is refused.
 */
final readonly class AcceptProposalHandler
{
    public const string NOT_PROPOSED = 'insights.proposal.error.not_proposed';
    public const string BUCKET_RULE_UNSUPPORTED = 'insights.proposal.error.bucket_rule_unsupported';

    public function __construct(
        private ProposalRepository $proposals,
        private ProposalCardCreatorInterface $cards,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(AcceptProposalCommand $command): Proposal
    {
        $proposalId = $command->proposal->id ?? throw new \LogicException('A stored proposal has an id.');
        if (ProposalKind::BucketRule === $command->proposal->kind) {
            throw new DomainErrors(['proposal' => self::BUCKET_RULE_UNSUPPORTED]);
        }

        // The card transaction nests as a savepoint. A refusal leaves as a
        // value, because a throw closes the EntityManager.
        $accepted = $this->em->wrapInTransaction(function () use ($proposalId): Proposal|DomainErrors {
            $proposal = $this->proposals->findOneLocked($proposalId);
            if (null === $proposal || ProposalState::Proposed !== $proposal->state) {
                return new DomainErrors(['proposal' => self::NOT_PROPOSED]);
            }
            try {
                $cardId = $this->cards->createBacklogCard($proposal->analysis->project, new ProposalCard($proposal->title, $proposal->body, $proposal->analysis->documentId));
            } catch (DomainErrors $refusal) {
                return $refusal;
            }
            $proposal->state = ProposalState::Created;
            $proposal->cardId = $cardId;

            return $proposal;
        });
        if ($accepted instanceof DomainErrors) {
            throw $accepted;
        }

        $this->auditor->record(
            'insights.proposal_accepted',
            AuditOutcome::Success,
            [
                'proposalId' => (string) $proposalId,
                'analysisId' => (string) $accepted->analysis->id,
                'projectId' => (string) $accepted->analysis->project->id,
                'cardId' => (string) $accepted->cardId,
            ],
            new AuditSubject('proposal', (string) $proposalId),
        );

        return $accepted;
    }
}
