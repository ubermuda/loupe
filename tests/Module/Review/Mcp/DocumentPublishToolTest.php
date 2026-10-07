<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Mcp\DocumentPublishTool;
use App\Tests\Support\McpRefusalMessages;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DocumentPublishToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private DocumentPublishTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(DocumentPublishTool::class);
        self::assertInstanceOf(DocumentPublishTool::class, $tool);
        $this->tool = $tool;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);

        return $user;
    }

    private function documentInNewProject(User $owner, DocumentStatus $status): Document
    {
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: 'A doc');
        $document->addVersion('# Original', '<h1>Original</h1>');
        $document->status = $status;
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    public function test_publishes_a_draft_without_adding_a_version(): void
    {
        $document = $this->documentInNewProject($this->user('publish-tool@example.com'), DocumentStatus::Draft);
        $this->actAsMcpTokenBoundTo($document->project);

        $result = ($this->tool)((string) $document->id);

        self::assertSame(['documentId' => (string) $document->id, 'status' => 'in-review', 'published' => true], $result);
        self::assertCount(1, $document->versions);
    }

    public function test_a_document_that_is_not_a_draft_is_returned_unchanged(): void
    {
        $document = $this->documentInNewProject($this->user('publish-tool-twice@example.com'), DocumentStatus::ChangesRequested);
        $this->actAsMcpTokenBoundTo($document->project);

        $result = ($this->tool)((string) $document->id);

        self::assertSame(['documentId' => (string) $document->id, 'status' => 'changes-requested', 'published' => false], $result);
    }

    public function test_an_archived_draft_is_refused_with_a_message_the_agent_can_act_on(): void
    {
        $document = $this->documentInNewProject($this->user('publish-tool-archived@example.com'), DocumentStatus::Draft);
        $document->archivedAt = new \DateTimeImmutable();
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($document->project);

        try {
            ($this->tool)((string) $document->id);
            self::fail('an archived document must be refused');
        } catch (ToolCallException $e) {
            self::assertSame('documentId: An archived document cannot be published. Restore it with document_unarchive first.', $e->getMessage());
        }

        self::assertSame(DocumentStatus::Draft, $document->status);
    }

    public function test_cannot_publish_a_document_of_another_project_of_the_same_owner(): void
    {
        $owner = $this->user('publish-tool-cross@example.com');
        $document = $this->documentInNewProject($owner, DocumentStatus::Draft);
        $projectB = new Project($owner, 'p-'.uniqid());
        $this->em->persist($projectB);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($projectB);

        try {
            ($this->tool)((string) $document->id);
            self::fail('publishing another project\'s document must throw');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        self::assertSame(DocumentStatus::Draft, $document->status);
    }

    public function test_cannot_publish_another_users_document(): void
    {
        $document = $this->documentInNewProject($this->user('publish-tool-victim@example.com'), DocumentStatus::Draft);
        $attacker = $this->user('publish-tool-attacker@example.com');
        $attackerProject = new Project($attacker, 'p-'.uniqid());
        $this->em->persist($attackerProject);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($attackerProject);

        try {
            ($this->tool)((string) $document->id);
            self::fail('publishing another user\'s document must throw');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        self::assertSame(DocumentStatus::Draft, $document->status);
    }

    public function test_unbound_mcp_token_is_rejected(): void
    {
        $owner = $this->user('publish-tool-unbound@example.com');
        $document = $this->documentInNewProject($owner, DocumentStatus::Draft);
        $this->actAsUnboundMcpToken($owner);

        try {
            ($this->tool)((string) $document->id);
            self::fail('an unbound token must throw');
        } catch (ToolCallException $e) {
            self::assertSame(McpRefusalMessages::NO_PROJECT_REACHED, $e->getMessage());
        }

        self::assertSame(DocumentStatus::Draft, $document->status);
    }
}
