<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\DocumentMarkCommentAddressedTool;
use App\Module\Review\ValueObject\Anchor;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DocumentMarkCommentAddressedToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private DocumentMarkCommentAddressedTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(DocumentMarkCommentAddressedTool::class);
        self::assertInstanceOf(DocumentMarkCommentAddressedTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_a_pending_thread_becomes_addressed(): void
    {
        $owner = $this->user('mark-pending@example.com');
        $comment = $this->rootComment($owner);
        $this->actAsMcpTokenBoundTo($comment->version->document->project);

        $result = ($this->tool)([(string) $comment->id]);

        self::assertSame([(string) $comment->id], $result['addressed']);
        self::assertSame([], $result['skipped']);
        self::assertSame(CommentStatus::Addressed, $comment->status);
    }

    public function test_a_resolve_the_identity_map_has_not_seen_is_not_overwritten(): void
    {
        $owner = $this->user('mark-race@example.com');
        $comment = $this->rootComment($owner);
        $this->actAsMcpTokenBoundTo($comment->version->document->project);

        // Stands in for a human clicking Resolve after the tool has read the
        // row: written straight to the database so the tool's in-memory copy
        // still says Pending, which is what the race actually looks like.
        $this->em->createQuery(
            'UPDATE '.Comment::class.' c SET c.status = :resolved WHERE c.id = :id'
        )
            ->setParameter('resolved', CommentStatus::Resolved)
            ->setParameter('id', $comment->id, 'uuid')
            ->execute();
        self::assertSame(CommentStatus::Pending, $comment->status);

        $result = ($this->tool)([(string) $comment->id]);

        self::assertSame([], $result['addressed']);
        self::assertSame([['id' => (string) $comment->id, 'reason' => 'already_resolved']], $result['skipped']);

        $id = $comment->id;
        $this->em->clear();
        $fetched = $this->em->find(Comment::class, $id);
        self::assertNotNull($fetched);
        self::assertSame(CommentStatus::Resolved, $fetched->status);
    }

    public function test_a_concurrent_address_is_reported_as_addressed_not_resolved(): void
    {
        $owner = $this->user('mark-concurrent@example.com');
        $comment = $this->rootComment($owner);
        $this->actAsMcpTokenBoundTo($comment->version->document->project);

        // A second agent gets there first. The losing conditional update cannot
        // tell this from a human's Resolve on its own, so the reason has to
        // come from re-reading the row.
        $this->em->createQuery(
            'UPDATE '.Comment::class.' c SET c.status = :addressed WHERE c.id = :id'
        )
            ->setParameter('addressed', CommentStatus::Addressed)
            ->setParameter('id', $comment->id, 'uuid')
            ->execute();
        self::assertSame(CommentStatus::Pending, $comment->status);

        $result = ($this->tool)([(string) $comment->id]);

        self::assertSame([], $result['addressed']);
        self::assertSame([['id' => (string) $comment->id, 'reason' => 'already_addressed']], $result['skipped']);
    }

    public function test_each_refusal_reports_its_own_reason_without_failing_the_batch(): void
    {
        $owner = $this->user('mark-mixed@example.com');
        $pending = $this->rootComment($owner);
        $project = $pending->version->document->project;

        $addressed = $this->siblingComment($pending);
        $addressed->status = CommentStatus::Addressed;
        $resolved = $this->siblingComment($pending);
        $resolved->status = CommentStatus::Resolved;
        $reply = new Comment(
            version: $pending->version,
            author: $owner,
            body: 'A reply.',
            anchor: $pending->anchor,
            parent: $pending,
        );
        $this->em->persist($reply);

        $foreign = $this->rootComment($this->user('mark-foreign@example.com'));
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);

        $result = ($this->tool)([
            'not-a-uuid',
            // Right length and shape, one character out of range: it must land
            // in invalid_id, not be mistaken for a real id that went missing.
            'zzzzzzzz-9b1c-42f7-8e44-a10a6e57c3d9',
            (string) $foreign->id,
            (string) $reply->id,
            (string) $addressed->id,
            (string) $resolved->id,
            (string) $pending->id,
        ]);

        self::assertSame([(string) $pending->id], $result['addressed']);
        self::assertSame([
            ['id' => 'not-a-uuid', 'reason' => 'invalid_id'],
            ['id' => 'zzzzzzzz-9b1c-42f7-8e44-a10a6e57c3d9', 'reason' => 'invalid_id'],
            ['id' => (string) $foreign->id, 'reason' => 'not_found'],
            ['id' => (string) $reply->id, 'reason' => 'is_reply'],
            ['id' => (string) $addressed->id, 'reason' => 'already_addressed'],
            ['id' => (string) $resolved->id, 'reason' => 'already_resolved'],
        ], $result['skipped']);
    }

    public function test_marking_an_already_addressed_thread_again_changes_nothing(): void
    {
        $owner = $this->user('mark-twice@example.com');
        $comment = $this->rootComment($owner);
        $this->actAsMcpTokenBoundTo($comment->version->document->project);

        ($this->tool)([(string) $comment->id]);
        $second = ($this->tool)([(string) $comment->id]);

        self::assertSame([], $second['addressed']);
        self::assertSame([['id' => (string) $comment->id, 'reason' => 'already_addressed']], $second['skipped']);
        self::assertSame(CommentStatus::Addressed, $comment->status);
    }

    public function test_an_id_from_before_a_revision_is_skipped_rather_than_flipping_a_dead_row(): void
    {
        $owner = $this->user('mark-superseded@example.com');
        $comment = $this->rootComment($owner);
        $document = $comment->version->document;
        $this->actAsMcpTokenBoundTo($document->project);

        // What document_revise does to the comment: a copy on the new version,
        // with the original left behind and still resolvable by its own id.
        $newVersion = $document->addVersion('# Hello again', '<h1>Hello again</h1>');
        $copy = new Comment(
            version: $newVersion,
            author: $comment->author,
            body: $comment->body,
            anchor: $comment->anchor,
        );
        $this->em->persist($copy);
        $this->em->flush();

        $result = ($this->tool)([(string) $comment->id]);

        self::assertSame([], $result['addressed']);
        self::assertSame([['id' => (string) $comment->id, 'reason' => 'superseded']], $result['skipped']);
        self::assertSame(CommentStatus::Pending, $comment->status);
        self::assertSame(CommentStatus::Pending, $copy->status);
    }

    public function test_unbound_mcp_token_is_rejected_rather_than_skipping_every_id(): void
    {
        $owner = $this->user('mark-unbound@example.com');
        $comment = $this->rootComment($owner);
        $this->actAsUnboundMcpToken($owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)([(string) $comment->id]);
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);

        return $user;
    }

    private function rootComment(User $owner): Comment
    {
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: 'Reviewable');
        $document->addVersion('# Hello', '<h1>Hello</h1>');
        $this->em->persist($document);

        $comment = new Comment(
            version: $document->currentVersion(),
            author: $owner,
            body: 'Please clarify.',
            anchor: new Anchor('Hello', '', '', 2),
        );
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    private function siblingComment(Comment $sibling): Comment
    {
        $comment = new Comment(
            version: $sibling->version,
            author: $sibling->author,
            body: 'Another thread.',
            anchor: $sibling->anchor,
        );
        $this->em->persist($comment);

        return $comment;
    }
}
