<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Entity;

use App\Module\Board\Entity\CardPullRequest;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Entity\InboxReview;
use App\Module\Inbox\Entity\InboxReviewTargetKind;
use App\Module\Inbox\Entity\InboxReviewVerdict;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InboxReviewTest extends KernelTestCase
{
    use InboxFixtures;

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_removing_a_target_retains_the_completed_result(bool $pullRequest): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = $this->owner($em, 'review-target');
        $project = $this->project($em, $owner, 'review-target');
        $target = $this->document($em, $project);
        if ($pullRequest) {
            $target = new CardPullRequest($this->card($em, $project), 'https://github.com/example/project/pull/42');
            $em->persist($target);
        }
        $item = new InboxItem($project, 1, InboxItemKind::Review, 'Review the change', true);
        $item->state = InboxItemState::Done;
        $review = new InboxReview($item, $target);
        $review->verdict = InboxReviewVerdict::ChangesRequested;
        $review->note = 'Explain how a retry preserves the response.';
        $review->reviewer = $owner;
        $review->submittedAt = new \DateTimeImmutable('2026-09-17 00:00:00');
        $review->reviewedVersionNumber = $pullRequest ? null : 1;
        $em->persist($item);
        $em->persist($review);
        $em->flush();
        $reviewId = $review->id;
        $label = $review->targetLabel;
        $em->clear();

        $stored = $em->find(InboxReview::class, $reviewId);
        self::assertInstanceOf(InboxReview::class, $stored);
        self::assertSame($pullRequest ? InboxReviewTargetKind::PullRequest : InboxReviewTargetKind::Document, $stored->targetKind);
        $storedTarget = $stored->document ?? $stored->pullRequest;
        self::assertNotNull($storedTarget);
        $em->remove($storedTarget);
        $em->flush();
        $em->clear();

        $stored = $em->find(InboxReview::class, $reviewId);
        self::assertInstanceOf(InboxReview::class, $stored);
        self::assertNull($stored->document);
        self::assertNull($stored->pullRequest);
        self::assertSame($label, $stored->targetLabel);
        self::assertSame(InboxReviewVerdict::ChangesRequested, $stored->verdict);
        self::assertSame('Explain how a retry preserves the response.', $stored->note);
        self::assertEquals($owner->id, $stored->reviewer?->id);
        self::assertSame('2026-09-17 00:00:00', $stored->submittedAt?->format('Y-m-d H:i:s'));
        self::assertSame($pullRequest ? null : 1, $stored->reviewedVersionNumber);
        self::assertSame(InboxItemState::Done, $stored->item->state);

        $em->remove($stored->item);
        $em->flush();
        $em->clear();
        self::assertNull($em->find(InboxReview::class, $reviewId));
    }

    public function test_an_item_has_only_one_review_record(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = $this->owner($em, 'review-unique');
        $project = $this->project($em, $owner, 'review-unique');
        $document = $this->document($em, $project);
        $item = new InboxItem($project, 1, InboxItemKind::Review, 'Review the change', true);
        $em->persist($item);
        $em->persist(new InboxReview($item, $document));
        $em->flush();
        $em->persist(new InboxReview($item, $document));

        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }

    public function test_a_question_cannot_become_a_review_by_linking_a_document(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $owner = $this->owner($em, 'review-question');
        $project = $this->project($em, $owner, 'review-question');
        $document = $this->document($em, $project);
        $item = $this->item($em, $project);

        $this->expectException(\InvalidArgumentException::class);
        new InboxReview($item, $document);
    }
}
