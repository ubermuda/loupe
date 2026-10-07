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
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryProposalRepository;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Readiness\Service\ReadinessReportWriter;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Event\ReviewSubmitted;
use App\Module\Review\Repository\DecisionSelectionRepository;
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
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
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
        $ticked = [];
        foreach ($this->decisionSelections->findByDocumentAndDecisionId($document, ReadinessReportWriter::DECISION_ID) as $selection) {
            $ticked[$selection->optionIndex] = ReadinessReportWriter::pickLabel($selection->optionLabel);
        }

        // A missing link, a deleted column and a project with no slot all leave the column null, and the card then lands in Backlog.
        $next = $this->workflowSlotLinks->findColumnForSlot($project, self::NEXT_SLOT);
        $created = 0;
        foreach ($this->discoveryProposals->findForRun($run) as $proposal) {
            if (null === $proposal->position || null !== $proposal->createdCardId
                || ($ticked[$proposal->position] ?? null) !== ReadinessReportWriter::pickLabel($proposal->title)) {
                continue;
            }

            try {
                $card = ($this->createCard)(new CreateCardCommand(
                    project: $project,
                    title: $proposal->title,
                    body: $proposal->body,
                    type: $proposal->type,
                    column: $next,
                    reporter: CardReporter::Agent,
                    actor: CardReporter::System,
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
