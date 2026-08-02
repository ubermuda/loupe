<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReplyToCommentCommand;
use App\Module\Review\Command\ReplyToCommentHandler;
use App\Module\Review\Command\ResolveCommentCommand;
use App\Module\Review\Command\ResolveCommentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\ValueObject\Anchor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ReviseDocumentHandlerTest extends KernelTestCase
{
    public function test_revise_adds_version_reanchors_comments_and_sets_status_in_review(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(username: 'agent', fullName: 'Agent', email: 'agent@example.com', password: 'hashed');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        // Create document with first version containing "use JWTs and rate limiting".
        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Auth PRD', 'use JWTs and rate limiting'));

        $v1 = $doc->currentVersion();

        // Add an open comment on v1 — quote "JWTs" survives into the new version.
        $survivingComment = new Comment(
            $v1,
            $user,
            'why JWT?',
            new Anchor('JWTs', 'use ', ' and', 4),
        );
        // Add an open comment on v1 — quote "rate limiting" will be gone in new version.
        $orphanedComment = new Comment(
            $v1,
            $user,
            'limit?',
            new Anchor('rate limiting', 'and ', '', 13),
        );
        $em->persist($survivingComment);
        $em->persist($orphanedComment);
        $em->flush();

        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        // Revise with new markdown that keeps "JWTs" but removes "rate limiting".
        /** @var ReviseDocumentHandler $reviseHandler */
        $reviseHandler = self::getContainer()->get(ReviseDocumentHandler::class);
        $summary = $reviseHandler(new ReviseDocumentCommand($doc, 'use JWTs only'));

        self::assertSame(1, $summary['carried']);
        self::assertSame(1, $summary['orphaned']);

        // Clear and re-fetch to avoid stale in-memory state.
        $em->clear();
        $freshDoc = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);

        self::assertSame(2, $freshDoc->versions->count());
        self::assertSame(DocumentStatus::InReview, $freshDoc->status);

        // The new version should have 2 copied comments (one carried, one orphaned).
        /** @var CommentRepository $commentRepository */
        $commentRepository = self::getContainer()->get(CommentRepository::class);
        $v2Comments = $commentRepository->findByVersion($freshDoc->currentVersion());

        self::assertCount(2, $v2Comments);

        $carriedCopies = array_filter($v2Comments, fn (Comment $c) => !$c->orphaned);
        $orphanedCopies = array_filter($v2Comments, fn (Comment $c) => $c->orphaned);

        self::assertCount(1, $carriedCopies);
        self::assertCount(1, $orphanedCopies);

        $carried = reset($carriedCopies);
        self::assertInstanceOf(Comment::class, $carried);
        self::assertFalse($carried->orphaned);
        self::assertSame('why JWT?', $carried->body);
        self::assertSame('JWTs', $carried->anchor->quote);

        $orphaned = reset($orphanedCopies);
        self::assertInstanceOf(Comment::class, $orphaned);
        self::assertTrue($orphaned->orphaned);
    }

    /**
     * Regression for a resolved thread whose reply used to resurrect: findOpenByVersion() selected
     * on a per-row resolved flag with no parent check, so a reply of a resolved root was copied onto
     * the new version with its parent detached (the resolved root isn't in the open set), reappearing
     * as a brand-new open top-level thread on every subsequent revision.
     */
    public function test_resolved_thread_carries_nothing_forward_even_with_an_unresolved_reply(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(username: 'agent2', fullName: 'Agent', email: 'agent2@example.com', password: 'hashed');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Resolved Thread Doc', 'use JWTs and rate limiting'));

        $v1 = $doc->currentVersion();

        $root = new Comment($v1, $user, 'why JWT?', new Anchor('JWTs', 'use ', ' and', 4));
        $em->persist($root);
        $em->flush();

        /** @var ReplyToCommentHandler $replyHandler */
        $replyHandler = self::getContainer()->get(ReplyToCommentHandler::class);
        $replyHandler(new ReplyToCommentCommand(actor: $user, parent: $root, body: 'Still an open question'));

        /** @var ResolveCommentHandler $resolveHandler */
        $resolveHandler = self::getContainer()->get(ResolveCommentHandler::class);
        $resolveHandler(new ResolveCommentCommand(comment: $root));

        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        /** @var ReviseDocumentHandler $reviseHandler */
        $reviseHandler = self::getContainer()->get(ReviseDocumentHandler::class);
        $summary = $reviseHandler(new ReviseDocumentCommand($doc, 'use JWTs only'));

        self::assertSame(0, $summary['carried'], 'a resolved thread (root or reply) must carry nothing forward');
        self::assertSame(0, $summary['orphaned']);

        $em->clear();
        $freshDoc = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);

        /** @var CommentRepository $commentRepository */
        $commentRepository = self::getContainer()->get(CommentRepository::class);
        $v2Comments = $commentRepository->findByVersion($freshDoc->currentVersion());

        self::assertCount(0, $v2Comments, 'nothing from the resolved thread should appear on the new version');
    }

    /**
     * An addressed thread is still open: the agent claiming it acted is not the human agreeing the
     * thread is finished, so it must survive a revision — with its status, and with its replies
     * still attached to it rather than detached into threads of their own.
     */
    public function test_addressed_thread_carries_forward_with_its_status_and_its_reply(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(username: 'agent4', fullName: 'Agent', email: 'agent4@example.com', password: 'hashed');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Addressed Thread Doc', 'use JWTs and rate limiting'));

        $v1 = $doc->currentVersion();

        $root = new Comment($v1, $user, 'why JWT?', new Anchor('JWTs', 'use ', ' and', 4));
        $root->status = CommentStatus::Addressed;
        $em->persist($root);
        $em->flush();

        /** @var ReplyToCommentHandler $replyHandler */
        $replyHandler = self::getContainer()->get(ReplyToCommentHandler::class);
        $replyHandler(new ReplyToCommentCommand(actor: $user, parent: $root, body: 'Rewritten that section'));

        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        /** @var ReviseDocumentHandler $reviseHandler */
        $reviseHandler = self::getContainer()->get(ReviseDocumentHandler::class);
        $summary = $reviseHandler(new ReviseDocumentCommand($doc, 'use JWTs only'));

        self::assertSame(2, $summary['carried'], 'an addressed thread carries its root and its reply forward');
        self::assertSame(0, $summary['orphaned']);

        $em->clear();
        $freshDoc = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);

        /** @var CommentRepository $commentRepository */
        $commentRepository = self::getContainer()->get(CommentRepository::class);
        $v2Comments = $commentRepository->findByVersion($freshDoc->currentVersion());

        self::assertCount(2, $v2Comments);

        $roots = array_values(array_filter($v2Comments, static fn (Comment $c): bool => null === $c->parent));
        $replies = array_values(array_filter($v2Comments, static fn (Comment $c): bool => null !== $c->parent));

        self::assertCount(1, $roots, 'the reply must stay attached, not become a thread of its own');
        self::assertCount(1, $replies);
        self::assertSame(CommentStatus::Addressed, $roots[0]->status, 'the agent claim survives the revision');
        self::assertSame($roots[0], $replies[0]->parent);
        self::assertSame(CommentStatus::Addressed, $replies[0]->threadStatus);
    }

    /**
     * Regression guard for concurrent revisions computing the same "next
     * version number": two sequential revisions must land as versionNumber
     * 2 and 3, not both landing on 2 (the fixed bug) or the second one 500ing
     * on the unique constraint. This does not exercise true concurrency —
     * dama/doctrine-test-bundle wraps the whole test in one connection's
     * transaction, so two overlapping DB transactions cannot be expressed
     * here; the lock ordering itself is verified by code review.
     */
    public function test_two_sequential_revisions_get_consecutive_version_numbers(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User(username: 'agent3', fullName: 'Agent', email: 'agent3@example.com', password: 'hashed');
        $em->persist($user);
        $project = new Project($user, 'p-'.uniqid());
        $em->persist($project);
        $em->flush();

        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($project, 'Sequential Revisions', 'v1 content'));

        $docId = $doc->id;
        self::assertInstanceOf(Uuid::class, $docId);

        /** @var ReviseDocumentHandler $reviseHandler */
        $reviseHandler = self::getContainer()->get(ReviseDocumentHandler::class);
        $reviseHandler(new ReviseDocumentCommand($doc, 'v2 content'));
        $reviseHandler(new ReviseDocumentCommand($doc, 'v3 content'));

        $em->clear();
        $freshDoc = $em->find(Document::class, $docId);
        self::assertInstanceOf(Document::class, $freshDoc);

        $versionNumbers = array_map(
            static fn (DocumentVersion $version): int => $version->versionNumber,
            $freshDoc->versions->toArray(),
        );

        self::assertSame([1, 2, 3], $versionNumbers);
    }
}
