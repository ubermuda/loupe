<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\Proposal;
use App\Module\Insights\Entity\ProposalKind;
use App\Module\Insights\Repository\AnalysisRepository;
use App\Module\Insights\Service\BucketRuleWriter;
use App\Module\Review\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Completes an analysis with its report document and the proposals the agent makes. */
final readonly class ReportAnalysisHandler
{
    public const int MAX_PROPOSALS = 20;

    public const string UNKNOWN_ANALYSIS = 'insights.analysis.error.unknown';
    public const string FINISHED = 'insights.analysis.error.finished';
    public const string UNKNOWN_DOCUMENT = 'insights.analysis.error.document_unknown';
    public const string TOO_MANY_PROPOSALS = 'insights.analysis.error.too_many_proposals';
    public const string INVALID_KIND = 'insights.proposal.error.invalid_kind';
    public const string TITLE_BLANK = 'insights.proposal.error.title_blank';
    public const string TITLE_TOO_LONG = 'insights.proposal.error.title_too_long';
    public const string BUCKET_RULE_INVALID = 'insights.proposal.error.report_bucket_rule_invalid';
    public const string BODY_BLANK = 'insights.proposal.error.body_blank';
    public const string SAVING_TOO_LONG = 'insights.proposal.error.estimated_saving_too_long';

    public function __construct(
        private AnalysisRepository $analyses,
        private DocumentRepository $documents,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(ReportAnalysisCommand $command): Analysis
    {
        $projectId = (string) ($command->project->id ?? throw new \LogicException('A stored project has an id.'));
        $analysis = $this->analyses->findOneByIdAndProject($command->analysisId, $command->project);
        if (null === $analysis) {
            throw new DomainErrors(['analysisId' => self::UNKNOWN_ANALYSIS]);
        }
        $document = $this->documents->findOneByIdAndProjectId($command->documentId, $projectId);
        $errors = self::proposalErrors($command->proposals);
        if (null === $document) {
            $errors['documentId'] = self::UNKNOWN_DOCUMENT;
        }
        if ($analysis->state->isFinished()) {
            $errors['analysisId'] = self::FINISHED;
        }
        if ([] !== $errors) {
            throw new DomainErrors($errors);
        }
        $documentId = $document->id ?? throw new \LogicException('A stored document has an id.');
        $analysisId = $analysis->id ?? throw new \LogicException('A stored analysis has an id.');

        // Locked, so a settle of the work request that races the report waits and then reads the final state.
        $completed = $this->em->wrapInTransaction(function () use ($command, $analysisId, $documentId): ?Analysis {
            $locked = $this->analyses->findOneLocked($analysisId);
            if (null === $locked || $locked->state->isFinished()) {
                return null;
            }
            $locked->complete($documentId, $this->clock->now());
            foreach ($command->proposals as $position => $proposal) {
                $this->em->persist(new Proposal(
                    analysis: $locked,
                    kind: ProposalKind::from($proposal->kind),
                    title: trim($proposal->title),
                    body: trim($proposal->body),
                    payload: $proposal->payload,
                    estimatedSaving: self::saving($proposal),
                    position: $position,
                ));
            }
            $this->em->flush();

            return $locked;
        });
        if (null === $completed) {
            throw new DomainErrors(['analysisId' => self::FINISHED]);
        }

        $this->auditor->record(
            'insights.analysis_reported',
            AuditOutcome::Success,
            [
                'analysisId' => (string) $analysisId,
                'projectId' => $projectId,
                'documentId' => (string) $documentId,
                'proposals' => \count($command->proposals),
            ],
            new AuditSubject('analysis', (string) $analysisId),
        );

        return $completed;
    }

    /**
     * @param list<ReportedProposal> $proposals
     *
     * @return array<string, string>
     */
    private static function proposalErrors(array $proposals): array
    {
        if (\count($proposals) > self::MAX_PROPOSALS) {
            return ['proposals' => self::TOO_MANY_PROPOSALS];
        }
        foreach ($proposals as $proposal) {
            $title = trim($proposal->title);
            $error = match (true) {
                null === ProposalKind::tryFrom($proposal->kind) => self::INVALID_KIND,
                '' === $title => self::TITLE_BLANK,
                mb_strlen($title) > Proposal::MAX_TITLE_LENGTH => self::TITLE_TOO_LONG,
                '' === trim($proposal->body) => self::BODY_BLANK,
                ProposalKind::BucketRule->value === $proposal->kind && !self::hasValidRule($proposal->payload) => self::BUCKET_RULE_INVALID,
                mb_strlen(self::saving($proposal) ?? '') > Proposal::MAX_ESTIMATED_SAVING_LENGTH => self::SAVING_TOO_LONG,
                default => null,
            };
            if (null !== $error) {
                return ['proposals' => $error];
            }
        }

        return [];
    }

    /** @param array<mixed>|null $payload */
    private static function hasValidRule(?array $payload): bool
    {
        $pattern = $payload['pattern'] ?? null;
        $bucket = $payload['bucket'] ?? null;

        return \is_string($pattern) && \is_string($bucket) && [] === BucketRuleWriter::errors($pattern, $bucket);
    }

    private static function saving(ReportedProposal $proposal): ?string
    {
        $saving = null === $proposal->estimatedSaving ? '' : trim($proposal->estimatedSaving);

        return '' === $saving ? null : $saving;
    }
}
