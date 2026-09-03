<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\MintProjectWidgetTokenCommand;
use App\Module\Project\Command\MintProjectWidgetTokenHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\AuditActorProviderInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class MintProjectWidgetTokenHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MintProjectWidgetTokenHandler $handler;
    private ProjectRepository $projects;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $projects = self::getContainer()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $projects);
        $this->projects = $projects;
        $actors = self::getContainer()->get(AuditActorProviderInterface::class);
        self::assertInstanceOf(AuditActorProviderInterface::class, $actors);
        $this->audit = new RecordingAuditor($actors);
        $this->handler = new MintProjectWidgetTokenHandler($this->em, $projects, $this->audit->auditor);
    }

    public function test_label_is_truncated_to_fit_the_column_for_long_project_names(): void
    {
        $project = $this->project('mint-widget-a@example.com', str_repeat('n', 100));

        $raw = ($this->handler)(new MintProjectWidgetTokenCommand($project));

        self::assertNotNull($project->widgetToken);
        self::assertSame(ApiTokenScope::SiteReview, $project->widgetToken->scope);
        self::assertTrue($project->widgetToken->matches($raw));
        self::assertSame('Widget: '.str_repeat('n', 92), $project->widgetToken->label);
        self::assertLessThanOrEqual(100, mb_strlen($project->widgetToken->label));
    }

    public function test_a_minted_token_is_recorded_on_the_security_channel(): void
    {
        $project = $this->project('mint-widget-audit@example.com', 'audited');

        ($this->handler)(new MintProjectWidgetTokenCommand($project));

        $token = $project->widgetToken;
        self::assertNotNull($token);

        $record = $this->audit->record('project.widget_token_minted');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_SECURITY, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('api_token', $record->subject->type);
        self::assertSame((string) $token->id, $record->subject->id);
        self::assertSame([
            'projectId' => (string) $project->id,
            'tokenId' => (string) $token->id,
        ], $record->context);

        self::assertSame(['project.widget_token_minted'], $this->audit->securityLogLines());
        self::assertSame([], $this->audit->domainLogLines());
    }

    public function test_a_rejected_second_mint_is_recorded_as_a_refusal(): void
    {
        $project = $this->project('mint-widget-audit-refused@example.com', 'refused');
        ($this->handler)(new MintProjectWidgetTokenCommand($project));

        try {
            ($this->handler)(new MintProjectWidgetTokenCommand($project));
            self::fail('Expected DomainErrors for a second widget token mint.');
        } catch (DomainErrors $e) {
            self::assertSame(['token' => 'project.error.widget_token_already_minted'], $e->errors);
        }

        $record = $this->audit->record('project.widget_token_mint_rejected');
        self::assertSame(AuditOutcome::Refused, $record->outcome);
        self::assertSame(Auditor::CATEGORY_SECURITY, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('project', $record->subject->type);
        self::assertSame((string) $project->id, $record->subject->id);
        self::assertSame(['projectId' => (string) $project->id], $record->context);

        self::assertSame(
            ['project.widget_token_minted', 'project.widget_token_mint_rejected'],
            $this->audit->securityLogLines(),
        );
        self::assertSame([], $this->audit->domainLogLines());
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(MintProjectWidgetTokenHandler::class);
    }

    /**
     * The sink drains outside the business transaction, so a record made inside
     * one outlives its rollback. A commit that fails after the mint must
     * therefore leave no record claiming a token was minted.
     */
    public function test_a_commit_that_fails_after_the_mint_records_nothing(): void
    {
        $project = $this->project('mint-widget-rollback@example.com', 'rolled-back');
        $handler = new MintProjectWidgetTokenHandler($this->failingCommitEntityManager(), $this->projects, $this->audit->auditor);

        try {
            $handler(new MintProjectWidgetTokenCommand($project));
            self::fail('a failed commit must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('commit failed', $e->getMessage());
        }

        self::assertSame([], $this->audit->operations());
        self::assertSame([], $this->audit->securityLogLines());
    }

    /**
     * Runs the closure, then throws as a failing flush or commit would: the
     * state change has happened in memory and nothing was kept.
     */
    private function failingCommitEntityManager(): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(
            static function (callable $callback) use ($em): never {
                $callback($em);

                throw new \RuntimeException('commit failed');
            },
        );

        return $em;
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
