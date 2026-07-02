<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Mcp;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\Site;
use App\Module\SiteReview\Entity\SiteReview;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Mcp\AddressSiteReviewCommentsTool;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

final class AddressSiteReviewCommentsToolTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AddressSiteReviewCommentsTool $tool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(AddressSiteReviewCommentsTool::class);
        self::assertInstanceOf(AddressSiteReviewCommentsTool::class, $tool);
        $this->tool = $tool;
    }

    private function actAs(User $user): void
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $tokenStorage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
        $tokenStorage->setToken($token);
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{Site, SiteReview} site + one submitted review with 2 pending comments
     */
    private function siteWithSubmittedReview(string $email, string $name = 'tool-site'): array
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $site = new Site($user, $name);
        $this->em->persist($site);
        $review = new SiteReview($site);
        $review->addComment('first', '.a', 'A', 'https://app/x');
        $review->addComment('second', '', '', 'https://app/y');
        $review->markSubmitted();
        $this->em->persist($review);
        $this->em->flush();

        return [$site, $review];
    }

    public function test_addresses_pending_comments(): void
    {
        $email = 'addr-pending@example.com';
        [$site, $review] = $this->siteWithSubmittedReview($email, 'addr-pending-site');
        $user = $site->owner;

        $comments = $review->comments->toArray();
        self::assertCount(2, $comments);

        $id1 = $comments[0]->id;
        $id2 = $comments[1]->id;
        self::assertNotNull($id1);
        self::assertNotNull($id2);

        $this->actAs($user);
        $result = ($this->tool)([(string) $id1, (string) $id2]);

        self::assertCount(2, $result['addressed']);
        self::assertCount(0, $result['skipped']);
        self::assertContains((string) $id1, $result['addressed']);
        self::assertContains((string) $id2, $result['addressed']);

        // Verify DB state.
        $this->em->clear();
        $c1 = $this->em->find(SiteReviewComment::class, $id1);
        $c2 = $this->em->find(SiteReviewComment::class, $id2);
        self::assertNotNull($c1);
        self::assertNotNull($c2);
        self::assertSame(SiteReviewCommentStatus::Addressed, $c1->status);
        self::assertSame(SiteReviewCommentStatus::Addressed, $c2->status);
    }

    public function test_skips_non_pending_unknown_invalid_and_draft(): void
    {
        $email = 'addr-skip@example.com';
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $site = new Site($user, 'addr-skip-site');
        $this->em->persist($site);

        // Submitted review with one resolved comment (not_pending).
        $submittedReview = new SiteReview($site);
        $resolvedComment = $submittedReview->addComment('resolved', '.a', 'A', 'https://app/x');
        $submittedReview->markSubmitted();
        $this->em->persist($submittedReview);
        $this->em->flush();

        $resolvedComment->status = SiteReviewCommentStatus::Resolved;
        $this->em->flush();

        // Draft review with a pending comment (not_submitted).
        $draftReview = new SiteReview($site);
        $draftComment = $draftReview->addComment('draft', '.b', 'B', 'https://app/y');
        $this->em->persist($draftReview);
        $this->em->flush();

        $resolvedId = $resolvedComment->id;
        $draftId = $draftComment->id;
        self::assertNotNull($resolvedId);
        self::assertNotNull($draftId);

        $randomUuid = (string) Uuid::v4();

        $this->actAs($user);
        $result = ($this->tool)([
            (string) $resolvedId, // not_pending
            $randomUuid,           // unknown
            'not-a-uuid',          // invalid_id
            (string) $draftId,     // not_submitted
        ]);

        self::assertCount(0, $result['addressed']);
        self::assertCount(4, $result['skipped']);

        $skippedByReason = [];
        foreach ($result['skipped'] as $s) {
            $skippedByReason[$s['reason']] = $s['id'];
        }

        self::assertArrayHasKey('not_pending', $skippedByReason);
        self::assertArrayHasKey('unknown', $skippedByReason);
        self::assertArrayHasKey('invalid_id', $skippedByReason);
        self::assertArrayHasKey('not_submitted', $skippedByReason);

        self::assertSame((string) $resolvedId, $skippedByReason['not_pending']);
        self::assertSame($randomUuid, $skippedByReason['unknown']);
        self::assertSame('not-a-uuid', $skippedByReason['invalid_id']);
        self::assertSame((string) $draftId, $skippedByReason['not_submitted']);

        // No statuses should have changed.
        $this->em->clear();
        $refetched = $this->em->find(SiteReviewComment::class, $resolvedId);
        self::assertNotNull($refetched);
        self::assertSame(SiteReviewCommentStatus::Resolved, $refetched->status);
    }

    public function test_other_users_comment_is_skipped_as_unknown(): void
    {
        $ownerEmail = 'addr-owner@example.com';
        [, $review] = $this->siteWithSubmittedReview($ownerEmail, 'addr-owner-site');

        $comments = $review->comments->toArray();
        $commentId = $comments[0]->id;
        self::assertNotNull($commentId);

        $otherEmail = 'addr-other@example.com';
        $other = new User(username: $otherEmail, fullName: 'Other', email: $otherEmail, password: 'x');
        $this->em->persist($other);
        $this->em->flush();

        $this->actAs($other);
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

    public function test_never_resolves(): void
    {
        $email = 'addr-never@example.com';
        [$site, $review] = $this->siteWithSubmittedReview($email, 'addr-never-site');
        $user = $site->owner;

        $comments = $review->comments->toArray();
        self::assertCount(2, $comments);

        $id1 = $comments[0]->id;
        $id2 = $comments[1]->id;
        self::assertNotNull($id1);
        self::assertNotNull($id2);

        $this->actAs($user);
        $result = ($this->tool)([(string) $id1, (string) $id2]);

        self::assertCount(2, $result['addressed']);

        // After addressing, status must be Addressed — never Resolved.
        $this->em->clear();
        $c1 = $this->em->find(SiteReviewComment::class, $id1);
        $c2 = $this->em->find(SiteReviewComment::class, $id2);
        self::assertNotNull($c1);
        self::assertNotNull($c2);
        self::assertSame(SiteReviewCommentStatus::Addressed, $c1->status);
        self::assertSame(SiteReviewCommentStatus::Addressed, $c2->status);
        self::assertNotSame(SiteReviewCommentStatus::Resolved, $c1->status);
        self::assertNotSame(SiteReviewCommentStatus::Resolved, $c2->status);
    }
}
