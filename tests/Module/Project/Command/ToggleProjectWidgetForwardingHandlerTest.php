<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Command\ToggleProjectWidgetForwardingCommand;
use App\Module\Project\Command\ToggleProjectWidgetForwardingHandler;
use App\Module\Project\Entity\Project;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\AuditActorProviderInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class ToggleProjectWidgetForwardingHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ToggleProjectWidgetForwardingHandler $handler;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $actors = self::getContainer()->get(AuditActorProviderInterface::class);
        self::assertInstanceOf(AuditActorProviderInterface::class, $actors);
        $this->audit = new RecordingAuditor($actors);
        $this->handler = new ToggleProjectWidgetForwardingHandler($this->em, $this->audit->auditor);
    }

    public function test_a_new_project_starts_collect_only(): void
    {
        self::assertFalse($this->project('forwarding-a@example.com')->forwardsToAgent);
    }

    public function test_toggling_turns_forwarding_on_and_back_off(): void
    {
        $project = $this->project('forwarding-b@example.com');

        self::assertTrue(($this->handler)(new ToggleProjectWidgetForwardingCommand($project)));
        self::assertTrue($project->forwardsToAgent);

        self::assertFalse(($this->handler)(new ToggleProjectWidgetForwardingCommand($project)));
        self::assertFalse($project->forwardsToAgent);
    }

    public function test_the_new_state_is_persisted(): void
    {
        $project = $this->project('forwarding-c@example.com');
        ($this->handler)(new ToggleProjectWidgetForwardingCommand($project));
        $projectId = $project->id;

        $this->em->clear();

        $reloaded = $this->em->find(Project::class, $projectId);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->forwardsToAgent);
    }

    public function test_each_toggle_is_recorded_with_the_state_it_left_behind(): void
    {
        $project = $this->project('forwarding-audit@example.com');

        ($this->handler)(new ToggleProjectWidgetForwardingCommand($project));

        $record = $this->audit->record('project.widget_forwarding_toggled');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('project', $record->subject->type);
        self::assertSame((string) $project->id, $record->subject->id);
        self::assertSame([
            'projectId' => (string) $project->id,
            'forwardsToAgent' => true,
        ], $record->context);

        self::assertSame(['project.widget_forwarding_toggled'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(ToggleProjectWidgetForwardingHandler::class);
    }

    /** @param non-empty-string $email */
    private function project(string $email): Project
    {
        $owner = new User(fullName: 'U', email: $email, password: 'x');
        $this->em->persist($owner);
        $project = new Project($owner, 'forwarding-site');
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
