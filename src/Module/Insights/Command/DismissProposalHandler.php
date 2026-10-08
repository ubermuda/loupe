<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Entity\ProposalState;
use App\Module\Insights\Repository\ProposalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class DismissProposalHandler
{
    public const string REASON_TOO_LONG = 'insights.proposal.error.dismiss_reason_too_long';

    public function __construct(
        private ProposalRepository $proposals,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(DismissProposalCommand $command): Proposal
    {
        $proposalId = $command->proposal->id ?? throw new \LogicException('A stored proposal has an id.');
        $reason = null === $command->reason ? '' : trim($command->reason);
        if (mb_strlen($reason) > Proposal::MAX_DISMISS_REASON_LENGTH) {
            throw new DomainErrors(['reason' => self::REASON_TOO_LONG]);
        }

        $dismissed = $this->em->wrapInTransaction(function () use ($proposalId, $reason): ?Proposal {
            $proposal = $this->proposals->findOneLocked($proposalId);
            if (null === $proposal || ProposalState::Proposed !== $proposal->state) {
                return null;
            }
            $proposal->state = ProposalState::Dismissed;
            $proposal->dismissReason = '' === $reason ? null : $reason;
            $this->em->flush();

            return $proposal;
        });
        if (null === $dismissed) {
            throw new DomainErrors(['proposal' => AcceptProposalHandler::NOT_PROPOSED]);
        }

        $this->auditor->record(
            'insights.proposal_dismissed',
            AuditOutcome::Success,
            [
                'proposalId' => (string) $proposalId,
                'analysisId' => (string) $dismissed->analysis->id,
                'projectId' => (string) $dismissed->analysis->project->id,
                'hasReason' => null !== $dismissed->dismissReason,
            ],
            new AuditSubject('proposal', (string) $proposalId),
        );

        return $dismissed;
    }
}
