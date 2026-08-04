<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\DocumentUnarchiveTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DocumentUnarchiveToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private DocumentUnarchiveTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(DocumentUnarchiveTool::class);
        self::assertInstanceOf(DocumentUnarchiveTool::class, $tool);
        $this->tool = $tool;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);

        return $user;
    }

    private function documentInNewProject(User $owner, string $title, bool $archived = true): Document
    {
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: $title);
        $document->addVersion('# Original', '<h1>Original</h1>');

        if ($archived) {
            $document->archivedAt = new \DateTimeImmutable('2026-08-01 09:00:00');
            $document->archiveReason = 'superseded by the v2 plan';
        }

        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    public function test_restores_an_archived_document_without_adding_a_version(): void
    {
        $owner = $this->user('unarchive-bound@example.com');
        $document = $this->documentInNewProject($owner, 'Put away');

        $this->actAsMcpTokenBoundTo($document->project);

        $result = ($this->tool)((string) $document->id);

        self::assertSame(['documentId' => (string) $document->id, 'archived' => false], $result);
        self::assertNull($document->archivedAt);
        // Restoring takes the reason with it, so the document is not back in the
        // list still explaining why it left.
        self::assertNull($document->archiveReason);
        self::assertCount(1, $document->versions);
    }

    public function test_unarchiving_a_document_that_is_not_archived_changes_nothing(): void
    {
        $owner = $this->user('unarchive-noop@example.com');
        $document = $this->documentInNewProject($owner, 'Never archived', archived: false);

        $this->actAsMcpTokenBoundTo($document->project);

        $result = ($this->tool)((string) $document->id);

        self::assertSame(['documentId' => (string) $document->id, 'archived' => false], $result);
        self::assertNull($document->archivedAt);
    }

    public function test_cannot_unarchive_a_document_of_another_project_of_the_same_owner(): void
    {
        $owner = $this->user('unarchive-cross@example.com');
        $documentInProjectA = $this->documentInNewProject($owner, 'Project A Doc');

        $projectB = new Project($owner, 'p-'.uniqid());
        $this->em->persist($projectB);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($projectB);

        try {
            ($this->tool)((string) $documentInProjectA->id);
            self::fail('unarchiving another project\'s document must throw');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        self::assertNotNull($documentInProjectA->archivedAt);
    }

    public function test_cannot_unarchive_another_users_document(): void
    {
        $victim = $this->user('unarchive-victim@example.com');
        $document = $this->documentInNewProject($victim, 'Victim Doc');

        $attacker = $this->user('unarchive-attacker@example.com');
        $attackerProject = new Project($attacker, 'p-'.uniqid());
        $this->em->persist($attackerProject);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($attackerProject);

        try {
            ($this->tool)((string) $document->id);
            self::fail('unarchiving another user\'s document must throw');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        self::assertNotNull($document->archivedAt);
    }

    public function test_unbound_mcp_token_is_rejected(): void
    {
        $owner = $this->user('unarchive-unbound@example.com');
        $document = $this->documentInNewProject($owner, 'Unreachable');

        $this->actAsUnboundMcpToken($owner);

        try {
            ($this->tool)((string) $document->id);
            self::fail('an unbound token must throw');
        } catch (ToolCallException $e) {
            self::assertSame('MCP token is not bound to a project. Mint a project token from the Connect page.', $e->getMessage());
        }

        self::assertNotNull($document->archivedAt);
    }
}
