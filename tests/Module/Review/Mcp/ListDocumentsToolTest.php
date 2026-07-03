<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\ListDocumentsTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ListDocumentsToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private ListDocumentsTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(ListDocumentsTool::class);
        self::assertInstanceOf(ListDocumentsTool::class, $tool);
        $this->tool = $tool;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);

        return $user;
    }

    private function project(User $owner): Project
    {
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        return $project;
    }

    public function test_returns_only_the_bound_projects_documents_even_for_the_same_owner(): void
    {
        $owner = $this->user('list-owner@example.com');
        $projectA = $this->project($owner);
        $projectB = $this->project($owner);

        $docA = new Document(owner: $owner, project: $projectA, title: 'Project A Document');
        $docA->addVersion('# Content A', '<h1>Content A</h1>');
        $this->em->persist($docA);

        $docB = new Document(owner: $owner, project: $projectB, title: 'Project B Document');
        $docB->addVersion('# Content B', '<h1>Content B</h1>');
        $this->em->persist($docB);

        $this->em->flush();

        $this->actAsMcpTokenBoundTo($projectA);

        $result = ($this->tool)();

        // Exactly one document — project A's, despite both belonging to the same owner.
        self::assertCount(1, $result);

        $item = $result[0];
        self::assertSame((string) $docA->id, $item['documentId']);
        self::assertSame('Project A Document', $item['title']);
        self::assertSame('in-review', $item['status']);
        self::assertSame(1, $item['currentVersion']);

        $returnedIds = array_column($result, 'documentId');
        self::assertNotContains((string) $docB->id, $returnedIds);
    }

    public function test_another_users_documents_are_not_visible(): void
    {
        $ownerA = $this->user('list-a@example.com');
        $ownerB = $this->user('list-b@example.com');
        $projectA = $this->project($ownerA);
        $projectB = $this->project($ownerB);

        $docB = new Document(owner: $ownerB, project: $projectB, title: 'Foreign Document');
        $docB->addVersion('# B', '<h1>B</h1>');
        $this->em->persist($docB);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($projectA);

        self::assertSame([], ($this->tool)());
    }

    public function test_unbound_mcp_token_is_rejected(): void
    {
        $owner = $this->user('list-unbound@example.com');

        $this->actAsUnboundMcpToken($owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)();
    }
}
