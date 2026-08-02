<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Mcp\SiteReviewGetTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SiteReviewGetToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private SiteReviewGetTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(SiteReviewGetTool::class);
        self::assertInstanceOf(SiteReviewGetTool::class, $tool);
        $this->tool = $tool;
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{Project, list<SiteReviewComment>} project + 2 pending comments
     */
    private function projectWithPendingComments(string $email, string $name = 'tool-site'): array
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, $name);
        $this->em->persist($project);
        $c1 = new SiteReviewComment($project, 0, 'first', '.a', 'A', 'https://app/x');
        $c1->status = SiteReviewCommentStatus::Pending;
        $c2 = new SiteReviewComment($project, 1, 'second', '', '', 'https://app/y');
        $c2->status = SiteReviewCommentStatus::Pending;
        $this->em->persist($c1);
        $this->em->persist($c2);
        $this->em->flush();

        return [$project, [$c1, $c2]];
    }

    public function test_returns_pending_comments_in_position_order(): void
    {
        $userEmail = 'get-order@example.com';
        $user = new User(username: $userEmail, fullName: 'U', email: $userEmail, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, 'order-site');
        $this->em->persist($project);

        $first = new SiteReviewComment($project, 0, 'older-comment', '.b', 'B', 'https://app/old');
        $first->status = SiteReviewCommentStatus::Pending;
        $this->em->persist($first);

        $second = new SiteReviewComment($project, 1, 'newer-first', '.c', 'C', 'https://app/new1');
        $second->status = SiteReviewCommentStatus::Pending;
        $this->em->persist($second);

        $third = new SiteReviewComment($project, 2, 'newer-second', '.d', 'D', 'https://app/new2');
        $third->status = SiteReviewCommentStatus::Pending;
        $this->em->persist($third);

        // Draft comment must NOT appear.
        $this->em->persist(new SiteReviewComment($project, 3, 'draft-comment', '.e', 'E', 'https://app/draft'));

        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);
        $result = ($this->tool)();

        self::assertSame((string) $project->id, $result['site']['id']);
        self::assertSame('order-site', $result['site']['name']);

        self::assertCount(3, $result['comments']);
        self::assertSame('older-comment', $result['comments'][0]['body']);
        self::assertSame('newer-first', $result['comments'][1]['body']);
        self::assertSame('newer-second', $result['comments'][2]['body']);

        foreach ($result['comments'] as $comment) {
            self::assertNotEmpty($comment['id']);
            self::assertNotEmpty($comment['createdAt']);
        }
    }

    public function test_addressed_and_resolved_comments_are_excluded(): void
    {
        $userEmail = 'get-status@example.com';
        $user = new User(username: $userEmail, fullName: 'U', email: $userEmail, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, 'status-site');
        $this->em->persist($project);

        $pending = new SiteReviewComment($project, 0, 'pending', '.a', 'A', 'https://app/x');
        $pending->status = SiteReviewCommentStatus::Pending;
        $addressed = new SiteReviewComment($project, 1, 'addressed', '.b', 'B', 'https://app/y');
        $addressed->status = SiteReviewCommentStatus::Addressed;
        $resolved = new SiteReviewComment($project, 2, 'resolved', '.c', 'C', 'https://app/z');
        $resolved->status = SiteReviewCommentStatus::Resolved;
        $this->em->persist($pending);
        $this->em->persist($addressed);
        $this->em->persist($resolved);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);
        $result = ($this->tool)();

        self::assertCount(1, $result['comments']);
        self::assertSame('pending', $result['comments'][0]['body']);
    }

    public function test_matching_handle_by_name_or_id_returns_the_bound_project(): void
    {
        $userEmail = 'get-resolve@example.com';
        [$project] = $this->projectWithPendingComments($userEmail, 'resolve-site');

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
        [$boundProject] = $this->projectWithPendingComments($userEmail, 'bound-site');

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
        [$project] = $this->projectWithPendingComments($userEmail, 'known-site');

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
