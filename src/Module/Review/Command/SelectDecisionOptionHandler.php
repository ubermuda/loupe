<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\Review\Entity\DecisionSelection;
use App\Module\Review\Repository\DecisionSelectionRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\DecisionBlockService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SelectDecisionOptionHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private DecisionSelectionRepository $decisionSelections,
        private DecisionBlockService $decisionBlocks,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(SelectDecisionOptionCommand $command): DecisionSelection
    {
        $selection = $this->em->wrapInTransaction(function () use ($command): DecisionSelection {
            // Two overlapping answers would both find no row and both insert,
            // tripping the (document, decision) unique index. The document is
            // the only row existing before the first answer, so it serialises
            // them. The version is read inside the lock too, or a revision
            // landing mid-write is stamped with the old number.
            $this->em->lock($command->document, LockMode::PESSIMISTIC_WRITE);

            $version = $this->documentVersions->findLatest($command->document);

            // An option index only means anything against the list it was
            // rendered from. Refused rather than resolved against either
            // version: the current one records a label they never clicked, and
            // the one they saw answers with options the document has dropped.
            if ($version->versionNumber !== $command->displayedVersionNumber) {
                throw new DomainErrors(['versionNumber' => 'review.decision.error.stale_version']);
            }

            $decision = null;
            foreach ($this->decisionBlocks->extract($version->renderedHtml) as $candidate) {
                if ($candidate->id === $command->decisionId) {
                    $decision = $candidate;
                }
            }

            if (null === $decision) {
                throw new DomainErrors(['decisionId' => 'review.decision.error.unknown']);
            }

            $label = $decision->optionAt($command->optionIndex)
                ?? throw new DomainErrors(['optionIndex' => 'review.decision.error.unknown_option']);

            $selection = $this->decisionSelections->findOneByDocumentAndDecisionId($command->document, $command->decisionId);
            if (null === $selection) {
                $selection = new DecisionSelection(
                    document: $command->document,
                    decisionId: $command->decisionId,
                    optionIndex: $command->optionIndex,
                    optionLabel: $label,
                    versionNumber: $version->versionNumber,
                );
                $this->em->persist($selection);
            } else {
                $selection->optionIndex = $command->optionIndex;
                $selection->optionLabel = $label;
                $selection->versionNumber = $version->versionNumber;
                $selection->selectedAt = new \DateTimeImmutable();
            }

            $this->em->flush();

            return $selection;
        });

        // After the commit, never inside it: the sink drains at kernel.terminate,
        // so a record written in the closure outlives a rollback. The chosen
        // label stays out, because the document's author wrote it.
        $this->auditor->record(
            'review.decision_selected',
            AuditOutcome::Success,
            [
                'documentId' => (string) $command->document->id,
                'decisionId' => $command->decisionId,
                'optionIndex' => $command->optionIndex,
                'versionNumber' => $selection->versionNumber,
            ],
            new AuditSubject('document', (string) $command->document->id),
        );

        return $selection;
    }
}
