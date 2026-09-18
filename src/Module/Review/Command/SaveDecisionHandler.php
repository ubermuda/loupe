<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\DecisionSelection;
use App\Module\Review\Repository\DecisionSelectionRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\DecisionBlockService;
use App\Module\Review\ValueObject\DecisionType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class SaveDecisionHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private DecisionSelectionRepository $decisionSelections,
        private DecisionBlockService $decisionBlocks,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(SaveDecisionCommand $command): void
    {
        $outcome = $this->em->wrapInTransaction(function () use ($command): bool|DomainErrors {
            $this->em->lock($command->document, LockMode::PESSIMISTIC_WRITE);
            $version = $this->documentVersions->findLatest($command->document);
            if ($version->versionNumber !== $command->displayedVersionNumber) {
                return new DomainErrors(['versionNumber' => 'review.decision.error.stale_version']);
            }
            $decision = array_find($this->decisionBlocks->extract($version->renderedHtml), fn ($candidate) => $candidate->id === $command->decisionId);
            if (null === $decision) {
                return new DomainErrors(['decisionId' => 'review.decision.error.unknown']);
            }

            $wanted = array_values(array_unique($command->optionIndexes));
            sort($wanted);
            if (DecisionType::Single === $decision->type && 1 !== \count($wanted)) {
                return new DomainErrors(['optionIndexes' => 'review.decision.error.unknown_option']);
            }
            foreach ($wanted as $index) {
                if (null === $decision->optionAt($index)) {
                    return new DomainErrors(['optionIndexes' => 'review.decision.error.unknown_option']);
                }
            }

            $stored = $this->decisionSelections->findByDocumentAndDecisionId($command->document, $command->decisionId);
            $current = array_values(array_filter($decision->resolveIndexes(array_map(
                static fn (DecisionSelection $selection): array => [$selection->optionLabel, $selection->optionIndex],
                $stored,
            )), static fn (?int $index): bool => null !== $index));
            sort($current);
            if ($current === $wanted) {
                return false;
            }
            $expected = array_values(array_unique($command->expectedOptionIndexes));
            sort($expected);
            if ($current !== $expected) {
                return new DomainErrors(['optionIndexes' => 'review.decision.error.changed_answer']);
            }

            foreach ($stored as $selection) {
                $this->em->remove($selection);
            }
            // Delete before inserting, because reordered options can reuse unique indexes.
            $this->em->flush();
            foreach ($wanted as $index) {
                $this->em->persist(new DecisionSelection(
                    $command->document,
                    $command->decisionId,
                    $index,
                    $decision->options[$index],
                    $version->versionNumber,
                ));
            }

            return true;
        });

        if ($outcome instanceof DomainErrors) {
            throw $outcome;
        }
        if (!$outcome) {
            return;
        }
        $this->auditor->record(
            'review.decision_saved',
            AuditOutcome::Success,
            [
                'documentId' => (string) $command->document->id,
                'decisionId' => $command->decisionId,
                'versionNumber' => $command->displayedVersionNumber,
                'optionCount' => \count(array_unique($command->optionIndexes)),
            ],
            new AuditSubject('document', (string) $command->document->id),
        );
    }
}
