<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Repository\ReviewRepository;
use App\Module\Review\Service\LastSeenVersionResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class LastSeenVersionResolverTest extends TestCase
{
    private CommentRepository&Stub $comments;
    private ReviewRepository&Stub $reviews;
    private DocumentVersionRepository&MockObject $documentVersions;
    private Document $document;
    private User $reader;

    #[\Override]
    protected function setUp(): void
    {
        $this->comments = $this->createStub(CommentRepository::class);
        $this->reviews = $this->createStub(ReviewRepository::class);
        $this->documentVersions = $this->createMock(DocumentVersionRepository::class);

        $this->reader = new User(fullName: 'Riley Chen', email: 'riley@example.com', password: 'hashed');
        $this->document = new Document(
            owner: $this->reader,
            project: new Project($this->reader, 'p-watermark'),
            title: 'Watermarked',
        );
    }

    public function test_an_anonymous_reader_has_no_watermark(): void
    {
        $this->documentVersions->expects($this->never())->method('findLatestNumberCreatedAtOrBefore');

        self::assertNull($this->resolver()->versionNumberFor($this->document, null));
    }

    public function test_a_reader_who_never_engaged_has_no_watermark(): void
    {
        $this->comments->method('findLatestCreatedAtByDocumentAndAuthor')->willReturn(null);
        $this->reviews->method('findLatestSubmittedAtByDocumentAndReviewer')->willReturn(null);
        $this->documentVersions->expects($this->never())->method('findLatestNumberCreatedAtOrBefore');

        self::assertNull($this->resolver()->versionNumberFor($this->document, $this->reader));
    }

    public function test_the_watermark_is_the_later_of_the_two_signals(): void
    {
        $comment = new \DateTimeImmutable('2026-01-02 10:00:00');
        $verdict = new \DateTimeImmutable('2026-01-05 10:00:00');

        $this->comments->method('findLatestCreatedAtByDocumentAndAuthor')->willReturn($comment);
        $this->reviews->method('findLatestSubmittedAtByDocumentAndReviewer')->willReturn($verdict);
        $this->documentVersions->expects($this->once())
            ->method('findLatestNumberCreatedAtOrBefore')
            ->with($this->document, $verdict)
            ->willReturn(4);

        self::assertSame(4, $this->resolver()->versionNumberFor($this->document, $this->reader));
    }

    public function test_a_comment_alone_is_signal_enough(): void
    {
        $comment = new \DateTimeImmutable('2026-01-02 10:00:00');

        $this->comments->method('findLatestCreatedAtByDocumentAndAuthor')->willReturn($comment);
        $this->reviews->method('findLatestSubmittedAtByDocumentAndReviewer')->willReturn(null);
        $this->documentVersions->expects($this->once())
            ->method('findLatestNumberCreatedAtOrBefore')
            ->with($this->document, $comment)
            ->willReturn(1);

        self::assertSame(1, $this->resolver()->versionNumberFor($this->document, $this->reader));
    }

    public function test_a_verdict_alone_is_signal_enough(): void
    {
        $verdict = new \DateTimeImmutable('2026-01-05 10:00:00');

        $this->comments->method('findLatestCreatedAtByDocumentAndAuthor')->willReturn(null);
        $this->reviews->method('findLatestSubmittedAtByDocumentAndReviewer')->willReturn($verdict);
        $this->documentVersions->expects($this->once())
            ->method('findLatestNumberCreatedAtOrBefore')
            ->with($this->document, $verdict)
            ->willReturn(2);

        self::assertSame(2, $this->resolver()->versionNumberFor($this->document, $this->reader));
    }

    public function test_a_watermark_no_version_predates_resolves_to_nothing(): void
    {
        $this->comments->method('findLatestCreatedAtByDocumentAndAuthor')
            ->willReturn(new \DateTimeImmutable('2026-01-02 10:00:00'));
        $this->reviews->method('findLatestSubmittedAtByDocumentAndReviewer')->willReturn(null);
        $this->documentVersions->expects($this->once())
            ->method('findLatestNumberCreatedAtOrBefore')
            ->willReturn(null);

        self::assertNull($this->resolver()->versionNumberFor($this->document, $this->reader));
    }

    private function resolver(): LastSeenVersionResolver
    {
        return new LastSeenVersionResolver($this->comments, $this->reviews, $this->documentVersions);
    }
}
