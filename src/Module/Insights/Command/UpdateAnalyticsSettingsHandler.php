<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Insights\Entity\InsightsProjectSettings;
use App\Module\Insights\Repository\InsightsProjectSettingsRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Stores the analysis settings of a project, with the model and effort rules a work request applies. */
final readonly class UpdateAnalyticsSettingsHandler
{
    public const string INVALID_MODEL = 'insights.settings.error.invalid_model';
    public const string INVALID_EFFORT = 'insights.settings.error.invalid_effort';

    public function __construct(
        private EntityManagerInterface $em,
        private InsightsProjectSettingsRepository $insightsProjectSettings,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(UpdateAnalyticsSettingsCommand $command): void
    {
        $errors = [];
        if (null !== $command->model && 1 !== preg_match(WorkRequest::MODEL_PATTERN, $command->model)) {
            $errors['model'] = self::INVALID_MODEL;
        }
        if (null !== $command->effort && !\in_array($command->effort, WorkRequest::EFFORTS, true)) {
            $errors['effort'] = self::INVALID_EFFORT;
        }
        if ([] !== $errors) {
            throw new DomainErrors($errors);
        }

        $project = $command->project;
        $projectId = (string) ($project->id ?? throw new \LogicException('A stored project has an id.'));

        $settings = $this->em->wrapInTransaction(function () use ($command, $project): InsightsProjectSettings {
            // The lock keeps two first saves from both inserting a row.
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            $settings = $this->insightsProjectSettings->findForProject($project);
            if (null === $settings) {
                $settings = new InsightsProjectSettings($project);
                $this->em->persist($settings);
            }
            if ($command->changeModel) {
                $settings->defaultModel = $command->model;
            }
            if ($command->changeEffort) {
                $settings->defaultEffort = $command->effort;
            }
            if ($command->changeCollectFullText) {
                $settings->collectFullText = $command->collectFullText;
            }
            $this->em->flush();

            return $settings;
        });

        $this->auditor->record(
            'insights.settings_saved',
            AuditOutcome::Success,
            [
                'projectId' => $projectId,
                'model' => $settings->defaultModel,
                'effort' => $settings->defaultEffort,
                'collectFullText' => $settings->collectFullText,
            ],
            new AuditSubject('project', $projectId),
        );
    }
}
