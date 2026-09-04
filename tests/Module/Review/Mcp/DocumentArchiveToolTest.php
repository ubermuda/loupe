<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\DocumentArchiveTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DocumentArchiveToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private DocumentArchiveTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(DocumentArchiveTool::class);
        self::assertInstanceOf(DocumentArchiveTool::class, $tool);
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

    public function test_archives_a_document_without_adding_a_version(): void
    {
        $owner = $this->user('archive-bound@example.com');
        $document = $this->documentInNewProject($owner, 'Post 5 — draft');

        $this->actAsMcpTokenBoundTo($document->project);

        $result = ($this->tool)((string) $document->id, 'superseded by the v2 plan');

        self::assertSame(['documentId' => (string) $document->id, 'archived' => true], $result);
        self::assertNotNull($document->archivedAt);
        self::assertSame('superseded by the v2 plan', $document->archiveReason);
        self::assertCount(1, $document->versions);
    }

    /**
     * A second archive is a no-op down to the reason, so restating one means
     * restoring the document and archiving it again.
     */
    public function test_archiving_an_archived_document_keeps_the_original_timestamp_and_reason(): void
    {
        $owner = $this->user('archive-twice@example.com');
        $document = $this->documentInNewProject($owner, 'Already away');

        $this->actAsMcpTokenBoundTo($document->project);

        ($this->tool)((string) $document->id, 'superseded by the v2 plan');
        $firstArchivedAt = $document->archivedAt;
        self::assertNotNull($firstArchivedAt);

        $result = ($this->tool)((string) $document->id, 'duplicate of the onboarding brief');

        self::assertSame(['documentId' => (string) $document->id, 'archived' => true], $result);
        self::assertSame($firstArchivedAt, $document->archivedAt);
        self::assertSame('superseded by the v2 plan', $document->archiveReason);
    }

    /**
     * A required parameter only forces the agent to send the field; sending
     * spaces would satisfy the schema and archive the document with an
     * explanation that explains nothing. The message names what is wrong rather
     * than falling through to the generic rejection.
     */
    public function test_a_blank_reason_is_rejected_with_a_message_the_agent_can_act_on(): void
    {
        $owner = $this->user('archive-blank@example.com');
        $document = $this->documentInNewProject($owner, 'Needs a reason');

        $this->actAsMcpTokenBoundTo($document->project);

        try {
            ($this->tool)((string) $document->id, '   ');
            self::fail('a blank reason must throw');
        } catch (ToolCallException $e) {
            self::assertSame('reason: A reason for archiving the document is required.', $e->getMessage());
        }

        self::assertNull($document->archivedAt);
    }

    public function test_cannot_archive_a_document_of_another_project_of_the_same_owner(): void
    {
        $owner = $this->user('archive-cross@example.com');
        $documentInProjectA = $this->documentInNewProject($owner, 'Project A Doc');

        $projectB = new Project($owner, 'p-'.uniqid());
        $this->em->persist($projectB);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($projectB);

        try {
            ($this->tool)((string) $documentInProjectA->id, 'superseded by the v2 plan');
            self::fail('archiving another project\'s document must throw');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        self::assertNull($documentInProjectA->archivedAt);
    }

    public function test_cannot_archive_another_users_document(): void
    {
        $victim = $this->user('archive-victim@example.com');
        $document = $this->documentInNewProject($victim, 'Victim Doc');

        $attacker = $this->user('archive-attacker@example.com');
        $attackerProject = new Project($attacker, 'p-'.uniqid());
        $this->em->persist($attackerProject);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($attackerProject);

        try {
            ($this->tool)((string) $document->id, 'superseded by the v2 plan');
            self::fail('archiving another user\'s document must throw');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        self::assertNull($document->archivedAt);
    }

    public function test_unbound_mcp_token_is_rejected(): void
    {
        $owner = $this->user('archive-unbound@example.com');
        $document = $this->documentInNewProject($owner, 'Unreachable');

        $this->actAsUnboundMcpToken($owner);

        try {
            ($this->tool)((string) $document->id, 'superseded by the v2 plan');
            self::fail('an unbound token must throw');
        } catch (ToolCallException $e) {
            self::assertSame('MCP token is not bound to a project. Mint a project token from the Connect page.', $e->getMessage());
        }

        self::assertNull($document->archivedAt);
    }
}
