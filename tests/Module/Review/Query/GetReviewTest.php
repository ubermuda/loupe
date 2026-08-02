<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Query;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Query\GetDocument;
use App\Module\Review\Query\GetReview;
use App\Module\Review\ValueObject\Anchor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetReviewTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private GetReview $getReview;
    private GetDocument $getDocument;
    private User $owner;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $getReview = self::getContainer()->get(GetReview::class);
        self::assertInstanceOf(GetReview::class, $getReview);
        $this->getReview = $getReview;

        $getDocument = self::getContainer()->get(GetDocument::class);
        self::assertInstanceOf(GetDocument::class, $getDocument);
        $this->getDocument = $getDocument;

        $this->owner = new User(
            username: 'owner',
            fullName: 'Owner User',
            email: 'owner@example.com',
            password: 'hashed',
        );
        $this->em->persist($this->owner);

        $this->project = new Project($this->owner, 'p-'.uniqid());
        $this->em->persist($this->project);
        $this->em->flush();
    }

    public function test_returns_review_shape_with_verdict_and_threaded_comments(): void
    {
        $doc = new Document(owner: $this->owner, project: $this->project, title: 'Auth PRD');
        $version = $doc->addVersion(
            'Use JWTs for authentication and rate limiting.',
            '<p>Use JWTs for authentication and rate limiting.</p>',
        );

        $rootComment = new Comment(
            $version,
            $this->owner,
            'Why JWTs? Consider opaque tokens.',
            new Anchor('JWTs', 'Use ', ' for', 4),
        );

        $reply = new Comment(
            $version,
            $this->owner,
            'JWTs allow stateless auth which suits the agent use-case.',
            new Anchor('JWTs', 'Use ', ' for', 4),
            parent: $rootComment,
        );

        $review = new Review($version, Verdict::ChangesRequested, $this->owner);

        $this->em->persist($doc);
        $this->em->persist($rootComment);
        $this->em->persist($reply);
        $this->em->persist($review);
        $this->em->flush();

        $result = ($this->getReview)($doc);

        self::assertSame('in-review', $result['status']);
        self::assertSame('changes-requested', $result['verdict']);
        self::assertSame(1, $result['version']);

        $comments = $result['comments'];
        // Only root comments appear at the top level (not the reply).
        self::assertCount(1, $comments);

        $root = $comments[0];
        self::assertSame('JWTs', $root['quote']);
        self::assertSame('Why JWTs? Consider opaque tokens.', $root['body']);
        self::assertFalse($root['resolved']);
        self::assertFalse($root['orphaned']);

        // The reply must appear in thread, not at the top level.
        self::assertCount(1, $root['thread']);
        $replyData = $root['thread'][0];
        self::assertSame('JWTs', $replyData['quote']);
        self::assertSame('JWTs allow stateless auth which suits the agent use-case.', $replyData['body']);
        self::assertFalse($replyData['resolved']);
        self::assertFalse($replyData['orphaned']);
    }

    public function test_verdict_is_null_when_no_review_submitted(): void
    {
        $doc = new Document(owner: $this->owner, project: $this->project, title: 'No Review Yet');
        $doc->addVersion('Some content.', '<p>Some content.</p>');

        $this->em->persist($doc);
        $this->em->flush();

        $result = ($this->getReview)($doc);

        self::assertNull($result['verdict']);
        self::assertSame('in-review', $result['status']);
        self::assertSame([], $result['comments']);
    }

    public function test_get_document_returns_correct_shape(): void
    {
        $doc = new Document(owner: $this->owner, project: $this->project, title: 'Shape Test');
        $doc->addVersion('# Hello', '<h1>Hello</h1>');

        $this->em->persist($doc);
        $this->em->flush();

        $result = ($this->getDocument)($doc);

        self::assertSame((string) $doc->id, $result['documentId']);
        self::assertSame('Shape Test', $result['title']);
        self::assertSame('in-review', $result['status']);
        self::assertSame(1, $result['version']);
        self::assertSame('# Hello', $result['markdown']);
    }
}
