<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Project\Command\MintProjectMcpTokenCommand;
use App\Module\Project\Command\MintProjectMcpTokenHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\AuditBundle\AuditActorProviderInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;

final class MintProjectMcpTokenHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MintProjectMcpTokenHandler $handler;
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
        $this->handler = new MintProjectMcpTokenHandler($this->em, $this->projects, $this->audit->auditor);
    }

    public function test_mints_mcp_token_bound_to_project(): void
    {
        $project = $this->project('mint-mcp-a@example.com', 'mcp-app');

        $raw = ($this->handler)(new MintProjectMcpTokenCommand($project));

        self::assertNotNull($project->mcpToken);
        self::assertSame(ApiTokenScope::Mcp, $project->mcpToken->scope);
        self::assertTrue($project->mcpToken->matches($raw));
        self::assertSame('MCP: mcp-app', $project->mcpToken->label);
    }

    public function test_second_mint_throws_domain_errors(): void
    {
        $project = $this->project('mint-mcp-b@example.com', 'once-only');
        ($this->handler)(new MintProjectMcpTokenCommand($project));

        try {
            ($this->handler)(new MintProjectMcpTokenCommand($project));
            self::fail('Expected DomainErrors for a second MCP token mint.');
        } catch (DomainErrors $e) {
            self::assertSame(['token' => 'project.error.mcp_token_already_minted'], $e->errors);
        }
    }

    public function test_minted_token_resolves_the_project_by_mcp_binding_only(): void
    {
        $project = $this->project('mint-mcp-c@example.com', 'resolve-me');

        ($this->handler)(new MintProjectMcpTokenCommand($project));

        $token = $project->mcpToken;
        self::assertNotNull($token);
        self::assertSame($project, $this->projects->findOneByMcpToken($token));
        self::assertNull($this->projects->findOneByWidgetToken($token), 'MCP token must not resolve through the widget binding');
    }

    public function test_label_is_truncated_to_fit_the_column_for_long_project_names(): void
    {
        $project = $this->project('mint-mcp-d@example.com', str_repeat('n', 100));

        ($this->handler)(new MintProjectMcpTokenCommand($project));

        self::assertNotNull($project->mcpToken);
        self::assertSame('MCP: '.str_repeat('n', 95), $project->mcpToken->label);
        self::assertLessThanOrEqual(100, mb_strlen($project->mcpToken->label));
    }

    public function test_a_minted_token_is_recorded_on_the_security_channel(): void
    {
        $project = $this->project('mint-mcp-audit@example.com', 'audited');

        ($this->handler)(new MintProjectMcpTokenCommand($project));

        $token = $project->mcpToken;
        self::assertNotNull($token);

        $record = $this->audit->record('project.mcp_token_minted');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_SECURITY, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('api_token', $record->subject->type);
        self::assertSame((string) $token->id, $record->subject->id);
        self::assertSame([
            'projectId' => (string) $project->id,
            'tokenId' => (string) $token->id,
        ], $record->context);

        self::assertSame(['project.mcp_token_minted'], $this->audit->securityLogLines());
        self::assertSame([], $this->audit->domainLogLines());
    }

    public function test_a_rejected_second_mint_is_recorded_as_a_refusal(): void
    {
        $project = $this->project('mint-mcp-audit-refused@example.com', 'refused');
        ($this->handler)(new MintProjectMcpTokenCommand($project));

        try {
            ($this->handler)(new MintProjectMcpTokenCommand($project));
            self::fail('Expected DomainErrors for a second MCP token mint.');
        } catch (DomainErrors) {
        }

        $record = $this->audit->record('project.mcp_token_mint_rejected');
        self::assertSame(AuditOutcome::Refused, $record->outcome);
        self::assertSame(Auditor::CATEGORY_SECURITY, $record->category);
        self::assertNotNull($record->subject);
        self::assertSame('project', $record->subject->type);
        self::assertSame((string) $project->id, $record->subject->id);
        self::assertSame(['projectId' => (string) $project->id], $record->context);

        self::assertSame(
            ['project.mcp_token_minted', 'project.mcp_token_mint_rejected'],
            $this->audit->securityLogLines(),
        );
        self::assertSame([], $this->audit->domainLogLines());
    }

    public function test_the_handler_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(MintProjectMcpTokenHandler::class);
    }

    /**
     * The sink drains outside the business transaction, so a record made inside
     * one outlives its rollback. A commit that fails after the mint must
     * therefore leave no record claiming a token was minted.
     */
    public function test_a_commit_that_fails_after_the_mint_records_nothing(): void
    {
        $project = $this->project('mint-mcp-rollback@example.com', 'rolled-back');
        $handler = new MintProjectMcpTokenHandler($this->failingCommitEntityManager(), $this->projects, $this->audit->auditor);

        try {
            $handler(new MintProjectMcpTokenCommand($project));
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
