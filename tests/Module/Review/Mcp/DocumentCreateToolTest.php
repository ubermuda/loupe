<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Mcp\DocumentCreateTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DocumentCreateToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private DocumentCreateTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(DocumentCreateTool::class);
        self::assertInstanceOf(DocumentCreateTool::class, $tool);
        $this->tool = $tool;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'hashed');
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

    public function test_a_blank_title_is_rejected_with_a_message_the_agent_can_act_on(): void
    {
        $owner = $this->user('create-blank-title@example.com');
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);

        try {
            ($this->tool)('   ', '# Body');
            self::fail('a blank title must throw');
        } catch (ToolCallException $e) {
            self::assertSame('title: A document title must not be blank.', $e->getMessage());
        }
    }

    public function test_tags_passed_at_creation_are_created_and_attached(): void
    {
        $owner = $this->user('create-tagged@example.com');
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)('Auth PRD', '# Auth', null, ['Release', 'design', 'DESIGN']);

        self::assertSame(['design', 'release'], $result['tags']);

        $this->em->clear();
        $document = $this->em->find(Document::class, Uuid::fromString($result['documentId']));
        self::assertInstanceOf(Document::class, $document);
        self::assertSame(['design', 'release'], array_map(static fn (Tag $t): string => $t->name, $document->tags->toArray()));
    }

    public function test_a_document_created_without_tags_carries_none(): void
    {
        $owner = $this->user('create-untagged@example.com');
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);

        self::assertSame([], ($this->tool)('Auth PRD', '# Auth')['tags']);
    }

    public function test_references_are_recorded_and_visible_from_the_document_they_point_at(): void
    {
        $owner = $this->user('create-references@example.com');
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);
        $target = new Document(owner: $owner, project: $project, title: 'The spec');
        $target->addVersion('# Spec', '<h1>Spec</h1>');
        $this->em->persist($target);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);

        // The same id twice must land as one link, not two. Named argument
        // because $tags now sits between $description and $references.
        $result = ($this->tool)('Companion thread', '# Thread', null, references: [(string) $target->id, (string) $target->id]);

        $this->em->clear();
        $document = $this->em->find(Document::class, Uuid::fromString($result['documentId']));
        self::assertInstanceOf(Document::class, $document);
        self::assertCount(1, $document->references);
        $reference = $document->references->first();
        self::assertInstanceOf(Document::class, $reference);
        self::assertSame((string) $target->id, (string) $reference->id);

        // The reverse direction is derived from the same row.
        $reloadedTarget = $this->em->find(Document::class, $target->id);
        self::assertInstanceOf(Document::class, $reloadedTarget);
        self::assertCount(1, $reloadedTarget->referencedBy);
        $inbound = $reloadedTarget->referencedBy->first();
        self::assertInstanceOf(Document::class, $inbound);
        self::assertSame($result['documentId'], (string) $inbound->id);
    }

    public function test_a_reference_to_another_projects_document_is_rejected(): void
    {
        $owner = $this->user('create-xref@example.com');
        $otherProject = new Project($owner, 'p-'.uniqid());
        $this->em->persist($otherProject);
        $foreign = new Document(owner: $owner, project: $otherProject, title: 'Elsewhere');
        $foreign->addVersion('# Elsewhere', '<h1>Elsewhere</h1>');
        $this->em->persist($foreign);

        $boundProject = new Project($owner, 'p-'.uniqid());
        $this->em->persist($boundProject);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($boundProject);

        try {
            ($this->tool)('Companion', '# Body', null, references: [(string) $foreign->id]);
            self::fail('referencing another project\'s document must throw');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        // The call failed outright rather than dropping the bad reference and
        // creating the document without it.
        $this->em->clear();
        self::assertSame(
            0,
            (int) $this->em->getConnection()->fetchOne(
                'SELECT count(*) FROM documents WHERE project_id = :id',
                ['id' => (string) $boundProject->id],
            ),
        );
    }

    public function test_unbound_mcp_token_is_rejected(): void
    {
        $owner = $this->user('create-unbound@example.com');

        $this->actAsUnboundMcpToken($owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)('Title', '# Body');
    }

    public function test_the_size_cap_stays_below_what_postgres_can_index(): void
    {
        // to_tsvector refuses a vector over 1,048,575 bytes. Accepting a
        // document larger than it can index stored the row and then failed
        // indexing, and on create and rename that failure happens after the
        // flush — leaving the document permanently unsearchable with nothing
        // to retry it.
        self::assertLessThanOrEqual(1_048_575, DocumentCreateTool::MAX_MARKDOWN_BYTES);
    }
}
