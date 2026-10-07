<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\Service;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\Project\Entity\Project;
use App\Module\Project\Service\WorkflowTemplateChoices;
use App\Module\Project\Workshop\WorkshopConnectionsProviderInterface;
use App\Module\Project\Workshop\WorkshopReadiness;
use App\Module\Project\Workshop\WorkshopReadinessRow;
use App\Module\Readiness\Service\ReadinessChecklist;
use App\Module\Workflow\Entity\WorkflowBinding;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ReadinessChecklistTest extends KernelTestCase
{
    use BridgeScenario;

    private static int $installationId = 9_100_000;

    public function test_a_hidden_guide_has_no_checklist(): void
    {
        $project = $this->newProject('readiness-hidden@example.com');
        $project->readinessGuideHiddenAt = new \DateTimeImmutable();

        self::assertNull($this->checklist()->forProject($project));
    }

    public function test_a_new_project_lists_six_open_checks_in_order(): void
    {
        $project = $this->newProject('readiness-new@example.com');

        $readiness = $this->readiness($this->checklist(), $project);

        self::assertSame(['agent', 'workflow', 'bridge', 'github', 'agent_account', 'repository'], array_map(static fn (WorkshopReadinessRow $row): string => $row->key, $readiness->rows));
        self::assertSame(0, $readiness->doneCount());
        self::assertSame(6, $readiness->total());
        $connect = '/projects/'.$project->id.'/connect';
        self::assertSame($connect, $this->row($readiness, 'agent')->actionUrl);
        self::assertSame($connect, $this->row($readiness, 'bridge')->actionUrl);
        self::assertNull($this->row($readiness, 'workflow')->actionUrl);
        self::assertNull($this->row($readiness, 'workflow')->detail);
        self::assertNull($this->row($readiness, 'github')->actionUrl);
        self::assertSame('/projects/'.$project->id.'/readiness/agent-account', $this->row($readiness, 'agent_account')->actionUrl);
        self::assertNull($this->row($readiness, 'repository')->actionUrl);
    }

    public function test_the_github_check_offers_the_install_when_the_instance_has_an_app(): void
    {
        $project = $this->newProject('readiness-app@example.com');

        $row = $this->row($this->readiness($this->checklist(configured: true), $project), 'github');

        self::assertFalse($row->done);
        self::assertSame('/projects/'.$project->id.'/github/install', $row->actionUrl);
        self::assertSame('readiness.row.github.action', $row->actionLabel);
    }

    public function test_a_ready_project_marks_the_first_four_checks_done(): void
    {
        $em = $this->em();
        $project = $this->newProject('readiness-ready@example.com');
        $project->agentFirstSeenAt = new \DateTimeImmutable();
        $em->persist(new WorkflowBinding($project, 'simple', 1, []));
        $this->seedBridge($em, $project->owner, projects: [(string) $project->id]);
        $em->persist(new GitHubInstallation($project, ++self::$installationId, 'acme', GitHubRepositorySelection::Selected));
        $em->persist(new ForgeRepository($project, 'github', 'ext-ready', 'acme/app', ForgeRepositorySource::Installation, (string) self::$installationId));
        $em->flush();

        $readiness = $this->readiness($this->checklist(configured: true), $project);

        self::assertSame([true, true, true, true, false, false], array_map(static fn (WorkshopReadinessRow $row): bool => $row->done, $readiness->rows));
        self::assertSame(4, $readiness->doneCount());
        self::assertSame('workflow.template.simple.label', $this->row($readiness, 'workflow')->detail);
        foreach (array_slice($readiness->rows, 0, 4) as $row) {
            self::assertNull($row->actionUrl, $row->key);
        }
    }

    public function test_the_agent_account_check_asks_for_a_login_first(): void
    {
        $project = $this->newProject('readiness-account-none@example.com');
        $this->seedPushingBridge($project, 'acme-agent');

        $row = $this->row($this->readiness($this->checklist(), $project), 'agent_account');

        self::assertFalse($row->done);
        self::assertSame('readiness.row.agent_account.no_login', $row->status);
        self::assertSame('readiness.row.agent_account.action', $row->actionLabel);
        self::assertSame('/projects/'.$project->id.'/readiness/agent-account', $row->actionUrl);
    }

    public function test_the_agent_account_check_waits_for_a_bridge_that_pushes_as_the_login(): void
    {
        $project = $this->newProject('readiness-account-no-bridge@example.com');
        $project->agentGitHubLogin = 'acme-agent';

        $row = $this->row($this->readiness($this->checklist(), $project), 'agent_account');

        self::assertFalse($row->done);
        self::assertSame('readiness.row.agent_account.no_push', $row->status);
        self::assertSame(['%login%' => 'acme-agent'], $row->statusParameters);
        self::assertSame('/projects/'.$project->id.'/readiness/agent-account', $row->actionUrl);
    }

    public function test_a_bridge_that_pushes_as_another_login_does_not_count(): void
    {
        $project = $this->newProject('readiness-account-other@example.com');
        $project->agentGitHubLogin = 'acme-agent';
        $this->seedPushingBridge($project, 'acme-bot');

        self::assertFalse($this->row($this->readiness($this->checklist(), $project), 'agent_account')->done);
    }

    public function test_a_quiet_bridge_that_pushes_as_the_login_does_not_count(): void
    {
        $project = $this->newProject('readiness-account-quiet@example.com');
        $project->agentGitHubLogin = 'acme-agent';
        $this->seedPushingBridge($project, 'acme-agent', new \DateTimeImmutable('-1 day'));

        $row = $this->row($this->readiness($this->checklist(), $project), 'agent_account');

        self::assertFalse($row->done);
        self::assertSame('readiness.row.agent_account.no_push', $row->status);
    }

    public function test_a_running_bridge_that_pushes_as_the_login_in_another_case_marks_the_check_done(): void
    {
        $project = $this->newProject('readiness-account-done@example.com');
        $project->agentGitHubLogin = 'Acme-Agent';
        $this->seedPushingBridge($project, 'acme-agent');

        $row = $this->row($this->readiness($this->checklist(), $project), 'agent_account');

        self::assertTrue($row->done);
        self::assertSame('readiness.row.agent_account.done', $row->status);
        self::assertSame(['%login%' => 'Acme-Agent'], $row->statusParameters);
        self::assertNull($row->actionUrl);
    }

    public function test_a_template_with_no_label_shows_its_key(): void
    {
        $em = $this->em();
        $project = $this->newProject('readiness-custom@example.com');
        $em->persist(new WorkflowBinding($project, 'house-rules', 1, []));
        $em->flush();

        self::assertSame('house-rules', $this->row($this->readiness($this->checklist(), $project), 'workflow')->detail);
    }

    public function test_a_quiet_bridge_does_not_count_as_running(): void
    {
        $project = $this->newProject('readiness-quiet@example.com');
        $this->seedBridge($this->em(), $project->owner, projects: [(string) $project->id], lastSeenAt: new \DateTimeImmutable('-1 day'));

        self::assertFalse($this->row($this->readiness($this->checklist(), $project), 'bridge')->done);
    }

    public function test_the_github_check_needs_a_live_installation_and_an_installed_repository(): void
    {
        $em = $this->em();
        $hookOnly = $this->newProject('readiness-hook@example.com');
        $em->persist(new GitHubInstallation($hookOnly, ++self::$installationId, 'acme', GitHubRepositorySelection::All));
        $em->persist(new ForgeRepository($hookOnly, 'github', 'ext-hook', 'acme/hook', ForgeRepositorySource::Hook));
        $suspended = $this->newProject('readiness-suspended@example.com');
        $installation = new GitHubInstallation($suspended, ++self::$installationId, 'acme', GitHubRepositorySelection::All);
        $installation->suspendedAt = new \DateTimeImmutable();
        $em->persist($installation);
        $em->persist(new ForgeRepository($suspended, 'github', 'ext-suspended', 'acme/suspended', ForgeRepositorySource::Installation, (string) $installation->installationId));
        $removed = $this->newProject('readiness-removed@example.com');
        $installation = new GitHubInstallation($removed, ++self::$installationId, 'acme', GitHubRepositorySelection::All);
        $installation->removedAt = new \DateTimeImmutable();
        $em->persist($installation);
        $em->persist(new ForgeRepository($removed, 'github', 'ext-removed', 'acme/removed', ForgeRepositorySource::Installation, (string) $installation->installationId));
        $em->flush();

        $checklist = $this->checklist();
        foreach ([$hookOnly, $suspended, $removed] as $project) {
            self::assertFalse($this->row($this->readiness($checklist, $project), 'github')->done, $project->name);
        }
    }

    public function test_a_repository_of_a_suspended_installation_does_not_count_for_a_live_one(): void
    {
        $em = $this->em();
        $project = $this->newProject('readiness-split@example.com');
        $suspended = new GitHubInstallation($project, ++self::$installationId, 'acme', GitHubRepositorySelection::Selected);
        $suspended->suspendedAt = new \DateTimeImmutable();
        $em->persist($suspended);
        $em->persist(new GitHubInstallation($project, ++self::$installationId, 'other', GitHubRepositorySelection::Selected));
        $em->persist(new ForgeRepository($project, 'github', 'ext-split', 'acme/app', ForgeRepositorySource::Installation, (string) $suspended->installationId));
        $em->flush();

        self::assertFalse($this->row($this->readiness($this->checklist(), $project), 'github')->done);
    }

    private function seedPushingBridge(Project $project, string $login, \DateTimeImmutable $lastSeenAt = new \DateTimeImmutable()): void
    {
        $bridge = $this->seedBridge($this->em(), $project->owner, projects: [(string) $project->id], lastSeenAt: $lastSeenAt);
        $bridge->pushLogin = $login;
        $this->em()->flush();
    }

    /** @param non-empty-string $email */
    private function newProject(string $email): Project
    {
        $em = $this->em();

        return $this->project($em, $this->user($em, $email), 'Readiness '.$email);
    }

    private function checklist(bool $configured = false): ReadinessChecklist
    {
        $container = static::getContainer();
        $value = $configured ? 'set' : null;

        return new ReadinessChecklist(
            $container->get(WorkshopConnectionsProviderInterface::class),
            $container->get(WorkflowBindingRepository::class),
            $container->get(WorkflowTemplateChoices::class),
            $container->get(GitHubInstallationRepository::class),
            $container->get(ForgeRepositoryRepository::class),
            new GitHubAppConfiguration($value, $value, $value, $value, $value, $value),
            $container->get(UrlGeneratorInterface::class),
        );
    }

    private function readiness(ReadinessChecklist $checklist, Project $project): WorkshopReadiness
    {
        $readiness = $checklist->forProject($project);
        self::assertNotNull($readiness);

        return $readiness;
    }

    private function row(WorkshopReadiness $readiness, string $key): WorkshopReadinessRow
    {
        foreach ($readiness->rows as $row) {
            if ($key === $row->key) {
                return $row;
            }
        }

        self::fail('No row '.$key);
    }
}
