<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardRepository;
use App\Module\Readiness\Entity\DiscoveryProposal;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Module\Readiness\Repository\DiscoveryRunRepository;
use App\Module\Readiness\Service\ReadinessReportWriter;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Workflow\Contract\CardEvaluations;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Writes the report of a discovery run as a document, stores the proposals, and links the document to the discovery card. */
final readonly class SubmitReadinessReportHandler
{
    public const string RUN_UNKNOWN = 'readiness.report.error.run_unknown';

    public const string RUN_NOT_REQUESTED = 'readiness.report.error.run_not_requested';

    public const string ALREADY_REPORTED = 'readiness.report.error.already_reported';

    public const string FINDING_INVALID = 'readiness.report.error.finding_invalid';

    public const string PROPOSAL_INVALID = 'readiness.report.error.proposal_invalid';

    public const string PROPOSAL_TYPE = 'readiness.report.error.proposal_type';

    public const string PROPOSAL_DUPLICATE = 'readiness.report.error.proposal_duplicate';

    public const string CARD_NOT_OPEN = 'readiness.report.error.card_not_open';

    public const string MARKUP_NOT_ALLOWED = 'readiness.report.error.markup_not_allowed';

    public const string TAG = 'readiness-report';

    /** @var list<CardType> */
    public const array PROPOSAL_TYPES = [CardType::Feature, CardType::Bug, CardType::Security, CardType::Tooling, CardType::Docs, CardType::Idea];

    public function __construct(
        private DiscoveryRunRepository $discoveryRuns,
        private CardRepository $cards,
        private CreateDocumentHandler $createDocument,
        private ReadinessReportWriter $writer,
        private CardEvaluations $evaluations,
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(SubmitReadinessReportCommand $command): Document
    {
        $project = $command->project;
        $run = Uuid::isValid($command->runId) ? $this->discoveryRuns->find($command->runId) : null;
        if (null === $run || $run->project !== $project) {
            throw new DomainErrors(['runId' => self::RUN_UNKNOWN]);
        }

        $types = $this->validate($command);

        // The lock makes the state check and the report one step, so two reports cannot both pass. A refusal leaves the closure as a value, because a throw closes the EntityManager.
        $document = $this->em->wrapInTransaction(function () use ($command, $run, $types): Document|DomainErrors {
            $this->em->lock($command->project, LockMode::PESSIMISTIC_WRITE);
            $fresh = $this->discoveryRuns->freshStateOf($run);
            if ($fresh['hasReport']) {
                return new DomainErrors(['runId' => self::ALREADY_REPORTED]);
            }
            if (DiscoveryRunState::Requested !== $fresh['state']) {
                return new DomainErrors(['runId' => self::RUN_NOT_REQUESTED]);
            }

            $proposals = [];
            $position = 0;
            foreach (array_values($command->proposals) as $index => $input) {
                $proposals[] = new DiscoveryProposal(
                    run: $run,
                    position: null === $input->openCardNumber ? $position++ : null,
                    key: trim($input->key),
                    title: ReadinessReportWriter::optionLabel($input->title),
                    type: $types[$index],
                    body: $input->body,
                    openCardNumber: $input->openCardNumber,
                );
            }

            $document = ($this->createDocument)(new CreateDocumentCommand(
                project: $command->project,
                title: mb_substr($this->translator->trans('readiness.report.title', ['%project%' => $command->project->name]), 0, Document::MAX_TITLE_LENGTH),
                markdown: $this->writer->write($command->workflow, $command->summary, array_values($command->findings), $proposals),
                description: $this->translator->trans('readiness.report.description'),
                tagNames: [self::TAG],
            ));

            $card = $run->card;
            $card->syncDocuments(...[...array_map(static fn (CardDocument $link): Document => $link->document, $card->documents->toArray()), $document]);
            foreach ($proposals as $proposal) {
                $this->em->persist($proposal);
            }
            $run->reportDocument = $document;
            $run->state = DiscoveryRunState::Reported;
            $this->em->flush();

            return $document;
        });
        if ($document instanceof DomainErrors) {
            throw $document;
        }

        $cardId = $run->card->id ?? throw new \LogicException('A stored card has an id.');
        $gaps = \count(array_filter($command->findings, static fn (ReportFinding $finding): bool => ReadinessReportWriter::GAP === $finding->status));
        $this->auditor->record(
            'readiness.report_submitted',
            AuditOutcome::Success,
            [
                'discoveryRunId' => (string) $run->id,
                'projectId' => (string) $project->id,
                'cardId' => (string) $cardId,
                'documentId' => (string) $document->id,
                'findingCount' => \count($command->findings),
                'gapCount' => $gaps,
                'proposalCount' => \count($command->proposals),
                'tickableCount' => \count(array_filter($command->proposals, static fn (ReportProposal $proposal): bool => null === $proposal->openCardNumber)),
            ],
            new AuditSubject('discovery_run', (string) $run->id),
        );
        // The rule reads the run state, so the engine evaluates the card again now that the run reported.
        if ($this->evaluations->isOn()) {
            $this->evaluations->forCards([$cardId]);
        }

        return $document;
    }

    /**
     * Refuses a report the app cannot store or show, before anything is written.
     *
     * @return list<CardType> the type of each proposal, in order
     */
    private function validate(SubmitReadinessReportCommand $command): array
    {
        $texts = [$command->workflow, $command->summary];
        foreach ($command->findings as $finding) {
            if ('' === trim($finding->check) || '' === trim($finding->evidence)
                || !\in_array($finding->status, [ReadinessReportWriter::READY, ReadinessReportWriter::GAP], true)) {
                throw new DomainErrors(['findings' => self::FINDING_INVALID]);
            }
            array_push($texts, $finding->check, $finding->evidence);
        }

        $types = [];
        $keys = [];
        foreach ($command->proposals as $proposal) {
            $key = trim($proposal->key);
            $title = ReadinessReportWriter::optionLabel($proposal->title);
            if ('' === $key || mb_strlen($key) > DiscoveryProposal::MAX_KEY_LENGTH || '' === $title || mb_strlen($title) > Card::MAX_TITLE_LENGTH) {
                throw new DomainErrors(['proposals' => self::PROPOSAL_INVALID]);
            }
            $type = CardType::tryFrom($proposal->type);
            if (null === $type || !\in_array($type, self::PROPOSAL_TYPES, true)) {
                throw new DomainErrors(['proposals' => self::PROPOSAL_TYPE]);
            }
            if (isset($keys[$key])) {
                throw new DomainErrors(['proposals' => self::PROPOSAL_DUPLICATE]);
            }
            $keys[$key] = true;
            $types[] = $type;

            if (null !== $proposal->openCardNumber) {
                $card = $this->cards->findOneByProjectAndNumber($command->project, $proposal->openCardNumber);
                if (null === $card || !$this->cards->isInOpenColumn($card)) {
                    throw new DomainErrors(['proposals' => self::CARD_NOT_OPEN]);
                }
            }
            array_push($texts, $title, $proposal->body);
        }

        // A decision block in worker text would take the place of the one the report writes.
        foreach ($texts as $text) {
            if (1 === preg_match('~<!--\s*/?\s*decision~i', $text)) {
                throw new DomainErrors(['report' => self::MARKUP_NOT_ALLOWED]);
            }
        }

        return $types;
    }
}
