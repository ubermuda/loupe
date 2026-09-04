<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\DecisionSelection;
use App\Module\Review\Repository\DecisionSelectionRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\DecisionBlockService;
use App\Module\Review\ValueObject\Decision;
use App\Module\Review\ValueObject\DecisionType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

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

    public function __invoke(SelectDecisionOptionCommand $command): SelectDecisionOptionResult
    {
        $result = $this->em->wrapInTransaction(function () use ($command): SelectDecisionOptionResult {
            // Two overlapping answers would both find no row and both insert,
            // tripping the (document, decision, option) unique index. The
            // document is the only row existing before the first answer, so it
            // serialises them. The version is read inside the lock too, or a
            // revision landing mid-write is stamped with the old number.
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

            $stored = $this->decisionSelections->findByDocumentAndDecisionId($command->document, $command->decisionId);

            $selection = DecisionType::Multiple === $decision->type
                ? $this->setOption($command, $label, $version->versionNumber, $this->atCurrentIndexes($decision, $stored))
                : $this->replaceAnswer($command, $label, $version->versionNumber, $stored);

            $this->em->flush();

            return new SelectDecisionOptionResult($selection, $version->versionNumber, $label);
        });

        // After the commit, never inside it: the sink drains at kernel.terminate,
        // so a record written in the closure outlives a rollback. The chosen
        // label stays out, because the document's author wrote it.
        $this->auditor->record(
            null === $result->selection ? 'review.decision_cleared' : 'review.decision_selected',
            AuditOutcome::Success,
            [
                'documentId' => (string) $command->document->id,
                'decisionId' => $command->decisionId,
                'optionIndex' => $command->optionIndex,
                'versionNumber' => $result->versionNumber,
            ],
            new AuditSubject('document', (string) $command->document->id),
        );

        return $result;
    }

    /**
     * The stored answers, each sitting where the current version shows it.
     *
     * A revision that reorders a block leaves the rows on their old indexes
     * while the page resolves them by label onto the new ones. The click then
     * names an index the rows do not use, so a tick reads as an untick and the
     * unique key collides.
     *
     * A row whose option the revision dropped resolves nowhere, so it is parked
     * past the last option rather than left where it stands: its old index is
     * one a surviving answer may now need, and nothing reads the index of a row
     * whose label is gone. The label, the version and the time are what that row
     * reports, and all three are carried across.
     *
     * No two rows are given the same target, so the set can be laid down in one
     * pass. The rows that move are rewritten rather than updated, because two
     * options that swap places would meet on the unique key mid-flush: Doctrine
     * writes every update before any delete.
     *
     * @param list<DecisionSelection> $stored
     *
     * @return list<DecisionSelection>
     */
    private function atCurrentIndexes(Decision $decision, array $stored): array
    {
        $beyondTheOptions = \count($decision->options);
        $resolved = $decision->resolveIndexes(array_map(
            static fn (DecisionSelection $selection): array => [$selection->optionLabel, $selection->optionIndex],
            $stored,
        ));

        $moved = [];
        $settled = [];
        foreach ($stored as $position => $selection) {
            $index = $resolved[$position] ?? $beyondTheOptions++;

            if ($index === $selection->optionIndex) {
                $settled[] = $selection;

                continue;
            }

            $moved[] = [$selection, $index];
        }

        if ([] === $moved) {
            return $settled;
        }

        foreach ($moved as [$selection]) {
            $this->em->remove($selection);
        }
        $this->em->flush();

        foreach ($moved as [$selection, $index]) {
            $replacement = new DecisionSelection(
                document: $selection->document,
                decisionId: $selection->decisionId,
                optionIndex: $index,
                optionLabel: $selection->optionLabel,
                versionNumber: $selection->versionNumber,
                selectedAt: $selection->selectedAt,
            );
            $this->em->persist($replacement);
            $settled[] = $replacement;
        }
        $this->em->flush();

        return $settled;
    }

    /**
     * Puts one option of a multi-choice block on or off.
     *
     * One POST carries one option either way, which is what keeps the Turbo
     * stream and the refusal path the same shape as the single-choice one. The
     * click says which of the two it wants, so a repeat of the same POST, and a
     * second tab holding an older view of the block, both land where they mean.
     *
     * @param list<DecisionSelection> $stored
     */
    private function setOption(
        SelectDecisionOptionCommand $command,
        string $label,
        int $versionNumber,
        array $stored,
    ): ?DecisionSelection {
        foreach ($stored as $selection) {
            if ($selection->optionIndex === $command->optionIndex) {
                if (!$command->chosen) {
                    $this->em->remove($selection);

                    return null;
                }

                return $selection;
            }
        }

        if (!$command->chosen) {
            return null;
        }

        $selection = new DecisionSelection(
            document: $command->document,
            decisionId: $command->decisionId,
            optionIndex: $command->optionIndex,
            optionLabel: $label,
            versionNumber: $versionNumber,
        );
        $this->em->persist($selection);

        return $selection;
    }

    /**
     * Records the one answer a single-choice block takes.
     *
     * Extra rows are removed and flushed before the kept row moves, because a
     * revision can turn a multi-choice block back into a single-choice one and
     * Doctrine writes updates before deletes. They would meet on the unique key.
     *
     * @param list<DecisionSelection> $stored
     */
    private function replaceAnswer(
        SelectDecisionOptionCommand $command,
        string $label,
        int $versionNumber,
        array $stored,
    ): DecisionSelection {
        $selection = array_shift($stored);

        if ([] !== $stored) {
            foreach ($stored as $extra) {
                $this->em->remove($extra);
            }
            $this->em->flush();
        }

        if (null === $selection) {
            $selection = new DecisionSelection(
                document: $command->document,
                decisionId: $command->decisionId,
                optionIndex: $command->optionIndex,
                optionLabel: $label,
                versionNumber: $versionNumber,
            );
            $this->em->persist($selection);

            return $selection;
        }

        $selection->optionIndex = $command->optionIndex;
        $selection->optionLabel = $label;
        $selection->versionNumber = $versionNumber;
        $selection->selectedAt = new \DateTimeImmutable();

        return $selection;
    }
}
