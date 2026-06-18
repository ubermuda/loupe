<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Command;

use App\Module\Account\Entity\User;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
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
        $em->flush();

        // Create document with first version containing "use JWTs and rate limiting".
        /** @var CreateDocumentHandler $createHandler */
        $createHandler = self::getContainer()->get(CreateDocumentHandler::class);
        $doc = $createHandler(new CreateDocumentCommand($user, 'Auth PRD', 'use JWTs and rate limiting'));

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
        $summary = $reviseHandler(new ReviseDocumentCommand($docId, $user, 'use JWTs only'));

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

        $carried = array_first($carriedCopies);
        self::assertInstanceOf(Comment::class, $carried);
        self::assertSame('why JWT?', $carried->body);
        self::assertSame('JWTs', $carried->anchor->quote);
    }
}
