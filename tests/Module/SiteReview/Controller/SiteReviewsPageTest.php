<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\Site;
use App\Module\SiteReview\Entity\SiteReview;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class SiteReviewsPageTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);

        return $user;
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{Site, SiteReview} site + one submitted review with 2 pending comments
     */
    private function siteWithSubmittedReview(EntityManagerInterface $em, string $email, string $siteName): array
    {
        $owner = $this->user($em, $email);
        $site = new Site($owner, $siteName);
        $em->persist($site);
        $review = new SiteReview($site);
        $review->addComment('First comment', '.selector', 'Selected text', 'https://example.com/page');
        $review->addComment('Second comment', '', '', 'https://example.com/other');
        $review->markSubmitted();
        $em->persist($review);
        $em->flush();

        return [$site, $review];
    }

    public function test_page_shows_submitted_review_with_statuses(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$site, $review] = $this->siteWithSubmittedReview($em, 'reviews-page-a@example.com', 'reviews-site-a');
        $owner = $site->owner;
        $reviewId = $review->id;
        $comments = $review->comments->toArray();
        $commentId = $comments[0]->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/site-review/sites/'.$site->id);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-review-id="'.$reviewId.'"]'));
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-comment-status="pending"]')->count());
        self::assertCount(1, $crawler->filter('[data-comment-id="'.$commentId.'"]'));
    }

    public function test_resolve_marks_comment_resolved(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$site, $review] = $this->siteWithSubmittedReview($em, 'reviews-page-b@example.com', 'reviews-site-b');
        $owner = $site->owner;
        $comments = $review->comments->toArray();
        $comment = $comments[0];
        $commentId = $comment->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/site-review/sites/'.$site->id);
        self::assertResponseIsSuccessful();

        $client->submitForm('Resolve');

        self::assertResponseRedirects('/site-review/sites/'.$site->id);

        $em->clear();
        $fresh = $em->find(SiteReviewComment::class, $commentId);
        self::assertNotNull($fresh);
        self::assertSame(SiteReviewCommentStatus::Resolved, $fresh->status);
    }

    public function test_reopen_returns_comment_to_pending(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$site, $review] = $this->siteWithSubmittedReview($em, 'reviews-page-c@example.com', 'reviews-site-c');
        $owner = $site->owner;
        $comments = $review->comments->toArray();
        $comment = $comments[0];
        $commentId = $comment->id;

        // Seed an Addressed comment so the Reopen button appears.
        $comment->status = SiteReviewCommentStatus::Addressed;
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/site-review/sites/'.$site->id);
        self::assertResponseIsSuccessful();

        $client->submitForm('Reopen');

        self::assertResponseRedirects('/site-review/sites/'.$site->id);

        $em->clear();
        $fresh = $em->find(SiteReviewComment::class, $commentId);
        self::assertNotNull($fresh);
        self::assertSame(SiteReviewCommentStatus::Pending, $fresh->status);
    }

    public function test_non_owner_cannot_resolve(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$site, $review] = $this->siteWithSubmittedReview($em, 'reviews-page-d@example.com', 'reviews-site-d');
        $other = $this->user($em, 'rvw-page-d-oth@example.com');
        $comments = $review->comments->toArray();
        $comment = $comments[0];
        $commentId = $comment->id;
        $em->flush();
        $em->clear();

        // The non-owner needs a valid CSRF context: GET a page they can access.
        $client->loginUser($other);
        $client->request(Request::METHOD_GET, '/site-review/sites');
        self::assertResponseIsSuccessful();

        // Direct POST with the sentinel CSRF token (valid stateless token).
        // The sentinel 'csrf-token' passes SameOriginCsrfTokenManager when BrowserKit
        // history provides a same-origin Referer — the preceding GET is load-bearing.
        $client->request(
            Request::METHOD_POST,
            '/site-review/comments/'.(string) $commentId.'/resolve',
            ['_csrf_token' => 'csrf-token'],
        );

        self::assertResponseStatusCodeSame(403);

        // Status must be unchanged.
        $em->clear();
        $fresh = $em->find(SiteReviewComment::class, $commentId);
        self::assertNotNull($fresh);
        self::assertSame(SiteReviewCommentStatus::Pending, $fresh->status);
    }

    public function test_draft_review_shows_no_action_buttons(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'reviews-page-e@example.com');
        $site = new Site($owner, 'reviews-site-e');
        $em->persist($site);
        $review = new SiteReview($site);
        $review->addComment('Draft comment', '.a', 'text', 'https://example.com');
        $em->persist($review);
        $em->flush();
        $reviewId = $review->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/site-review/sites/'.$site->id);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-review-id="'.$reviewId.'"]'));

        // Draft reviews must not render resolve/reopen forms.
        $reviewCard = $crawler->filter('[data-review-id="'.$reviewId.'"]');
        self::assertCount(0, $reviewCard->filter('button:contains("Resolve")'));
        self::assertCount(0, $reviewCard->filter('button:contains("Reopen")'));
    }
}
