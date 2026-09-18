<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Repository;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\ValueObject\Anchor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommentDeletionRepositoryTest extends KernelTestCase
{
    public function test_active_queries_exclude_deleted_threads_and_recovery_preserves_them(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $comments = self::getContainer()->get(CommentRepository::class);
        $author = new User('Thread Author', 'retained-'.uniqid().'@example.com', 'x');
        $project = new Project($author, 'Retained threads');
        $document = new Document($author, $project, 'Document');
        $version = $document->addVersion('Content', '<p>Content</p>');
        $other = new Document($author, $project, 'Other document');
        $otherVersion = $other->addVersion('Other', '<p>Other</p>');
        $active = new Comment($version, $author, 'Visible', Anchor::unanchored(), createdAt: new \DateTimeImmutable('-2 days'));
        $activeReply = new Comment($version, $author, 'Visible reply', Anchor::unanchored(), $active, createdAt: new \DateTimeImmutable('-1 day'));
        $deleted = new Comment($version, $author, 'Retained', Anchor::unanchored());
        $deleted->deletedAt = new \DateTimeImmutable();
        $deletedReply = new Comment($version, $author, 'Retained reply', Anchor::unanchored(), $deleted);
        $otherDeleted = new Comment($otherVersion, $author, 'Other retained', Anchor::unanchored());
        $otherDeleted->deletedAt = new \DateTimeImmutable();
        foreach ([$author, $project, $document, $other, $active, $activeReply, $deleted, $deletedReply, $otherDeleted] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        self::assertEqualsCanonicalizing([$active, $activeReply], $comments->findByVersion($version));
        self::assertEqualsCanonicalizing([$active, $activeReply], $comments->findOpenByVersion($version));
        self::assertSame([$activeReply], $comments->findReplies($active));
        self::assertSame([], $comments->findReplies($deleted));
        self::assertSame([$deletedReply], $comments->findRepliesIncludingDeleted($deleted));
        self::assertEqualsCanonicalizing([$deleted, $deletedReply], $comments->findDeletedByDocument($document));
        self::assertEqualsCanonicalizing([$active, $activeReply, $deleted, $deletedReply, $otherDeleted], $comments->findByAuthor($author));
        self::assertSame($activeReply->createdAt?->getTimestamp(), $comments->findLatestEngagementByDocumentAndAuthor($document, $author)?->at->getTimestamp());
        self::assertNull($comments->findLatestEngagementByDocumentAndAuthor($other, $author));

        $deleted->deletedAt = null;
        $em->flush();

        self::assertEqualsCanonicalizing([$active, $activeReply, $deleted, $deletedReply], $comments->findByVersion($version));
        self::assertSame([], $comments->findDeletedByDocument($document));
        self::assertSame([$deletedReply], $comments->findReplies($deleted));
    }

    public function test_deleted_threads_do_not_contribute_status_or_orphan_signals(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $comments = self::getContainer()->get(CommentRepository::class);
        $author = new User('Signals Author', 'deleted-signals-'.uniqid().'@example.com', 'x');
        $project = new Project($author, 'Deleted signals');
        $document = new Document($author, $project, 'Document');
        $version = $document->addVersion('Content', '<p>Content</p>');
        foreach ([$author, $project, $document] as $entity) {
            $em->persist($entity);
        }
        foreach (CommentStatus::cases() as $status) {
            $comment = new Comment($version, $author, 'Retained', Anchor::unanchored());
            $comment->status = $status;
            $comment->orphaned = true;
            $comment->deletedAt = new \DateTimeImmutable();
            $em->persist($comment);
        }
        $visible = new Comment($version, $author, 'Visible', Anchor::unanchored());
        $em->persist($visible);
        $em->flush();

        self::assertCount(4, $comments->findByAuthor($author));
        $signals = $comments->signalsByVersions([(string) $version->id])[(string) $version->id];
        self::assertSame(1, $signals->pendingThreadCount);
        self::assertSame(0, $signals->addressedThreadCount);
        self::assertSame(0, $signals->resolvedThreadCount);
        self::assertSame(0, $signals->orphanedThreadCount);
        self::assertSame(0, $signals->pendingOrphanedThreadCount);
    }
}
