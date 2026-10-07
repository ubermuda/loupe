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
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Turns a proposed card into a backlog card. The proposal moves to created
 * before the card exists, so a second accept that races this one finds it
 * taken. A refused card puts the proposal back.
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

        $claimed = $this->em->wrapInTransaction(function () use ($proposalId): ?Proposal {
            $proposal = $this->proposals->findOneLocked($proposalId);
            if (null === $proposal || ProposalState::Proposed !== $proposal->state) {
                return null;
            }
            $proposal->state = ProposalState::Created;
            $this->em->flush();

            return $proposal;
        });
        if (null === $claimed) {
            throw new DomainErrors(['proposal' => self::NOT_PROPOSED]);
        }

        $project = $claimed->analysis->project;
        try {
            $cardId = $this->cards->createBacklogCard($project, new ProposalCard($claimed->title, $claimed->body, $claimed->analysis->documentId));
        } catch (\Throwable $e) {
            $this->release($proposalId);

            throw $e;
        }
        $claimed->cardId = $cardId;
        $this->em->flush();

        $this->auditor->record(
            'insights.proposal_accepted',
            AuditOutcome::Success,
            [
                'proposalId' => (string) $proposalId,
                'analysisId' => (string) $claimed->analysis->id,
                'projectId' => (string) $project->id,
                'cardId' => $cardId->toRfc4122(),
            ],
            new AuditSubject('proposal', (string) $proposalId),
        );

        return $claimed;
    }

    private function release(Uuid $proposalId): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE insights_proposals SET state = :proposed WHERE id = :id AND state = :created AND card_id IS NULL',
            ['proposed' => ProposalState::Proposed->value, 'created' => ProposalState::Created->value, 'id' => $proposalId->toRfc4122()],
        );
    }
}
