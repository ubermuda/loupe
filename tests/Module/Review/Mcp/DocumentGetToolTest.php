<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\DocumentGetTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DocumentGetToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private DocumentGetTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(DocumentGetTool::class);
        self::assertInstanceOf(DocumentGetTool::class, $tool);
        $this->tool = $tool;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);

        return $user;
    }

    private function documentInNewProject(User $owner, string $title): Document
    {
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: $title);
        $document->addVersion('# Hello', '<h1>Hello</h1>');
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    public function test_returns_a_document_of_the_bound_project(): void
    {
        $owner = $this->user('getdoc-bound@example.com');
        $document = $this->documentInNewProject($owner, 'Readable');

        $this->actAsMcpTokenBoundTo($document->project);

        $result = ($this->tool)((string) $document->id);

        self::assertSame((string) $document->id, $result['documentId']);
        self::assertSame('Readable', $result['title']);
        self::assertSame('# Hello', $result['markdown']);
        self::assertFalse($result['archived']);
        self::assertNull($result['versionDescription']);
        self::assertSame([], $result['references']);
    }

    /**
     * Revising replaces the whole reference set, so an agent adding one link has
     * to be able to read the current set first — including an archived target,
     * whose link is still live.
     */
    public function test_reports_the_documents_it_references(): void
    {
        $owner = $this->user('getdoc-refs@example.com');
        $document = $this->documentInNewProject($owner, 'Companion thread');

        $target = new Document(owner: $owner, project: $document->project, title: 'The retired spec');
        $target->addVersion('# Spec', '<h1>Spec</h1>');
        $target->archivedAt = new \DateTimeImmutable();
        $this->em->persist($target);
        $document->references->add($target);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($document->project);

        $result = ($this->tool)((string) $document->id);

        self::assertSame([[
            'documentId' => (string) $target->id,
            'title' => 'The retired spec',
            'archived' => true,
        ]], $result['references']);
    }

    /**
     * An agent that fetches one document can otherwise not tell it was archived,
     * nor read back the description it wrote with the revision.
     */
    public function test_reports_archive_state_and_the_current_versions_description(): void
    {
        $owner = $this->user('getdoc-meta@example.com');
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: 'Archived and described');
        $document->addVersion('# v1', '<h1>v1</h1>', 'The original brief.');
        $document->addVersion('# v2', '<h1>v2</h1>', 'Replaced the rollout section.');
        $document->archivedAt = new \DateTimeImmutable();
        $this->em->persist($document);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)((string) $document->id);

        self::assertTrue($result['archived']);
        self::assertSame(2, $result['version']);
        self::assertSame('Replaced the rollout section.', $result['versionDescription']);
    }

    public function test_cannot_read_a_document_of_another_project_of_the_same_owner(): void
    {
        $owner = $this->user('getdoc-cross@example.com');
        $document = $this->documentInNewProject($owner, 'Hidden');

        $projectB = new Project($owner, 'p-'.uniqid());
        $this->em->persist($projectB);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($projectB);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not found or not accessible');
        ($this->tool)((string) $document->id);
    }

    public function test_an_unknown_id_and_a_foreign_document_are_rejected_identically(): void
    {
        $owner = $this->user('getdoc-probe@example.com');
        $foreign = $this->documentInNewProject($owner, 'Hidden');

        $projectB = new Project($owner, 'p-'.uniqid());
        $this->em->persist($projectB);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($projectB);

        $unknownId = (string) Uuid::v7();

        $foreignMessage = null;
        try {
            ($this->tool)((string) $foreign->id);
        } catch (ToolCallException $e) {
            $foreignMessage = $e->getMessage();
        }

        $unknownMessage = null;
        try {
            ($this->tool)($unknownId);
        } catch (ToolCallException $e) {
            $unknownMessage = $e->getMessage();
        }

        self::assertNotNull($foreignMessage, 'a foreign document must be rejected');
        self::assertNotNull($unknownMessage, 'an unknown id must be rejected');

        // Only the echoed id may differ; anything else would let a caller probe
        // which ids exist outside the project its token is bound to.
        self::assertSame(
            str_replace((string) $foreign->id, 'ID', $foreignMessage),
            str_replace($unknownId, 'ID', $unknownMessage),
        );
    }

    public function test_a_malformed_document_id_is_rejected_with_a_clear_message(): void
    {
        $owner = $this->user('getdoc-malformed@example.com');
        $document = $this->documentInNewProject($owner, 'Whatever');

        $this->actAsMcpTokenBoundTo($document->project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"not-a-uuid" is not a valid document ID.');
        ($this->tool)('not-a-uuid');
    }

    public function test_unbound_mcp_token_is_rejected(): void
    {
        $owner = $this->user('getdoc-unbound@example.com');
        $document = $this->documentInNewProject($owner, 'Unreachable');

        $this->actAsUnboundMcpToken($owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)((string) $document->id);
    }
}
