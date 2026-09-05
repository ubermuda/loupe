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
        $comment = new SiteReviewComment($project, 0, 'Fix this', 'https://example.com/')
            ->addAnchor('.hero h1', 'Hello world')
            ->addAnchor('.hero p', 'Sub heading');

        /** @var SiteReviewCommentRepository&Stub $repo */
        $repo = $this->createStub(SiteReviewCommentRepository::class);
        $repo->method('findByOwner')->willReturn([$comment]);

        $rows = iterator_to_array(new SiteReviewExporter($repo)->export($owner));

        self::assertCount(1, $rows);
        self::assertSame('My project', $rows[0]['project']);
        self::assertSame('Fix this', $rows[0]['body']);
        self::assertSame('https://example.com/', $rows[0]['url']);
        self::assertSame('pending', $rows[0]['status']);
        self::assertArrayHasKey('createdAt', $rows[0]);
        self::assertSame('site_reviews.json', new SiteReviewExporter($repo)->filename());

        self::assertArrayNotHasKey('selector', $rows[0]);
        self::assertSame([
            ['selector' => '.hero h1', 'text' => 'Hello world', 'quote' => null, 'quotePrefix' => null, 'quoteSuffix' => null],
            ['selector' => '.hero p', 'text' => 'Sub heading', 'quote' => null, 'quotePrefix' => null, 'quoteSuffix' => null],
        ], $rows[0]['anchors']);
    }

    public function test_an_unanchored_comment_exports_an_empty_anchor_list(): void
    {
        $owner = new User('Alice A', 'alice@example.com', 'x');
        $project = new Project($owner, 'My project', 'example.com');
        $comment = new SiteReviewComment($project, 0, 'A page note', 'https://example.com/');

        /** @var SiteReviewCommentRepository&Stub $repo */
        $repo = $this->createStub(SiteReviewCommentRepository::class);
        $repo->method('findByOwner')->willReturn([$comment]);

        $rows = iterator_to_array(new SiteReviewExporter($repo)->export($owner));

        self::assertSame([], $rows[0]['anchors']);
    }

    /**
     * The export is the user's own copy of their data, so it carries the points
     * themselves rather than the flag the agent-facing payload reports.
     */
    public function test_a_drawing_is_exported_in_full(): void
    {
        $owner = new User('Alice A', 'alice@example.com', 'x');
        $project = new Project($owner, 'My project', 'example.com');
        $drawn = new SiteReviewComment($project, 0, 'Point here', 'https://example.com/');
        $drawn->strokes = [['space' => 'page', 'points' => [[0.1, 0.2], [0.3, 0.4]]]];
        $plain = new SiteReviewComment($project, 1, 'A page note', 'https://example.com/');

        /** @var SiteReviewCommentRepository&Stub $repo */
        $repo = $this->createStub(SiteReviewCommentRepository::class);
        $repo->method('findByOwner')->willReturn([$drawn, $plain]);

        $rows = iterator_to_array(new SiteReviewExporter($repo)->export($owner));

        self::assertSame(
            [['space' => 'page', 'points' => [[0.1, 0.2], [0.3, 0.4]]]],
            $rows[0]['strokes'],
        );
        self::assertSame([], $rows[1]['strokes']);
    }
}
