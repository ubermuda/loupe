<?php

declare(strict_types=1);

namespace App\Module\Readiness\EventListener;

use App\Exception\DomainErrors;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\EpicChildrenOpen;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardSource;
use App\Module\Board\Entity\CardSourceKind;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryProposalRepository;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Readiness\Service\ReadinessReportWriter;
use App\Module\Review\Entity\DecisionSelection;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Event\ReviewSubmitted;
use App\Module\Review\Repository\DecisionSelectionRepository;
use App\Module\Review\Service\DecisionBlockService;
use App\Module\Review\ValueObject\Decision;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The owner approved a discovery report. Each proposal they ticked becomes a card in Next, and the discovery card moves to done.
 * The event fires inside the verdict transaction, so the card handlers join it, and a failure here undoes the verdict.
 */
#[AsEventListener]
final readonly class CreateTickedCardsOnReviewSubmitted
{
    public const string NEXT_SLOT = 'next';

    public function __construct(
        private DiscoveryRunRepository $discoveryRuns,
        private DiscoveryProposalRepository $discoveryProposals,
        private DecisionSelectionRepository $decisionSelections,
        private WorkflowSlotLinkRepository $workflowSlotLinks,
        private BoardColumnRepository $boardColumns,
        private CreateCardHandler $createCard,
        private UpdateCardHandler $updateCard,
        private DecisionBlockService $decisionBlocks,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The picks that still stand in the approved version, counted by option label.
     * A pick whose option a later version removed counts for nothing, and a moved option is found by its label.
     *
     * @return array<string, int>
     */
    private function tickedLabels(ReviewSubmitted $event, Document $document): array
    {
        $block = array_find(
            $this->decisionBlocks->extract($event->review->version->renderedHtml),
            static fn (Decision $decision): bool => ReadinessReportWriter::DECISION_ID === $decision->id,
        );
        if (null === $block) {
            return [];
        }

        $picks = $this->decisionSelections->findByDocumentAndDecisionId($document, ReadinessReportWriter::DECISION_ID);
        $resolved = $block->resolveIndexes(array_map(static fn (DecisionSelection $pick): array => [$pick->optionLabel, $pick->optionIndex], $picks));
        $ticked = [];
        foreach ($resolved as $index) {
            $option = null === $index ? null : $block->optionAt($index);
            if (null !== $option) {
                $label = ReadinessReportWriter::pickLabel($option);
                $ticked[$label] = ($ticked[$label] ?? 0) + 1;
            }
        }

        return $ticked;
    }

    public function __invoke(ReviewSubmitted $event): void
    {
        if (Verdict::Approved !== $event->review->verdict) {
            return;
        }
        $document = $event->review->version->document;
        $run = $this->discoveryRuns->findByReportDocument($document);
        if (null === $run || DiscoveryRunState::Reported !== $run->state) {
            return;
        }

        $project = $run->project;
        $ticked = $this->tickedLabels($event, $document);

        // A missing link, a deleted column and a project with no slot all leave the column null, and the card then lands in Backlog.
        $next = $this->workflowSlotLinks->findColumnForSlot($project, self::NEXT_SLOT);
        $created = 0;
        foreach ($this->discoveryProposals->findForRun($run) as $proposal) {
            $label = ReadinessReportWriter::pickLabel($proposal->title);
            if (null === $proposal->position || null !== $proposal->createdCardId || ($ticked[$label] ?? 0) < 1) {
                continue;
            }
            --$ticked[$label];

            try {
                $card = ($this->createCard)(new CreateCardCommand(
                    project: $project,
                    title: $proposal->title,
                    body: $proposal->body,
                    type: $proposal->type,
                    column: $next,
                    reporter: CardReporter::Agent,
                    actor: CardReporter::System,
                    source: new CardSource(CardSourceKind::Loupe),
                ));
            } catch (DomainErrors $e) {
                $this->logger->warning('readiness.proposal_card_refused', ['discoveryRunId' => (string) $run->id, 'proposalId' => (string) $proposal->id, 'errors' => $e->errors]);

                continue;
            }
            $proposal->createdCardId = $card->id;
            ++$created;
        }

        $terminal = $this->boardColumns->findFirstTerminalForProjectId((string) $project->id);
        if (null !== $terminal && $terminal !== $run->card->column) {
            try {
                ($this->updateCard)(new UpdateCardCommand(card: $run->card, actor: CardReporter::System, column: $terminal));
            } catch (DomainErrors|EpicChildrenOpen $e) {
                $this->logger->warning('readiness.discovery_card_not_moved', ['discoveryRunId' => (string) $run->id, 'cardId' => (string) $run->card->id, 'reason' => $e::class]);
            }
        }

        $run->state = DiscoveryRunState::Done;
        $run->endedAt = $this->clock->now();
        $this->em->flush();

        $this->logger->info('readiness.report_approved', ['discoveryRunId' => (string) $run->id, 'projectId' => (string) $project->id, 'cardsCreated' => $created]);
    }
}
