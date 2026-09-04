<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\DocumentReplyToCommentTool;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\ValueObject\Anchor;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DocumentReplyToCommentToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private DocumentReplyToCommentTool $tool;
    private CommentRepository $comments;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(DocumentReplyToCommentTool::class);
        self::assertInstanceOf(DocumentReplyToCommentTool::class, $tool);
        $this->tool = $tool;

        $comments = self::getContainer()->get(CommentRepository::class);
        self::assertInstanceOf(CommentRepository::class, $comments);
        $this->comments = $comments;
    }

    public function test_the_reply_is_authored_by_the_agent_not_the_token_owner(): void
    {
        $owner = $this->user('reply-agent@example.com');
        $comment = $this->rootComment($owner);
        $this->actAsMcpTokenBoundTo($comment->version->document->project);

        $result = ($this->tool)((string) $comment->id, 'Fixed in the next revision.');

        $reply = $this->comments->find(Uuid::fromString($result['id']));
        self::assertInstanceOf(Comment::class, $reply);
        self::assertSame('Fixed in the next revision.', $reply->body);
        self::assertSame($comment->id, $reply->parent?->id);

        $agent = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $agent);
        self::assertSame($agent->agent()->id, $reply->author->id);
        self::assertNotSame($owner->id, $reply->author->id);
        self::assertTrue($reply->author->isAgent());
    }

    public function test_replying_to_a_reply_is_rejected(): void
    {
        $owner = $this->user('reply-nested@example.com');
        $comment = $this->rootComment($owner);
        $this->actAsMcpTokenBoundTo($comment->version->document->project);

        $first = ($this->tool)((string) $comment->id, 'One.');

        $this->expectException(ToolCallException::class);
        // A sentence naming the argument, not the key: nothing renders a
        // template for an MCP caller, so the key would reach the agent verbatim.
        $this->expectExceptionMessage('body: A reply can only be added to the top-level comment of a thread.');
        ($this->tool)($first['id'], 'Two.');
    }

    public function test_an_empty_reply_is_rejected(): void
    {
        $owner = $this->user('reply-empty@example.com');
        $comment = $this->rootComment($owner);
        $this->actAsMcpTokenBoundTo($comment->version->document->project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('body: A reply must not be blank.');
        ($this->tool)((string) $comment->id, "  \n ");
    }

    public function test_a_comment_from_before_a_revision_is_rejected(): void
    {
        $owner = $this->user('reply-superseded@example.com');
        $comment = $this->rootComment($owner);
        $document = $comment->version->document;
        $this->actAsMcpTokenBoundTo($document->project);

        // What document_revise does to the comment: a copy on the new version,
        // with the original left behind and still resolvable by its own id. A
        // reply written onto the original would surface nowhere.
        $newVersion = $document->addVersion('# Hello again', '<h1>Hello again</h1>');
        $this->em->persist(new Comment(
            version: $newVersion,
            author: $comment->author,
            body: $comment->body,
            anchor: $comment->anchor,
        ));
        $this->em->flush();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('belongs to version 1, but the document is now on version 2');
        ($this->tool)((string) $comment->id, 'Should never land.');
    }

    public function test_a_comment_in_another_project_is_not_reachable(): void
    {
        $owner = $this->user('reply-cross@example.com');
        $comment = $this->rootComment($owner);

        $other = new Project($owner, 'p-'.uniqid());
        $this->em->persist($other);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($other);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not found or not accessible');
        ($this->tool)((string) $comment->id, 'Should never land.');
    }

    public function test_unbound_mcp_token_is_rejected(): void
    {
        $owner = $this->user('reply-unbound@example.com');
        $comment = $this->rootComment($owner);
        $this->actAsUnboundMcpToken($owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)((string) $comment->id, 'Should never land.');
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
}
