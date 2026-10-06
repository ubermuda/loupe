<?php

declare(strict_types=1);

namespace App\Module\Readiness\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\Project\Entity\Project;
use App\Module\Project\Service\WorkflowTemplateChoices;
use App\Module\Project\Workshop\WorkshopConnection;
use App\Module\Project\Workshop\WorkshopConnectionsProviderInterface;
use App\Module\Project\Workshop\WorkshopReadiness;
use App\Module\Project\Workshop\WorkshopReadinessProviderInterface;
use App\Module\Project\Workshop\WorkshopReadinessRow;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsAlias(WorkshopReadinessProviderInterface::class)]
final readonly class ReadinessChecklist implements WorkshopReadinessProviderInterface
{
    public function __construct(
        private WorkshopConnectionsProviderInterface $connections,
        private WorkflowBindingRepository $workflowBindings,
        private WorkflowTemplateChoices $templateChoices,
        private GitHubInstallationRepository $gitHubInstallations,
        private ForgeRepositoryRepository $forgeRepositories,
        private GitHubAppConfiguration $gitHubApp,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[\Override]
    public function forProject(Project $project): ?WorkshopReadiness
    {
        if (null !== $project->readinessGuideHiddenAt) {
            return null;
        }

        $projectId = $project->id ?? throw new \LogicException('A stored project has an id.');
        $connect = $this->urls->generate('app_project_connect', ['id' => (string) $projectId]);

        $agent = null !== $project->agentFirstSeenAt;
        $binding = $this->workflowBindings->findOneByProjectId($projectId);
        $bridge = [] !== array_filter($this->connections->forProject($project), static fn (WorkshopConnection $connection): bool => !$connection->quiet);
        $github = $this->gitHubReady($project);
        $installUrl = $github || !$this->gitHubApp->isConfigured() ? null : $this->urls->generate('app_github_app_install', ['id' => (string) $projectId]);

        return new WorkshopReadiness([
            new WorkshopReadinessRow(
                'agent',
                'readiness.row.agent.label',
                $agent,
                $agent ? 'readiness.row.agent.done' : 'readiness.row.agent.open',
                actionLabel: $agent ? null : 'readiness.row.agent.action',
                actionUrl: $agent ? null : $connect,
            ),
            new WorkshopReadinessRow(
                'workflow',
                'readiness.row.workflow.label',
                null !== $binding,
                null !== $binding ? 'readiness.row.workflow.done' : 'readiness.row.workflow.open',
                detail: null === $binding ? null : $this->templateLabel($binding->templateKey),
            ),
            new WorkshopReadinessRow(
                'bridge',
                'readiness.row.bridge.label',
                $bridge,
                $bridge ? 'readiness.row.bridge.done' : 'readiness.row.bridge.open',
                actionLabel: $bridge ? null : 'readiness.row.bridge.action',
                actionUrl: $bridge ? null : $connect,
            ),
            new WorkshopReadinessRow(
                'github',
                'readiness.row.github.label',
                $github,
                $github ? 'readiness.row.github.done' : 'readiness.row.github.open',
                actionLabel: null === $installUrl ? null : 'readiness.row.github.action',
                actionUrl: $installUrl,
            ),
            new WorkshopReadinessRow('agent_account', 'readiness.row.agent_account.label', false, 'readiness.row.agent_account.open'),
            new WorkshopReadinessRow('repository', 'readiness.row.repository.label', false, 'readiness.row.repository.open'),
        ]);
    }

    private function gitHubReady(Project $project): bool
    {
        $live = array_filter(
            $this->gitHubInstallations->findByProject($project),
            static fn (GitHubInstallation $installation): bool => null === $installation->removedAt && null === $installation->suspendedAt,
        );
        if ([] === $live) {
            return false;
        }

        return [] !== array_filter(
            $this->forgeRepositories->findByProject($project),
            static fn (ForgeRepository $repository): bool => ForgeRepositorySource::Installation === $repository->source,
        );
    }

    /** A translation key for a shipped template, or the bare key of any other. */
    private function templateLabel(string $templateKey): string
    {
        foreach ($this->templateChoices->choices() as $choice) {
            if ($choice->key === $templateKey) {
                return $choice->label;
            }
        }

        return $templateKey;
    }
}
