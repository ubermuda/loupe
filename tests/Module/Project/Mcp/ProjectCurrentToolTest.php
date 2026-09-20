<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Mcp\ProjectCurrentTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProjectCurrentToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private ProjectCurrentTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(ProjectCurrentTool::class);
        self::assertInstanceOf(ProjectCurrentTool::class, $tool);
        $this->tool = $tool;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);

        return $user;
    }

    private function project(User $owner, string $name): Project
    {
        $project = new Project($owner, $name);
        $this->em->persist($project);
        $this->em->flush();

        return $project;
    }

    public function test_it_reports_the_project_the_connection_acts_on(): void
    {
        $project = $this->project($this->user('owner@example.test'), 'Dev Project');
        $this->actAsMcpTokenBoundTo($project);

        self::assertSame(
            ['project' => [
                'id' => (string) $project->id,
                'slug' => $project->slug,
                'name' => 'Dev Project',
            ]],
            ($this->tool)(),
        );
    }

    public function test_it_names_one_project_only_when_the_owner_has_several(): void
    {
        $owner = $this->user('owner@example.test');
        $first = $this->project($owner, 'First');
        $this->project($owner, 'Second');
        $this->actAsMcpTokenBoundTo($first);

        $answer = ($this->tool)();

        self::assertSame((string) $first->id, $answer['project']['id']);
        self::assertSame('First', $answer['project']['name']);
    }

    public function test_an_unbound_token_is_told_to_mint_a_project_token(): void
    {
        $this->actAsUnboundMcpToken($this->user('owner@example.test'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not bound to a project');

        ($this->tool)();
    }
}
