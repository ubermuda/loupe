<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\CreateDocumentTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CreateDocumentToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private CreateDocumentTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(CreateDocumentTool::class);
        self::assertInstanceOf(CreateDocumentTool::class, $tool);
        $this->tool = $tool;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);

        return $user;
    }

    public function test_document_is_created_in_the_bound_project(): void
    {
        $owner = $this->user('create-bound@example.com');
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)('Auth PRD', "# Auth\n\nUse JWTs.");

        self::assertStringContainsString($result['documentId'], $result['reviewUrl']);

        $this->em->clear();
        $document = $this->em->find(Document::class, Uuid::fromString($result['documentId']));
        self::assertInstanceOf(Document::class, $document);
        self::assertSame((string) $project->id, (string) $document->project->id);
        self::assertSame((string) $owner->id, (string) $document->owner->id);
        self::assertSame('Auth PRD', $document->title);
    }

    public function test_unbound_mcp_token_is_rejected(): void
    {
        $owner = $this->user('create-unbound@example.com');

        $this->actAsUnboundMcpToken($owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)('Title', '# Body');
    }
}
