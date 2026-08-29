<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\ReviseDocumentCommand;
use App\Module\Review\Command\ReviseDocumentHandler;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\CommentStatus;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Service\LastSeenVersionResolver;
use App\Module\Review\ValueObject\Anchor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The resolver against real SQL. The unit test covers how the two signals are
 * combined; these cover which row each query picks, which is where the carried
 * copies of a comment matter.
 */
final class LastSeenVersionResolverQueryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private User $reader;
    private Document $document;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->reader = new User(fullName: 'Riley Chen', email: 'reader-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($this->reader);
        $project = new Project($this->reader, 'p-'.uniqid());
        $this->em->persist($project);

        $this->document = new Document(owner: $this->reader, project: $project, title: 'Watermarked');
        $this->document->addVersion('The plan mentions JWTs.', '<p>The plan mentions JWTs.</p>');
        $this->em->persist($this->document);
        $this->em->flush();
    }

    public function test_a_carried_comment_resolves_to_the_version_it_was_written_on(): void
    {
        $this->comment($this->reader);
        $this->revise('The plan mentions JWTs and rotation.');
        $this->revise('The plan mentions JWTs, rotation and revocation.');

        // The comment now has a row on v1, v2 and v3, all sharing one createdAt.
        self::assertSame(1, $this->resolve());
    }

    public function test_a_comment_resolved_before_the_revision_is_not_carried_and_still_resolves(): void
    {
        $comment = $this->comment($this->reader);
        $comment->status = CommentStatus::Resolved;
        $this->em->flush();

        $this->revise('The plan mentions JWTs and rotation.');

        self::assertSame(1, $this->resolve());
    }

    public function test_the_newest_comment_wins_over_an_older_one_still_being_carried(): void
    {
        $this->comment($this->reader, createdAt: new \DateTimeImmutable('-2 hours'));
        $this->revise('The plan mentions JWTs and rotation.');

        $this->comment($this->reader, version: 2);
        $this->revise('The plan mentions JWTs, rotation and revocation.');

        // Both comments now have a row on v3; the newer one was written on v2.
        self::assertSame(2, $this->resolve());
    }

    public function test_a_reply_counts_like_any_other_comment(): void
    {
        $other = new User(fullName: 'Claude', email: 'agent-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($other);

        $root = $this->comment($other);
        $this->comment($this->reader, parent: $root);

        $this->revise('The plan mentions JWTs and rotation.');
        $this->revise('The plan mentions JWTs, rotation and revocation.');

        self::assertSame(1, $this->resolve());
    }

    public function test_a_verdict_resolves_to_the_version_it_was_given_on(): void
    {
        $this->em->persist(new Review(
            version: $this->document->currentVersion(),
            verdict: Verdict::Approved,
            reviewer: $this->reader,
        ));
        $this->em->flush();

        $this->revise('The plan mentions JWTs and rotation.');

        self::assertSame(1, $this->resolve());
    }

    public function test_a_reader_who_never_engaged_has_no_watermark(): void
    {
        $this->revise('The plan mentions JWTs and rotation.');

        self::assertNull($this->resolve());
    }

    private function comment(User $author, int $version = 1, ?Comment $parent = null, ?\DateTimeImmutable $createdAt = null): Comment
    {
        $target = $this->document->versions->filter(
            static fn (DocumentVersion $candidate): bool => $candidate->versionNumber === $version,
        )->first() ?: throw new \LogicException('No such version.');

        $comment = new Comment(
            version: $target,
            author: $author,
            body: 'Which signing algorithm?',
            anchor: new Anchor('JWTs', 'mentions ', '.', 18),
            parent: $parent,
            createdAt: $createdAt ?? new \DateTimeImmutable(),
        );
        $this->em->persist($comment);
        $this->em->flush();

        return $comment;
    }

    private function revise(string $markdown): void
    {
        $handler = self::getContainer()->get(ReviseDocumentHandler::class);
        self::assertInstanceOf(ReviseDocumentHandler::class, $handler);
        $handler(new ReviseDocumentCommand($this->document, $markdown, 'A revision'));
    }

    private function resolve(): ?int
    {
        $resolver = self::getContainer()->get(LastSeenVersionResolver::class);
        self::assertInstanceOf(LastSeenVersionResolver::class, $resolver);

        return $resolver->versionNumberFor($this->document, $this->reader);
    }
}
