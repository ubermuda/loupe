<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\SiteReview\Service\SiteReviewExporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class SiteReviewExporterTest extends TestCase
{
    public function test_exports_flat_comments(): void
    {
        $owner = new User('Alice A', 'alice@example.com', 'x');
        $project = new Project($owner, 'My project', 'example.com');
        $comment = new SiteReviewComment($project, 0, 'Fix this', '.hero h1', 'Hello world', 'https://example.com/');

        /** @var SiteReviewCommentRepository&Stub $repo */
        $repo = $this->createStub(SiteReviewCommentRepository::class);
        $repo->method('findByOwner')->willReturn([$comment]);

        $rows = new SiteReviewExporter($repo)->export($owner);

        self::assertCount(1, $rows);
        self::assertSame('My project', $rows[0]['project']);
        self::assertSame('Fix this', $rows[0]['body']);
        self::assertSame('.hero h1', $rows[0]['selector']);
        self::assertSame('Hello world', $rows[0]['text']);
        self::assertSame('https://example.com/', $rows[0]['url']);
        self::assertSame('draft', $rows[0]['status']);
        self::assertArrayHasKey('createdAt', $rows[0]);
        self::assertSame('site_reviews.json', new SiteReviewExporter($repo)->filename());
    }
}
