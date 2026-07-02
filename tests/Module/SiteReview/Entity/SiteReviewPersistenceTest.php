<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Entity;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\Site;
use App\Module\SiteReview\Entity\SiteReview;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Entity\SiteReviewStatus;
use App\Module\SiteReview\Repository\SiteReviewRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SiteReviewPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_review_and_comments_persist_with_defaults(): void
    {
        $site = $this->site('persist@example.com');
        $review = new SiteReview($site);
        $review->addComment('too small', '.btn', 'Save', 'https://app/x');
        $this->em->persist($review);
        $this->em->flush();

        $reviewId = $review->id;
        self::assertNotNull($reviewId);
        $this->em->clear();

        $fresh = $this->em->find(SiteReview::class, $reviewId);
        self::assertNotNull($fresh);
        self::assertSame(SiteReviewStatus::InProgress, $fresh->status);
        self::assertNull($fresh->submittedAt);
        self::assertCount(1, $fresh->comments);
        $firstComment = $fresh->comments->first();
        self::assertNotFalse($firstComment);
        self::assertSame(SiteReviewCommentStatus::Pending, $firstComment->status);
        self::assertSame(0, $firstComment->position);
    }

    public function test_positions_do_not_collide_after_delete_then_add(): void
    {
        $site = $this->site('positions@example.com');
        $review = new SiteReview($site);
        $review->addComment('a', '.a', 'A', 'https://app/a');
        $review->addComment('b', '.b', 'B', 'https://app/b');
        $review->addComment('c', '.c', 'C', 'https://app/c');

        $first = $review->comments->first();
        self::assertNotFalse($first);
        $review->comments->removeElement($first);

        $added = $review->addComment('d', '.d', 'D', 'https://app/d');
        self::assertSame(3, $added->position);
    }

    public function test_only_one_in_progress_review_per_site(): void
    {
        $site = $this->site('one-draft@example.com');
        $this->em->persist(new SiteReview($site));
        $this->em->flush();

        $this->em->persist(new SiteReview($site));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function test_multiple_submitted_reviews_coexist(): void
    {
        $site = $this->site('many-submitted@example.com');
        foreach ([1, 2] as $i) {
            $review = new SiteReview($site);
            $review->markSubmitted();
            $this->em->persist($review);
        }
        $this->em->flush();

        $reviews = self::getContainer()->get(SiteReviewRepository::class);
        self::assertInstanceOf(SiteReviewRepository::class, $reviews);
        self::assertCount(2, $reviews->findBy(['site' => $site]));
    }

    public function test_new_in_progress_review_allowed_alongside_submitted(): void
    {
        $site = $this->site('after-submit@example.com');
        $submitted = new SiteReview($site);
        $submitted->markSubmitted();
        $this->em->persist($submitted);
        $this->em->flush();

        $this->em->persist(new SiteReview($site));
        $this->em->flush();

        $reviews = self::getContainer()->get(SiteReviewRepository::class);
        self::assertInstanceOf(SiteReviewRepository::class, $reviews);
        self::assertCount(2, $reviews->findBy(['site' => $site]));
    }

    /** @param non-empty-string $email */
    private function site(string $email): Site
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $site = new Site($user, 'test-site');
        $this->em->persist($site);
        $this->em->flush();

        return $site;
    }
}
