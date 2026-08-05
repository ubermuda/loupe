<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\DocumentRenameTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DocumentRenameToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private DocumentRenameTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(DocumentRenameTool::class);
        self::assertInstanceOf(DocumentRenameTool::class, $tool);
        $this->tool = $tool;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);

        return $user;
    }

    private function documentInNewProject(User $owner, string $title): Document
    {
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: $title);
        $document->addVersion('# Original', '<h1>Original</h1>');
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    public function test_renames_a_document_without_adding_a_version(): void
    {
        $owner = $this->user('rename-bound@example.com');
        $document = $this->documentInNewProject($owner, 'Post 5 — draft');

        $this->actAsMcpTokenBoundTo($document->project);

        $result = ($this->tool)((string) $document->id, 'Post 5 — Rate limiting');

        self::assertSame(['documentId' => (string) $document->id, 'title' => 'Post 5 — Rate limiting'], $result);
        self::assertSame('Post 5 — Rate limiting', $document->title);
        self::assertCount(1, $document->versions);
    }

    public function test_cannot_rename_a_document_of_another_project_of_the_same_owner(): void
    {
        $owner = $this->user('rename-cross@example.com');
        $documentInProjectA = $this->documentInNewProject($owner, 'Project A Doc');

        $projectB = new Project($owner, 'p-'.uniqid());
        $this->em->persist($projectB);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($projectB);

        try {
            ($this->tool)((string) $documentInProjectA->id, 'Hijacked');
            self::fail('renaming another project\'s document must throw');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        self::assertSame('Project A Doc', $documentInProjectA->title);
    }

    public function test_cannot_rename_another_users_document(): void
    {
        $victim = $this->user('rename-victim@example.com');
        $document = $this->documentInNewProject($victim, 'Victim Doc');

        $attacker = $this->user('rename-attacker@example.com');
        $attackerProject = new Project($attacker, 'p-'.uniqid());
        $this->em->persist($attackerProject);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($attackerProject);

        try {
            ($this->tool)((string) $document->id, 'Hijacked');
            self::fail('renaming another user\'s document must throw');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        self::assertSame('Victim Doc', $document->title);
    }

    public function test_a_blank_title_keeps_its_own_message(): void
    {
        $owner = $this->user('rename-blank@example.com');
        $document = $this->documentInNewProject($owner, 'Keep me');

        $this->actAsMcpTokenBoundTo($document->project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('A document title must not be blank.');
        ($this->tool)((string) $document->id, '   ');
    }

    public function test_an_over_long_title_keeps_its_own_message(): void
    {
        $owner = $this->user('rename-long@example.com');
        $document = $this->documentInNewProject($owner, 'Keep me');

        $this->actAsMcpTokenBoundTo($document->project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('A document title must be at most 255 characters.');
        ($this->tool)((string) $document->id, str_repeat('a', Document::MAX_TITLE_LENGTH + 1));
    }

    public function test_unbound_mcp_token_is_rejected(): void
    {
        $owner = $this->user('rename-unbound@example.com');
        $document = $this->documentInNewProject($owner, 'Unreachable');

        $this->actAsUnboundMcpToken($owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)((string) $document->id, 'Nope');
    }
}
