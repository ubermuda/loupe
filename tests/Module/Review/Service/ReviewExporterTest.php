<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Repository\ReviewRepository;
use App\Module\Review\Service\ReviewExporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class ReviewExporterTest extends TestCase
{
    public function test_exports_flat_review_rows(): void
    {
        $reviewer = new User('Alice A', 'alice@example.com', 'x');
        $project = new Project($reviewer, 'My project');
        $document = new Document($reviewer, $project, 'My doc');
        $version = $document->addVersion('# v1', '<h1>v1</h1>');
        $review = new Review($version, Verdict::Approved, $reviewer);

        /** @var ReviewRepository&Stub $repo */
        $repo = $this->createStub(ReviewRepository::class);
        $repo->method('findByReviewer')->willReturn([$review]);

        $rows = new ReviewExporter($repo)->export($reviewer);

        self::assertCount(1, $rows);
        self::assertSame('My doc', $rows[0]['document']);
        self::assertSame(1, $rows[0]['versionNumber']);
        self::assertSame('approved', $rows[0]['verdict']);
        self::assertArrayHasKey('submittedAt', $rows[0]);
        self::assertSame('reviews.json', new ReviewExporter($repo)->filename());
    }
}
