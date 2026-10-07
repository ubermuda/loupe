<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Command\OpenWorkRequestCommand;
use App\Module\Bridge\Command\OpenWorkRequestHandler;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\AnalysisScope;
use App\Module\Insights\Entity\AnalysisTopic;
use App\Module\Insights\Service\AnalysisSettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Stores a waiting analysis, then opens the work request that offers it to the bridges. */
final readonly class StartAnalysisHandler
{
    public const string QUESTION_TOO_LONG = 'insights.analysis.error.question_too_long';
    public const string QUESTION_REQUIRED = 'insights.analysis.error.question_required';
    public const string INVALID_MODEL = 'insights.analysis.error.invalid_model';
    public const string INVALID_EFFORT = 'insights.analysis.error.invalid_effort';

    public const string WORK_KIND = 'analysis';
    public const string CAPABILITY = 'subject-analysis';
    public const string RULE_ID = 'insights.analysis';
    public const string REQUEST_REFUSED = 'request-refused';

    public function __construct(
        private EntityManagerInterface $em,
        private OpenWorkRequestHandler $openWorkRequest,
        private AnalysisSettings $settings,
        private ClockInterface $clock,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(StartAnalysisCommand $command): Analysis
    {
        $question = null === $command->question ? null : trim($command->question);
        if ('' === $question) {
            $question = null;
        }

        $errors = [];
        if (null !== $question && mb_strlen($question) > Analysis::MAX_QUESTION_LENGTH) {
            $errors['question'] = self::QUESTION_TOO_LONG;
        }
        if (null === $question && AnalysisTopic::Question === $command->topic) {
            $errors['question'] = self::QUESTION_REQUIRED;
        }
        if (null !== $command->model && 1 !== preg_match(WorkRequest::MODEL_PATTERN, $command->model)) {
            $errors['model'] = self::INVALID_MODEL;
        }
        if (null !== $command->effort && !\in_array($command->effort, WorkRequest::EFFORTS, true)) {
            $errors['effort'] = self::INVALID_EFFORT;
        }
        if ([] !== $errors) {
            throw new DomainErrors($errors);
        }

        $analysis = new Analysis(
            project: $command->project,
            topic: $command->topic,
            scope: new AnalysisScope($command->range),
            question: $question,
            model: $command->model ?? $this->settings->modelFor($command->project),
            effort: $command->effort ?? $this->settings->effortFor($command->project),
            createdAt: $this->clock->now(),
        );
        $this->em->persist($analysis);
        $this->em->flush();
        $analysisId = $analysis->id ?? throw new \LogicException('A stored analysis has an id.');

        // Not inside a transaction of ours: Bridge announces the request after its own commit.
        try {
            $request = ($this->openWorkRequest)(new OpenWorkRequestCommand(
                project: $command->project,
                subject: new WorkSubject(Analysis::SUBJECT_TYPE, $analysisId),
                cardNumber: null,
                kind: self::WORK_KIND,
                capability: self::CAPABILITY,
                ruleId: self::RULE_ID,
                context: new WorkRequestContext(),
                model: $analysis->model,
                effort: $analysis->effort,
            ));
        } catch (DomainErrors $e) {
            $analysis->fail(self::REQUEST_REFUSED, $this->clock->now());
            $this->em->flush();

            throw $e;
        }

        $analysis->workRequestId = $request->id;
        $this->em->flush();

        $this->auditor->record(
            'insights.analysis_started',
            AuditOutcome::Success,
            [
                'analysisId' => (string) $analysisId,
                'projectId' => (string) $command->project->id,
                'workRequestId' => (string) $request->id,
                'topic' => $analysis->topic->value,
                'range' => $command->range->value,
                'model' => $analysis->model,
                'effort' => $analysis->effort,
            ],
            new AuditSubject('analysis', (string) $analysisId),
        );

        return $analysis;
    }
}
