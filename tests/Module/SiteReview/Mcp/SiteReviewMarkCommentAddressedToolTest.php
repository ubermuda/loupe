<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Mcp\SiteReviewMarkCommentAddressedTool;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SiteReviewMarkCommentAddressedToolTest extends KernelTestCase
{
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private SiteReviewMarkCommentAddressedTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(SiteReviewMarkCommentAddressedTool::class);
        self::assertInstanceOf(SiteReviewMarkCommentAddressedTool::class, $tool);
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
        $c1 = new SiteReviewComment($project, 0, 'first', '.a', 'A', 'https://app/x');
        $c1->status = SiteReviewCommentStatus::Pending;
        $c2 = new SiteReviewComment($project, 1, 'second', '', '', 'https://app/y');
        $c2->status = SiteReviewCommentStatus::Pending;
        $this->em->persist($c1);
        $this->em->persist($c2);
        $this->em->flush();

        return [$project, [$c1, $c2]];
    }

    public function test_addresses_pending_comments(): void
    {
        $email = 'addr-pending@example.com';
        [$project, $comments] = $this->projectWithPendingComments($email, 'addr-pending-site');

        $id1 = $comments[0]->id;
        $id2 = $comments[1]->id;
        self::assertNotNull($id1);
        self::assertNotNull($id2);

        $this->actAsMcpTokenBoundTo($project);
        $result = ($this->tool)([(string) $id1, (string) $id2]);

        self::assertCount(2, $result['addressed']);
        self::assertCount(0, $result['skipped']);
        self::assertContains((string) $id1, $result['addressed']);
        self::assertContains((string) $id2, $result['addressed']);

        // Second invocation with the same ids — both must be skipped as already_addressed.
        $result2 = ($this->tool)([(string) $id1, (string) $id2]);
        self::assertCount(0, $result2['addressed']);
        self::assertCount(2, $result2['skipped']);
        foreach ($result2['skipped'] as $skipped) {
            self::assertSame('already_addressed', $skipped['reason']);
        }

        // Verify DB state. The MCP layer structurally never assigns Resolved —
        // only Addressed — so asserting Addressed here also covers that invariant.
        $this->em->clear();
        $c1 = $this->em->find(SiteReviewComment::class, $id1);
        $c2 = $this->em->find(SiteReviewComment::class, $id2);
        self::assertNotNull($c1);
        self::assertNotNull($c2);
        self::assertSame(SiteReviewCommentStatus::Addressed, $c1->status);
        self::assertSame(SiteReviewCommentStatus::Addressed, $c2->status);
    }

    public function test_a_resolve_the_identity_map_has_not_seen_is_not_overwritten(): void
    {
        $email = 'addr-race@example.com';
        [$project, $comments] = $this->projectWithPendingComments($email, 'addr-race-site');

        $id = $comments[0]->id;
        self::assertNotNull($id);

        // Stands in for a human clicking Resolve after the tool has read the
        // row: written straight to the database so the tool's in-memory copy
        // still says Pending, which is what the race actually looks like.
        $this->em->createQuery(
            'UPDATE '.SiteReviewComment::class.' c SET c.status = :resolved WHERE c.id = :id'
        )
            ->setParameter('resolved', SiteReviewCommentStatus::Resolved)
            ->setParameter('id', $id, 'uuid')
            ->execute();
        self::assertSame(SiteReviewCommentStatus::Pending, $comments[0]->status);

        $this->actAsMcpTokenBoundTo($project);
        $result = ($this->tool)([(string) $id]);

        self::assertSame([], $result['addressed']);
        self::assertSame([['id' => (string) $id, 'reason' => 'resolved']], $result['skipped']);

        $this->em->clear();
        $fetched = $this->em->find(SiteReviewComment::class, $id);
        self::assertNotNull($fetched);
        self::assertSame(SiteReviewCommentStatus::Resolved, $fetched->status);
    }

    public function test_skips_resolved_addressed_unknown_and_invalid(): void
    {
        $email = 'addr-skip@example.com';
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, 'addr-skip-site');
        $this->em->persist($project);

        $resolvedComment = new SiteReviewComment($project, 0, 'resolved', '.a', 'A', 'https://app/x');
        $resolvedComment->status = SiteReviewCommentStatus::Resolved;
        $this->em->persist($resolvedComment);

        $addressedComment = new SiteReviewComment($project, 1, 'addressed', '.b', 'B', 'https://app/y');
        $addressedComment->status = SiteReviewCommentStatus::Addressed;
        $this->em->persist($addressedComment);

        $this->em->flush();

        $resolvedId = $resolvedComment->id;
        $addressedId = $addressedComment->id;
        self::assertNotNull($resolvedId);
        self::assertNotNull($addressedId);

        $randomUuid = (string) Uuid::v4();

        $this->actAsMcpTokenBoundTo($project);
        $result = ($this->tool)([
            (string) $resolvedId, // resolved
            $randomUuid,           // unknown
            'not-a-uuid',          // invalid_id
            (string) $addressedId, // already_addressed
        ]);

        self::assertCount(0, $result['addressed']);
        self::assertCount(4, $result['skipped']);

        $skippedByReason = [];
        foreach ($result['skipped'] as $s) {
            $skippedByReason[$s['reason']] = $s['id'];
        }

        self::assertArrayHasKey('resolved', $skippedByReason);
        self::assertArrayHasKey('unknown', $skippedByReason);
        self::assertArrayHasKey('invalid_id', $skippedByReason);
        self::assertArrayHasKey('already_addressed', $skippedByReason);

        self::assertSame((string) $resolvedId, $skippedByReason['resolved']);
        self::assertSame($randomUuid, $skippedByReason['unknown']);
        self::assertSame('not-a-uuid', $skippedByReason['invalid_id']);
        self::assertSame((string) $addressedId, $skippedByReason['already_addressed']);

        // No statuses should have changed.
        $this->em->clear();
        $refetched = $this->em->find(SiteReviewComment::class, $resolvedId);
        self::assertNotNull($refetched);
        self::assertSame(SiteReviewCommentStatus::Resolved, $refetched->status);
    }

    public function test_another_users_comment_is_skipped_as_unknown(): void
    {
        $ownerEmail = 'addr-owner@example.com';
        [, $comments] = $this->projectWithPendingComments($ownerEmail, 'addr-owner-site');

        $commentId = $comments[0]->id;
        self::assertNotNull($commentId);

        $otherEmail = 'addr-other@example.com';
        $other = new User(fullName: 'Other', email: $otherEmail, password: 'x');
        $this->em->persist($other);
        $otherProject = new Project($other, 'addr-other-site');
        $this->em->persist($otherProject);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($otherProject);
        $result = ($this->tool)([(string) $commentId]);

        self::assertCount(0, $result['addressed']);
        self::assertCount(1, $result['skipped']);
        self::assertSame('unknown', $result['skipped'][0]['reason']);
        self::assertSame((string) $commentId, $result['skipped'][0]['id']);

        // Status must remain Pending.
        $this->em->clear();
        $refetched = $this->em->find(SiteReviewComment::class, $commentId);
        self::assertNotNull($refetched);
        self::assertSame(SiteReviewCommentStatus::Pending, $refetched->status);
    }

    public function test_comment_in_another_project_of_the_same_owner_is_skipped_as_unknown(): void
    {
        $ownerEmail = 'addr-cross@example.com';
        [$project, $comments] = $this->projectWithPendingComments($ownerEmail, 'addr-cross-site');

        $commentId = $comments[0]->id;
        self::assertNotNull($commentId);

        // Token bound to a DIFFERENT project of the very same owner.
        $otherProject = new Project($project->owner, 'addr-cross-other');
        $this->em->persist($otherProject);
        $this->em->flush();

        $this->actAsMcpTokenBoundTo($otherProject);
        $result = ($this->tool)([(string) $commentId]);

        self::assertCount(0, $result['addressed']);
        self::assertCount(1, $result['skipped']);
        self::assertSame('unknown', $result['skipped'][0]['reason']);

        // Status must remain Pending.
        $this->em->clear();
        $refetched = $this->em->find(SiteReviewComment::class, $commentId);
        self::assertNotNull($refetched);
        self::assertSame(SiteReviewCommentStatus::Pending, $refetched->status);
    }

    public function test_unbound_mcp_token_is_rejected(): void
    {
        $email = 'addr-unbound@example.com';
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $this->em->flush();

        $this->actAsUnboundMcpToken($user);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)([]);
    }
}
