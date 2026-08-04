<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Mcp\ReviewSubjectResolver;
use App\Module\Review\Security\McpBoundProjectVoter;
use App\Module\Review\ValueObject\Anchor;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ReviewSubjectResolverTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private ReviewSubjectResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $resolver = self::getContainer()->get(ReviewSubjectResolver::class);
        self::assertInstanceOf(ReviewSubjectResolver::class, $resolver);
        $this->resolver = $resolver;
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'hashed');
        $this->em->persist($user);

        return $user;
    }

    private function documentInNewProject(User $owner): Document
    {
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: 'Doc');
        $document->addVersion('# Hello', '<h1>Hello</h1>');
        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }

    private function commentOn(Document $document): Comment
    {
        $comment = new Comment($document->currentVersion(), $document->owner, 'Note', new Anchor('Hello', '# ', '', 2));
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    public function test_resolves_a_comment_of_the_bound_project(): void
    {
        $document = $this->documentInNewProject($this->user('res-cmt@example.com'));
        $comment = $this->commentOn($document);
        $this->actAsMcpTokenBoundTo($document->project);

        self::assertSame($comment, $this->resolver->requireComment((string) $comment->id, McpBoundProjectVoter::COMMENT_READ));
    }

    public function test_an_unknown_comment_and_a_foreign_comment_are_rejected_identically(): void
    {
        $owner = $this->user('res-cmt-probe@example.com');
        $foreign = $this->commentOn($this->documentInNewProject($owner));

        $projectB = new Project($owner, 'p-'.uniqid());
        $this->em->persist($projectB);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($projectB);

        $unknownId = (string) Uuid::v7();

        $foreignMessage = null;
        try {
            $this->resolver->requireComment((string) $foreign->id, McpBoundProjectVoter::COMMENT_READ);
        } catch (ToolCallException $e) {
            $foreignMessage = $e->getMessage();
        }

        $unknownMessage = null;
        try {
            $this->resolver->requireComment($unknownId, McpBoundProjectVoter::COMMENT_READ);
        } catch (ToolCallException $e) {
            $unknownMessage = $e->getMessage();
        }

        self::assertNotNull($foreignMessage, 'a foreign comment must be rejected');
        self::assertNotNull($unknownMessage, 'an unknown id must be rejected');
        self::assertStringContainsString('not found or not accessible', $foreignMessage);

        // Only the echoed id may differ; anything else would let a caller probe
        // which ids exist outside the project its token is bound to.
        self::assertSame(
            str_replace((string) $foreign->id, 'ID', $foreignMessage),
            str_replace($unknownId, 'ID', $unknownMessage),
        );
    }

    public function test_the_write_attribute_is_refused_on_another_projects_comment(): void
    {
        $owner = $this->user('res-cmt-write@example.com');
        $foreign = $this->commentOn($this->documentInNewProject($owner));

        $projectB = new Project($owner, 'p-'.uniqid());
        $this->em->persist($projectB);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($projectB);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not found or not accessible');
        $this->resolver->requireComment((string) $foreign->id, McpBoundProjectVoter::COMMENT_WRITE);
    }

    public function test_a_malformed_comment_id_is_rejected_with_a_clear_message(): void
    {
        $document = $this->documentInNewProject($this->user('res-cmt-bad@example.com'));
        $this->actAsMcpTokenBoundTo($document->project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"nope" is not a valid comment ID.');
        $this->resolver->requireComment('nope', McpBoundProjectVoter::COMMENT_READ);
    }

    public function test_an_unbound_token_is_rejected_before_the_comment_is_looked_up(): void
    {
        $owner = $this->user('res-cmt-unbound@example.com');
        $comment = $this->commentOn($this->documentInNewProject($owner));
        $this->actAsUnboundMcpToken($owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        $this->resolver->requireComment((string) $comment->id, McpBoundProjectVoter::COMMENT_READ);
    }

    public function test_resolves_a_document_of_the_bound_project(): void
    {
        $document = $this->documentInNewProject($this->user('res-doc@example.com'));
        $this->actAsMcpTokenBoundTo($document->project);

        self::assertSame($document, $this->resolver->requireDocument((string) $document->id, McpBoundProjectVoter::DOCUMENT_READ));
    }
}
