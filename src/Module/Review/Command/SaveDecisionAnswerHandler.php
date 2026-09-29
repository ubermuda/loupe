<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\DecisionAnswer;
use App\Module\Review\Entity\DecisionSelection;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Event\DecisionAnswerChanged;
use App\Module\Review\Repository\DecisionAnswerRepository;
use App\Module\Review\Repository\DecisionSelectionRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Service\DecisionBlockService;
use App\Module\Review\ValueObject\Decision;
use App\Module\Review\ValueObject\DecisionType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Replaces the whole answer to one decision with the state the reviewer's tab
 * shows. The last write wins, whichever version that tab displays: the picks
 * are carried onto the latest version by label.
 */
final readonly class SaveDecisionAnswerHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private DecisionSelectionRepository $decisionSelections,
        private DecisionAnswerRepository $decisionAnswers,
        private DecisionBlockService $decisionBlocks,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(SaveDecisionAnswerCommand $command): SaveDecisionAnswerResult
    {
        $context = [];
        $changed = null;
        $result = $this->em->wrapInTransaction(function () use ($command, &$context, &$changed): SaveDecisionAnswerResult|DomainErrors {
            // The document row serialises two saves, which would otherwise both
            // insert onto the unique keys. The latest version is read under it too.
            $this->em->lock($command->document, LockMode::PESSIMISTIC_WRITE);

            $shown = $this->documentVersions->findByNumber($command->document, $command->displayedVersionNumber);
            if (null === $shown) {
                return new DomainErrors(['versionNumber' => 'review.decision.error.unknown_version']);
            }
            $latest = $this->documentVersions->findLatest($command->document);
            $shownDecision = $this->decision($shown, $command->decisionId);
            $latestDecision = $this->decision($latest, $command->decisionId);
            if (null === $shownDecision || null === $latestDecision) {
                return new DomainErrors(['decisionId' => 'review.decision.error.unknown']);
            }

            $stored = $this->decisionSelections->findByDocumentAndDecisionId($command->document, $command->decisionId);
            $answer = $this->decisionAnswers->findOneByDocumentAndDecisionId($command->document, $command->decisionId);

            $wanted = [];
            $note = null;
            if (!$command->clear) {
                $shownIndexes = array_values(array_unique($command->optionIndexes));
                sort($shownIndexes);
                $single = DecisionType::Single === $shownDecision->type || DecisionType::Single === $latestDecision->type;
                if ($single && \count($shownIndexes) > 1) {
                    return new DomainErrors(['optionIndexes' => 'review.decision.error.unknown_option']);
                }
                $answers = [];
                foreach ($shownIndexes as $index) {
                    $label = $shownDecision->optionAt($index);
                    if (null === $label) {
                        return new DomainErrors(['optionIndexes' => 'review.decision.error.unknown_option']);
                    }
                    $answers[] = [$label, $index];
                }
                $wanted = $latestDecision->resolveIndexes($answers);
                if (\in_array(null, $wanted, true)) {
                    return new DomainErrors(['optionIndexes' => 'review.decision.error.unknown_option']);
                }
                $wanted = array_values(array_filter($wanted, static fn (?int $index): bool => null !== $index));
                sort($wanted);
            }
            // Clear drops the picks only: the note is the reviewer's own writing.
            $trimmed = trim($command->note ?? '');
            if ('' !== $trimmed) {
                $note = $trimmed;
            }
            $unanswered = [] === $wanted && null === $note;

            $current = array_values(array_filter($latestDecision->resolveIndexes(array_map(
                static fn (DecisionSelection $selection): array => [$selection->optionLabel, $selection->optionIndex],
                $stored,
            )), static fn (?int $index): bool => null !== $index));
            sort($current);
            $selectionsChanged = $current !== $wanted || \count($stored) !== \count($wanted);
            $answerChanged = $unanswered ? null !== $answer : (null === $answer || $answer->note !== $note);
            if (!$selectionsChanged && !$answerChanged) {
                return new SaveDecisionAnswerResult(changed: false, cleared: $command->clear || $unanswered);
            }

            if ($selectionsChanged) {
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
                        $latestDecision->options[$index],
                        $latest->versionNumber,
                    ));
                }
            }

            if ($unanswered) {
                if (null !== $answer) {
                    $this->em->remove($answer);
                }
                $answer = null;
            } elseif (null === $answer) {
                $answer = new DecisionAnswer($command->document, $command->decisionId, $note, $command->answeredBy, $latest->versionNumber);
                $this->em->persist($answer);
            } else {
                $answer->note = $note;
                $answer->answeredBy = $command->answeredBy;
                $answer->answeredAtVersion = $latest->versionNumber;
                $answer->updatedAt = new \DateTimeImmutable();
            }
            $this->em->flush();

            $context = [
                'documentId' => (string) $command->document->id,
                'decisionId' => $command->decisionId,
                'versionNumber' => $latest->versionNumber,
                'optionCount' => \count($wanted),
                'hasNote' => null !== $note,
            ];
            $changed = new DecisionAnswerChanged(
                $command->document->project->id ?? throw new \LogicException('The project has no id.'),
                $command->document->id ?? throw new \LogicException('The document has no id.'),
                $command->decisionId,
                $latest->versionNumber,
                $wanted,
                $answer?->note,
                $answer?->answeredBy?->fullName,
                $answer->updatedAt ?? new \DateTimeImmutable(),
            );

            return new SaveDecisionAnswerResult(changed: true, cleared: $command->clear || $unanswered);
        });

        if ($result instanceof DomainErrors) {
            throw $result;
        }
        if (null !== $changed) {
            $this->events->dispatch($changed);
        }
        if ($result->changed) {
            $this->auditor->record(
                $result->cleared ? 'review.decision_cleared' : 'review.decision_saved',
                AuditOutcome::Success,
                $context,
                new AuditSubject('document', (string) $command->document->id),
            );
        }

        return $result;
    }

    private function decision(DocumentVersion $version, string $decisionId): ?Decision
    {
        return array_find(
            $this->decisionBlocks->extract($version->renderedHtml),
            static fn (Decision $candidate): bool => $candidate->id === $decisionId,
        );
    }
}
