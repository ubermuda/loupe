<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Repository;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\ValueObject\Anchor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommentSignalsRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommentRepository $comments;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $repo = self::getContainer()->get(CommentRepository::class);
        self::assertInstanceOf(CommentRepository::class, $repo);
        $this->comments = $repo;
    }

    public function test_it_tallies_each_status_and_ignores_replies(): void
    {
        $author = $this->user('signals-statuses@example.com');
        $version = $this->version($author, 'Statuses');

        $pending = $this->comment($version, $author, CommentStatus::Pending);
        $this->comment($version, $author, CommentStatus::Addressed);
        $this->comment($version, $author, CommentStatus::Resolved);
        $this->comment($version, $author, CommentStatus::Resolved);
        // A reply keeps the default Pending status and is not a thread of its
        // own, so a tally that counted it would report two pending threads.
        $this->reply($version, $author, $pending);
        $this->em->flush();

        $signals = $this->comments->signalsByVersions([(string) $version->id]);
        $tally = $signals[(string) $version->id];

        self::assertSame(1, $tally->pendingThreadCount);
        self::assertSame(1, $tally->addressedThreadCount);
        self::assertSame(2, $tally->resolvedThreadCount);
        self::assertSame(4, $tally->threadCount());
        self::assertSame(2, $tally->openThreadCount());
        self::assertTrue($tally->hasAddressedThreads());
        self::assertFalse($tally->allThreadsAnswered());
    }

    public function test_it_counts_orphaned_thread_roots_only(): void
    {
        $author = $this->user('signals-orphans@example.com');
        $version = $this->version($author, 'Orphans');

        $orphaned = $this->comment($version, $author, CommentStatus::Pending);
        $orphaned->orphaned = true;
        $this->comment($version, $author, CommentStatus::Pending);
        // A reply copies its parent's anchor, so re-anchoring flags it too. One
        // broken anchor must still count once.
        $this->reply($version, $author, $orphaned)->orphaned = true;
        $this->em->flush();

        $signals = $this->comments->signalsByVersions([(string) $version->id]);

        self::assertSame(1, $signals[(string) $version->id]->orphanedThreadCount);
    }

    public function test_it_reports_every_version_asked_for_including_ones_with_no_comments(): void
    {
        $author = $this->user('signals-batch@example.com');
        $withComments = $this->version($author, 'With comments');
        $bare = $this->version($author, 'Bare');

        $this->comment($withComments, $author, CommentStatus::Addressed);
        $this->comment($withComments, $author, CommentStatus::Resolved);
        $this->em->flush();

        $signals = $this->comments->signalsByVersions([
            (string) $withComments->id,
            (string) $bare->id,
        ]);

        self::assertCount(2, $signals);
        self::assertTrue($signals[(string) $withComments->id]->allThreadsAnswered());
        self::assertSame(0, $signals[(string) $bare->id]->threadCount());
        // No threads means nothing was answered, so the badge must stay off.
        self::assertFalse($signals[(string) $bare->id]->allThreadsAnswered());
    }

    public function test_it_asks_nothing_of_the_database_for_an_empty_list(): void
    {
        self::assertSame([], $this->comments->signalsByVersions([]));
    }

    /** @param non-empty-string $email */
    private function user(string $email): User
    {
        $user = new User(fullName: 'Signals Author', email: $email, password: 'x');
        $this->em->persist($user);

        return $user;
    }

    private function version(User $owner, string $title): DocumentVersion
    {
        $project = new Project($owner, 'p-'.uniqid());
        $this->em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: $title);
        $version = $document->addVersion('# '.$title, '<h1>'.$title.'</h1>');
        $this->em->persist($document);
        $this->em->flush();

        return $version;
    }

    private function comment(DocumentVersion $version, User $author, CommentStatus $status): Comment
    {
        $comment = new Comment($version, $author, 'A thread root.', Anchor::unanchored());
        $comment->status = $status;
        $this->em->persist($comment);

        return $comment;
    }

    private function reply(DocumentVersion $version, User $author, Comment $parent): Comment
    {
        $reply = new Comment($version, $author, 'A reply.', $parent->anchor, $parent);
        $this->em->persist($reply);

        return $reply;
    }
}
