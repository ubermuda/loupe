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
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, $name);
        $this->em->persist($project);
        $c1 = new SiteReviewComment($project, 0, 'first', 'https://app/x')->addAnchor('.a', 'A');
        $c1->status = SiteReviewCommentStatus::Pending;
        $c2 = new SiteReviewComment($project, 1, 'second', 'https://app/y');
        $c2->status = SiteReviewCommentStatus::Pending;
        $this->em->persist($c1);
        $this->em->persist($c2);
        $this->em->flush();

        return [$project, [$c1, $c2]];
    }

    public function test_returns_pending_comments_in_position_order(): void
    {
        $userEmail = 'get-order@example.com';
        $user = new User(fullName: 'U', email: $userEmail, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, 'order-site');
        $this->em->persist($project);

        $first = new SiteReviewComment($project, 0, 'older-comment', 'https://app/old')->addAnchor('.b', 'B');
        $first->status = SiteReviewCommentStatus::Pending;
        $this->em->persist($first);

        $second = new SiteReviewComment($project, 1, 'newer-first', 'https://app/new1')->addAnchor('.c', 'C');
        $second->status = SiteReviewCommentStatus::Pending;
        $this->em->persist($second);

        $third = new SiteReviewComment($project, 2, 'newer-second', 'https://app/new2')->addAnchor('.d', 'D');
        $third->status = SiteReviewCommentStatus::Pending;
        $this->em->persist($third);

        // An addressed comment must NOT appear — the queue is the pending ones.
        $addressed = new SiteReviewComment($project, 3, 'addressed-comment', 'https://app/done')->addAnchor('.e', 'E');
        $addressed->status = SiteReviewCommentStatus::Addressed;
        $this->em->persist($addressed);

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

    public function test_a_comment_reports_its_anchors_and_no_scalar_selector(): void
    {
        $user = new User(fullName: 'U', email: 'get-anchors@example.com', password: 'x');
        $this->em->persist($user);
        $project = new Project($user, 'anchor-site');
        $this->em->persist($project);

        $multi = new SiteReviewComment($project, 0, 'These two belong together', 'https://app/x')
            ->addAnchor('.card', 'Save')
            ->addAnchor('.panel', 'Cancel');
        $multi->status = SiteReviewCommentStatus::Pending;
        $this->em->persist($multi);

        $note = new SiteReviewComment($project, 1, 'a page note', 'https://app/y');
        $note->status = SiteReviewCommentStatus::Pending;
        $this->em->persist($note);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);
        $result = ($this->tool)();

        // The scalars are gone from the payload; anchors[] replaces them.
        self::assertArrayNotHasKey('selector', $result['comments'][0]);
        self::assertArrayNotHasKey('text', $result['comments'][0]);

        self::assertSame([
            ['selector' => '.card', 'text' => 'Save', 'quote' => null, 'quotePrefix' => null, 'quoteSuffix' => null],
            ['selector' => '.panel', 'text' => 'Cancel', 'quote' => null, 'quotePrefix' => null, 'quoteSuffix' => null],
        ], $result['comments'][0]['anchors']);

        self::assertSame([], $result['comments'][1]['anchors']);
    }

    public function test_addressed_and_resolved_comments_are_excluded(): void
    {
        $userEmail = 'get-status@example.com';
        $user = new User(fullName: 'U', email: $userEmail, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, 'status-site');
        $this->em->persist($project);

        $pending = new SiteReviewComment($project, 0, 'pending', 'https://app/x')->addAnchor('.a', 'A');
        $pending->status = SiteReviewCommentStatus::Pending;
        $addressed = new SiteReviewComment($project, 1, 'addressed', 'https://app/y')->addAnchor('.b', 'B');
        $addressed->status = SiteReviewCommentStatus::Addressed;
        $resolved = new SiteReviewComment($project, 2, 'resolved', 'https://app/z')->addAnchor('.c', 'C');
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

    public function test_status_reads_back_the_comments_the_agent_addressed(): void
    {
        $user = new User(fullName: 'U', email: 'get-readback@example.com', password: 'x');
        $this->em->persist($user);
        $project = new Project($user, 'readback-site');
        $this->em->persist($project);

        $pending = new SiteReviewComment($project, 0, 'pending', 'https://app/x')->addAnchor('.a', 'A');
        $addressed = new SiteReviewComment($project, 1, 'addressed', 'https://app/y')->addAnchor('.b', 'B');
        $addressed->status = SiteReviewCommentStatus::Addressed;
        $resolved = new SiteReviewComment($project, 2, 'resolved', 'https://app/z')->addAnchor('.c', 'C');
        $resolved->status = SiteReviewCommentStatus::Resolved;
        $this->em->persist($pending);
        $this->em->persist($addressed);
        $this->em->persist($resolved);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($project);

        // Marking a comment addressed used to put it beyond every read path in
        // this server, so an agent could not report on its own work.
        $bodies = static fn (array $r): array => array_column($r['comments'], 'body');

        self::assertSame(['pending'], $bodies(($this->tool)()));
        self::assertSame(['pending'], $bodies(($this->tool)(status: 'pending')));
        self::assertSame(['addressed'], $bodies(($this->tool)(status: 'addressed')));
        self::assertSame(['resolved'], $bodies(($this->tool)(status: 'resolved')));
        self::assertSame(['pending', 'addressed', 'resolved'], $bodies(($this->tool)(status: 'all')));
    }

    public function test_every_comment_carries_its_status(): void
    {
        $email = 'get-carries-status@example.com';
        [$project] = $this->projectWithPendingComments($email, 'carries-status-site');

        $this->actAsMcpTokenBoundTo($project);
        $result = ($this->tool)();

        self::assertNotEmpty($result['comments']);
        self::assertSame('pending', $result['comments'][0]['status']);
    }

    public function test_an_unknown_status_is_refused_rather_than_silently_ignored(): void
    {
        $email = 'get-bad-status@example.com';
        [$project] = $this->projectWithPendingComments($email, 'bad-status-site');

        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        ($this->tool)(status: 'addresed');
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
        $this->expectExceptionMessage('Site "other-site" not found or not accessible.');
        ($this->tool)('other-site');
    }

    public function test_unknown_handle_is_not_accessible(): void
    {
        $userEmail = 'get-unknown@example.com';
        [$project] = $this->projectWithPendingComments($userEmail, 'known-site');

        $this->actAsMcpTokenBoundTo($project);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Site "nope" not found or not accessible.');
        ($this->tool)('nope');
    }

    public function test_unbound_mcp_token_is_rejected(): void
    {
        $userEmail = 'get-unbound@example.com';
        $user = new User(fullName: 'U', email: $userEmail, password: 'x');
        $this->em->persist($user);
        $this->em->flush();

        $this->actAsUnboundMcpToken($user);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)();
    }
}
