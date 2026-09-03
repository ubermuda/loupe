<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Audit\AuditActorProviderInterface;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Project\Command\ToggleProjectWidgetForwardingCommand;
use App\Module\Project\Command\ToggleProjectWidgetForwardingHandler;
use App\Module\Project\Entity\Project;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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

    public function test_a_freshly_minted_token_starts_collect_only(): void
    {
        $project = $this->project('forwarding-a@example.com');

        self::assertNotNull($project->widgetToken);
        self::assertFalse($project->widgetToken->forwardsToAgent);
    }

    public function test_toggling_turns_forwarding_on_and_back_off(): void
    {
        $project = $this->project('forwarding-b@example.com');

        self::assertTrue(($this->handler)(new ToggleProjectWidgetForwardingCommand($project)));
        self::assertNotNull($project->widgetToken);
        self::assertTrue($project->widgetToken->forwardsToAgent);

        self::assertFalse(($this->handler)(new ToggleProjectWidgetForwardingCommand($project)));
        self::assertFalse($project->widgetToken->forwardsToAgent);
    }

    public function test_the_new_state_is_persisted(): void
    {
        $project = $this->project('forwarding-c@example.com');
        ($this->handler)(new ToggleProjectWidgetForwardingCommand($project));
        $tokenId = $project->widgetToken?->id;

        $this->em->clear();

        $reloaded = $this->em->find(ApiToken::class, $tokenId);
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->forwardsToAgent);
    }

    public function test_a_project_without_a_widget_token_is_a_domain_error(): void
    {
        $project = $this->project('forwarding-d@example.com', withToken: false);

        $this->expectException(DomainErrors::class);
        ($this->handler)(new ToggleProjectWidgetForwardingCommand($project));
    }

    public function test_each_toggle_is_recorded_with_the_state_it_left_behind(): void
    {
        $project = $this->project('forwarding-audit@example.com');
        $token = $project->widgetToken;
        self::assertNotNull($token);

        ($this->handler)(new ToggleProjectWidgetForwardingCommand($project));

        $record = $this->audit->record('project.widget_forwarding_toggled');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('api_token', $record->subject->type);
        self::assertSame((string) $token->id, $record->subject->id);
        self::assertSame([
            'projectId' => (string) $project->id,
            'tokenId' => (string) $token->id,
            'forwardsToAgent' => true,
        ], $record->context);

        self::assertSame(['project.widget_forwarding_toggled'], $this->audit->domainLogLines());
        self::assertSame([], $this->audit->securityLogLines());
    }

    public function test_a_missing_widget_token_records_nothing(): void
    {
        $project = $this->project('forwarding-audit-none@example.com', withToken: false);

        try {
            ($this->handler)(new ToggleProjectWidgetForwardingCommand($project));
            self::fail('Expected DomainErrors for a project without a widget token.');
        } catch (DomainErrors) {
        }

        self::assertSame([], $this->audit->operations());
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(ToggleProjectWidgetForwardingHandler::class);
    }

    /** @param non-empty-string $email */
    private function project(string $email, bool $withToken = true): Project
    {
        $owner = new User(fullName: 'U', email: $email, password: 'x');
        $this->em->persist($owner);
        $project = new Project($owner, 'forwarding-site');

        if ($withToken) {
            [$token] = ApiToken::issue($owner, 'Widget', ApiTokenScope::SiteReview);
            $project->widgetToken = $token;
            $this->em->persist($token);
        }

        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
