<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReview;
use App\Module\SiteReview\Repository\SiteReviewRepository;
use App\Module\SiteReview\Service\SiteReviewExporter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class SiteReviewExporterTest extends TestCase
{
    public function test_exports_site_reviews_with_nested_comments(): void
    {
        $owner = new User('alice', 'Alice A', 'alice@example.com', 'x');
        $project = new Project($owner, 'My project', 'example.com');
        $siteReview = new SiteReview($project);
        $siteReview->addComment('Fix this', '.hero h1', 'Hello world', 'https://example.com/');

        /** @var SiteReviewRepository&Stub $repo */
        $repo = $this->createStub(SiteReviewRepository::class);
        $repo->method('findByOwner')->willReturn([$siteReview]);

        $rows = new SiteReviewExporter($repo)->export($owner);

        self::assertCount(1, $rows);
        self::assertSame('in-progress', $rows[0]['status']);
        self::assertCount(1, $rows[0]['comments']);
        self::assertSame(0, $rows[0]['comments'][0]['position']);
        self::assertSame('Fix this', $rows[0]['comments'][0]['body']);
        self::assertSame('.hero h1', $rows[0]['comments'][0]['selector']);
        self::assertSame('Hello world', $rows[0]['comments'][0]['text']);
        self::assertSame('https://example.com/', $rows[0]['comments'][0]['url']);
        self::assertSame('pending', $rows[0]['comments'][0]['status']);
        self::assertArrayHasKey('createdAt', $rows[0]['comments'][0]);
        self::assertSame('site_reviews.json', new SiteReviewExporter($repo)->filename());
    }
}
