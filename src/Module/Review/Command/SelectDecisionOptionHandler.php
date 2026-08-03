<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\DecisionSelection;
use App\Module\Review\Repository\DecisionSelectionRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\DecisionBlockService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class SelectDecisionOptionHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private DecisionSelectionRepository $decisionSelections,
        private DecisionBlockService $decisionBlocks,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SelectDecisionOptionCommand $command): DecisionSelection
    {
        return $this->em->wrapInTransaction(function () use ($command): DecisionSelection {
            // Answering is a click, so a reviewer changing their mind fires two
            // overlapping requests routinely. Both would find no row and both
            // would insert, tripping the (document, decision) unique index. The
            // document is the only row that exists before the first answer, so
            // it is what serialises them.
            //
            // The version is read inside the lock too: a revision landing between
            // the read and the write would otherwise be validated against the old
            // version and stamped with its number.
            $this->em->lock($command->document, LockMode::PESSIMISTIC_WRITE);

            $version = $this->documentVersions->findLatest($command->document);

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

            $this->logger->info('review.decision.selected', [
                'document_id' => (string) $command->document->id,
                'decision_id' => $command->decisionId,
                'option_index' => $command->optionIndex,
                'version_number' => $version->versionNumber,
            ]);

            return $selection;
        });
    }
}
