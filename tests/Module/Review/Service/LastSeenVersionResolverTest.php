<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\ReviewRepository;
use App\Module\Review\Service\LastSeenVersionResolver;
use App\Module\Review\ValueObject\Watermark;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class LastSeenVersionResolverTest extends TestCase
{
    private CommentRepository&Stub $comments;
    private ReviewRepository&Stub $reviews;
    private Document $document;
    private User $reader;

    #[\Override]
    protected function setUp(): void
    {
        $this->comments = $this->createStub(CommentRepository::class);
        $this->reviews = $this->createStub(ReviewRepository::class);

        $this->reader = new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'hashed');
        $this->document = new Document(
            owner: $this->reader,
            project: new Project($this->reader, 'p-watermark'),
            title: 'Watermarked',
        );
    }

    public function test_an_anonymous_reader_has_no_watermark(): void
    {
        self::assertNull($this->resolver()->versionNumberFor($this->document, null));
    }

    public function test_a_reader_who_never_engaged_has_no_watermark(): void
    {
        $this->engagement(comment: null, verdict: null);

        self::assertNull($this->resolver()->versionNumberFor($this->document, $this->reader));
    }

    public function test_a_comment_alone_is_signal_enough(): void
    {
        $this->engagement(
            comment: new Watermark(new \DateTimeImmutable('2026-01-02 10:00:00'), 1),
            verdict: null,
        );

        self::assertSame(1, $this->resolver()->versionNumberFor($this->document, $this->reader));
    }

    public function test_a_verdict_alone_is_signal_enough(): void
    {
        $this->engagement(
            comment: null,
            verdict: new Watermark(new \DateTimeImmutable('2026-01-05 10:00:00'), 2),
        );

        self::assertSame(2, $this->resolver()->versionNumberFor($this->document, $this->reader));
    }

    public function test_the_later_engagement_wins(): void
    {
        $this->engagement(
            comment: new Watermark(new \DateTimeImmutable('2026-01-02 10:00:00'), 1),
            verdict: new Watermark(new \DateTimeImmutable('2026-01-05 10:00:00'), 4),
        );

        self::assertSame(4, $this->resolver()->versionNumberFor($this->document, $this->reader));
    }

    public function test_the_later_engagement_wins_whichever_side_it_is_on(): void
    {
        $this->engagement(
            comment: new Watermark(new \DateTimeImmutable('2026-01-05 10:00:00'), 4),
            verdict: new Watermark(new \DateTimeImmutable('2026-01-02 10:00:00'), 1),
        );

        self::assertSame(4, $this->resolver()->versionNumberFor($this->document, $this->reader));
    }

    /** Both events are the reader's own, so they demonstrably saw the later version. */
    public function test_two_engagements_in_the_same_second_resolve_to_the_higher_version(): void
    {
        $sameSecond = new \DateTimeImmutable('2026-01-05 10:00:00');
        $this->engagement(
            comment: new Watermark($sameSecond, 2),
            verdict: new Watermark($sameSecond, 5),
        );

        self::assertSame(5, $this->resolver()->versionNumberFor($this->document, $this->reader));
    }

    private function engagement(?Watermark $comment, ?Watermark $verdict): void
    {
        $this->comments->method('findWatermarkByDocumentAndAuthor')->willReturn($comment);
        $this->reviews->method('findWatermarkByDocumentAndReviewer')->willReturn($verdict);
    }

    private function resolver(): LastSeenVersionResolver
    {
        return new LastSeenVersionResolver($this->comments, $this->reviews);
    }
}
