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
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MintProjectMcpTokenHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MintProjectMcpTokenHandler $handler;
    private ProjectRepository $projects;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $projects = self::getContainer()->get(ProjectRepository::class);
        self::assertInstanceOf(ProjectRepository::class, $projects);
        $this->projects = $projects;
        $this->handler = new MintProjectMcpTokenHandler($this->em, new NullLogger());
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

    /** @param non-empty-string $email */
    private function project(string $email, string $name): Project
    {
        $owner = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $project = new Project($owner, $name);
        $this->em->persist($owner);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }
}
