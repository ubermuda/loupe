<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Command;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Audit\AuditActorProviderInterface;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Project\Command\RegenerateProjectWidgetTokenCommand;
use App\Module\Project\Command\RegenerateProjectWidgetTokenHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RegenerateProjectWidgetTokenHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private RegenerateProjectWidgetTokenHandler $handler;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $projects = self::getContainer()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $projects);
        $actors = self::getContainer()->get(AuditActorProviderInterface::class);
        self::assertInstanceOf(AuditActorProviderInterface::class, $actors);
        $this->audit = new RecordingAuditor($actors);
        $this->handler = new RegenerateProjectWidgetTokenHandler($this->em, $projects, $this->audit->auditor);
    }

    public function test_a_regenerated_token_is_recorded_on_the_security_channel(): void
    {
        $project = $this->project('regen-widget-audit@example.com', 'audited');
        $replacedId = $this->mintToken($project);

        $raw = ($this->handler)(new RegenerateProjectWidgetTokenCommand($project));

        $token = $project->widgetToken;
        self::assertNotNull($token);
        self::assertTrue($token->matches($raw));
        self::assertNotSame($replacedId, (string) $token->id);

        $record = $this->audit->record('project.widget_token_regenerated');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_SECURITY, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('api_token', $record->subject->type);
        self::assertSame((string) $token->id, $record->subject->id);
        self::assertSame([
            'projectId' => (string) $project->id,
            'tokenId' => (string) $token->id,
            'previousTokenId' => $replacedId,
        ], $record->context);

        self::assertSame(['project.widget_token_regenerated'], $this->audit->securityLogLines());
        self::assertSame([], $this->audit->domainLogLines());
    }

    public function test_regenerating_without_a_previous_token_records_a_null_predecessor(): void
    {
        $project = $this->project('regen-widget-audit-fresh@example.com', 'fresh');

        ($this->handler)(new RegenerateProjectWidgetTokenCommand($project));

        $record = $this->audit->record('project.widget_token_regenerated');
        self::assertNull($record->context['previousTokenId']);
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(RegenerateProjectWidgetTokenHandler::class);
    }

    /** The id of the token the regeneration must replace. */
    private function mintToken(Project $project): string
    {
        [$token] = ApiToken::issue($project->owner, 'Widget: seeded', ApiTokenScope::SiteReview);
        $project->widgetToken = $token;
        $this->em->persist($token);
        $this->em->flush();

        $id = (string) $token->id;
        self::assertNotSame('', $id);

        return $id;
    }

    /** @param non-empty-string $email */
    private function project(string $email, string $name): Project
    {
        $owner = new User(fullName: 'U', email: $email, password: 'x');
        $project = new Project($owner, $name);
        $this->em->persist($owner);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
