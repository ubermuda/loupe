<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReview;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Entity\SiteReviewStatus;
use App\Module\SiteReview\Mcp\GetSiteReviewTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetSiteReviewToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private GetSiteReviewTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(GetSiteReviewTool::class);
        self::assertInstanceOf(GetSiteReviewTool::class, $tool);
        $this->tool = $tool;
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{Project, SiteReview} project + one submitted review with 2 pending comments
     */
    private function projectWithSubmittedReview(string $email, string $name = 'tool-site'): array
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, $name);
        $this->em->persist($project);
        $review = new SiteReview($project);
        $review->addComment('first', '.a', 'A', 'https://app/x');
        $review->addComment('second', '', '', 'https://app/y');
        $review->markSubmitted();
        $this->em->persist($review);
        $this->em->flush();

        return [$project, $review];
    }

    public function test_returns_pending_comments_of_submitted_reviews_in_order(): void
    {
        $userEmail = 'get-order@example.com';
        $user = new User(username: $userEmail, fullName: 'U', email: $userEmail, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, 'order-site');
        $this->em->persist($project);

        // Older review — will appear first in results.
        $olderReview = new SiteReview($project);
        $olderReview->addComment('older-comment', '.b', 'B', 'https://app/old');
        $olderReview->status = SiteReviewStatus::Submitted;
        $olderReview->submittedAt = new \DateTimeImmutable('-1 hour');
        $this->em->persist($olderReview);

        // Newer review — 2 pending comments.
        $newerReview = new SiteReview($project);
        $newerReview->addComment('newer-first', '.c', 'C', 'https://app/new1');
        $newerReview->addComment('newer-second', '.d', 'D', 'https://app/new2');
        $newerReview->markSubmitted();
        $this->em->persist($newerReview);

        // Draft review — comments must NOT appear.
        $draftReview = new SiteReview($project);
        $draftReview->addComment('draft-comment', '.e', 'E', 'https://app/draft');
        $this->em->persist($draftReview);

        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);
        $result = ($this->tool)();

        self::assertSame((string) $project->id, $result['site']['id']);
        self::assertSame('order-site', $result['site']['name']);

        // Draft comment must be absent; only 3 pending comments from 2 submitted reviews.
        self::assertCount(3, $result['comments']);

        // Ordering: older review first (submittedAt ASC), then position ASC within each.
        $olderReviewId = (string) $olderReview->id;
        $newerReviewId = (string) $newerReview->id;

        self::assertSame($olderReviewId, $result['comments'][0]['reviewId']);
        self::assertSame($newerReviewId, $result['comments'][1]['reviewId']);
        self::assertSame($newerReviewId, $result['comments'][2]['reviewId']);

        // Pin intra-review position ordering (position ASC within each review).
        self::assertSame('older-comment', $result['comments'][0]['body']);
        self::assertSame('newer-first', $result['comments'][1]['body']);
        self::assertSame('newer-second', $result['comments'][2]['body']);

        // Each entry carries a non-empty id and reviewId.
        foreach ($result['comments'] as $comment) {
            self::assertNotEmpty($comment['id']);
            self::assertNotEmpty($comment['reviewId']);
        }
    }

    public function test_addressed_and_resolved_comments_are_excluded(): void
    {
        $userEmail = 'get-status@example.com';
        $user = new User(username: $userEmail, fullName: 'U', email: $userEmail, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, 'status-site');
        $this->em->persist($project);
        $review = new SiteReview($project);
        $c1 = $review->addComment('pending', '.a', 'A', 'https://app/x');
        $c2 = $review->addComment('addressed', '.b', 'B', 'https://app/y');
        $c3 = $review->addComment('resolved', '.c', 'C', 'https://app/z');
        $review->markSubmitted();
        $this->em->persist($review);
        $this->em->flush();

        $c2->status = SiteReviewCommentStatus::Addressed;
        $c3->status = SiteReviewCommentStatus::Resolved;
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);
        $result = ($this->tool)();

        self::assertCount(1, $result['comments']);
        self::assertSame('pending', $result['comments'][0]['body']);
    }

    public function test_matching_handle_by_name_or_id_returns_the_bound_project(): void
    {
        $userEmail = 'get-resolve@example.com';
        [$project] = $this->projectWithSubmittedReview($userEmail, 'resolve-site');

        $this->actAsMcpTokenBoundTo($project);

        $withoutHandle = ($this->tool)();
        $byName = ($this->tool)($project->name);
        $siteId = $project->id;
        self::assertNotNull($siteId);
        $byId = ($this->tool)((string) $siteId);

        self::assertSame($withoutHandle['comments'], $byName['comments']);
        self::assertSame($withoutHandle['site'], $byName['site']);
        self::assertSame($byName['comments'], $byId['comments']);
        self::assertSame($byName['site'], $byId['site']);
    }

    public function test_handle_naming_another_project_of_the_same_owner_is_rejected(): void
    {
        $userEmail = 'get-mismatch@example.com';
        [$boundProject] = $this->projectWithSubmittedReview($userEmail, 'bound-site');

        $otherProject = new Project($boundProject->owner, 'other-site');
        $this->em->persist($otherProject);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($boundProject);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Token is not bound to that project.');
        ($this->tool)('other-site');
    }

    public function test_unknown_handle_is_not_found(): void
    {
        $userEmail = 'get-unknown@example.com';
        [$project] = $this->projectWithSubmittedReview($userEmail, 'known-site');

        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('No site "nope" found.');
        ($this->tool)('nope');
    }

    public function test_unbound_mcp_token_is_rejected(): void
    {
        $userEmail = 'get-unbound@example.com';
        $user = new User(username: $userEmail, fullName: 'U', email: $userEmail, password: 'x');
        $this->em->persist($user);
        $this->em->flush();

        $this->actAsUnboundMcpToken($user);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)();
    }
}
