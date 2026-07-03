<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Twig;

use App\LoopStage;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\SiteReview\Twig\SiteReviewNavExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class SiteReviewNavExtensionTest extends TestCase
{
    private SiteReviewCommentRepository&Stub $comments;
    private SiteReviewNavExtension $extension;

    #[\Override]
    protected function setUp(): void
    {
        $this->comments = $this->createStub(SiteReviewCommentRepository::class);
        $this->extension = new SiteReviewNavExtension($this->comments);
    }

    /**
     * @param array{pending: int, addressed: int, resolved: int} $counts
     */
    #[DataProvider('stageCases')]
    public function test_loop_stage_maps_counts(array $counts, LoopStage $expected): void
    {
        $this->comments->method('statusCountsForProject')->willReturn($counts);

        self::assertSame($expected, $this->extension->loopStage($this->project()));
    }

    /**
     * @return iterable<string, array{array{pending: int, addressed: int, resolved: int}, LoopStage}>
     */
    public static function stageCases(): iterable
    {
        yield 'no comments → Proposed' => [
            ['pending' => 0, 'addressed' => 0, 'resolved' => 0],
            LoopStage::Proposed,
        ];
        yield 'any pending → In review' => [
            ['pending' => 1, 'addressed' => 2, 'resolved' => 3],
            LoopStage::InReview,
        ];
        yield 'no pending but addressed → Revise' => [
            ['pending' => 0, 'addressed' => 1, 'resolved' => 4],
            LoopStage::Revise,
        ];
        yield 'all resolved → Approved' => [
            ['pending' => 0, 'addressed' => 0, 'resolved' => 2],
            LoopStage::Approved,
        ];
    }

    private function project(): Project
    {
        $owner = new User(username: 'maya', fullName: 'Maya', email: 'maya@example.com', password: 'x');

        return new Project($owner, 'Acme');
    }
}
