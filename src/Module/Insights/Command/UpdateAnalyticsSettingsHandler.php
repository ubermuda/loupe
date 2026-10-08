<?php

declare(strict_types=1);

namespace App\Module\Insights\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Service\ToolCallCollectionSettings;
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
    public const string INVALID_SUBCOMMAND_PROGRAMS = 'insights.settings.error.invalid_subcommand_programs';
    public const string TOO_MANY_SUBCOMMAND_PROGRAMS = 'insights.settings.error.too_many_subcommand_programs';

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
        $programs = $command->changeSubcommandPrograms ? array_values(array_unique($command->subcommandPrograms ?? [])) : [];
        if (\count($programs) > ToolCallCollectionSettings::MAX_PROJECT_PROGRAMS) {
            $errors['subcommandPrograms'] = self::TOO_MANY_SUBCOMMAND_PROGRAMS;
        } elseif ([] !== array_filter($programs, static fn (string $program): bool => 1 !== preg_match(ToolCallCollectionSettings::PROGRAM_PATTERN, $program))) {
            $errors['subcommandPrograms'] = self::INVALID_SUBCOMMAND_PROGRAMS;
        }
        if ([] !== $errors) {
            throw new DomainErrors($errors);
        }

        $project = $command->project;
        $projectId = (string) ($project->id ?? throw new \LogicException('A stored project has an id.'));

        $settings = $this->em->wrapInTransaction(function () use ($command, $project, $programs): InsightsProjectSettings {
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
            if ($command->changeSubcommandPrograms) {
                $settings->subcommandPrograms = [] === $programs ? null : $programs;
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
                'subcommandProgramCount' => \count($settings->subcommandPrograms ?? []),
            ],
            new AuditSubject('project', $projectId),
        );
    }
}
