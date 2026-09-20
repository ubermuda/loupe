<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class SiteReviewsPageTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);

        return $user;
    }

    /**
     * @param non-empty-string $email
     *
     * @return array{Project, list<SiteReviewComment>} project + 2 pending comments
     */
    private function projectWithPendingComments(EntityManagerInterface $em, string $email, string $siteName): array
    {
        $owner = $this->user($em, $email);
        $project = new Project($owner, $siteName);
        $em->persist($project);
        $c1 = new SiteReviewComment($project, 0, 'First comment', 'https://example.com/page')->addAnchor('.selector', 'Selected text');
        $c1->status = SiteReviewCommentStatus::Pending;
        $c2 = new SiteReviewComment($project, 1, 'Second comment', 'https://example.com/other');
        $c2->status = SiteReviewCommentStatus::Pending;
        $em->persist($c1);
        $em->persist($c2);
        $em->flush();

        return [$project, [$c1, $c2]];
    }

    public function test_a_multi_anchor_comment_gets_one_selector_disclosure_per_anchor(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'reviews-page-anchors@example.com');
        $project = new Project($owner, 'reviews-site-anchors');
        $em->persist($project);
        $multi = new SiteReviewComment($project, 0, 'These belong side by side', 'https://example.com/page')
            ->addAnchor('.card', 'Save')
            ->addAnchor('.panel', 'Cancel');
        $multi->status = SiteReviewCommentStatus::Pending;
        $em->persist($multi);
        $em->flush();
        $commentId = $multi->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/site-review');

        self::assertResponseIsSuccessful();
        $article = $crawler->filter('[data-comment-id="'.$commentId.'"]');
        // One captured element per anchor, each with its selector behind a disclosure.
        self::assertCount(2, $article->filter('.lp-feedback-anchor'));
        self::assertCount(2, $article->filter('.lp-feedback-anchor__selector'));
        self::assertStringContainsString('Element 2', $article->text());
        self::assertStringContainsString('.card', $article->text());
        self::assertStringContainsString('.panel', $article->text());
    }

    public function test_page_shows_pending_comments_with_statuses(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$project, $comments] = $this->projectWithPendingComments($em, 'reviews-page-a@example.com', 'reviews-site-a');
        $owner = $project->owner;
        $commentId = $comments[0]->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/site-review');

        self::assertResponseIsSuccessful();
        // Flat comment list: both comments rendered with their number.
        self::assertCount(2, $crawler->filter('[data-comment-id]'));
        self::assertCount(2, $crawler->filter('.lp-feedback-list__number'));
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-comment-status="pending"]')->count());
        self::assertCount(1, $crawler->filter('[data-comment-id="'.$commentId.'"]'));
        // A missing translation renders its key, and the gate does not fail on that.
        self::assertDoesNotMatchRegularExpression('/\\bsite_review\\.[a-z_]+\\.[a-z_.]+/', $crawler->filter('main')->text());
    }

    public function test_the_page_carries_no_reply_thread_and_the_reply_route_is_gone(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$project, $comments] = $this->projectWithPendingComments($em, 'site-reply-page@example.com', 'Replies');
        $comment = $comments[1];
        $em->clear();

        $client->loginUser($project->owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/site-review');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-site-review-replies]'));
        self::assertCount(0, $crawler->filter('.lp-reply-composer'));
        $client->request(Request::METHOD_POST, '/site-review/comments/'.$comment->id.'/reply');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_resolve_marks_comment_resolved(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$project, $comments] = $this->projectWithPendingComments($em, 'reviews-page-b@example.com', 'reviews-site-b');
        $owner = $project->owner;
        $commentId = $comments[0]->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/site-review');
        self::assertResponseIsSuccessful();

        $client->submitForm('Resolve');

        self::assertResponseRedirects('/projects/'.$project->id.'/site-review');

        $em->clear();
        $fresh = $em->find(SiteReviewComment::class, $commentId);
        self::assertNotNull($fresh);
        self::assertSame(SiteReviewCommentStatus::Resolved, $fresh->status);
    }

    public function test_reopen_returns_comment_to_pending(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$project, $comments] = $this->projectWithPendingComments($em, 'reviews-page-c@example.com', 'reviews-site-c');
        $owner = $project->owner;
        $comment = $comments[0];
        $commentId = $comment->id;

        // Seed an Addressed comment so the Reopen button appears.
        $comment->status = SiteReviewCommentStatus::Addressed;
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/site-review');
        self::assertResponseIsSuccessful();

        $client->submitForm('Reopen');

        self::assertResponseRedirects('/projects/'.$project->id.'/site-review');

        $em->clear();
        $fresh = $em->find(SiteReviewComment::class, $commentId);
        self::assertNotNull($fresh);
        self::assertSame(SiteReviewCommentStatus::Pending, $fresh->status);
    }

    public function test_non_owner_cannot_resolve(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        [$project, $comments] = $this->projectWithPendingComments($em, 'reviews-page-d@example.com', 'reviews-site-d');
        $other = $this->user($em, 'rvw-page-d-oth@example.com');
        $commentId = $comments[0]->id;
        $em->flush();
        $em->clear();

        // The non-owner needs a valid CSRF context: GET a page they can access.
        $client->loginUser($other);
        $client->request(Request::METHOD_GET, '/projects');
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

    public function test_a_comment_is_listed_as_soon_as_it_is_saved(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'reviews-page-e@example.com');
        $project = new Project($owner, 'reviews-site-e');
        $em->persist($project);
        // Default status, exactly as the widget's save leaves it — no send step.
        $comment = new SiteReviewComment($project, 0, 'Straight from the widget', 'https://example.com')->addAnchor('.a', 'text');
        $em->persist($comment);
        $em->flush();
        $commentId = $comment->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/site-review');

        self::assertResponseIsSuccessful();
        $block = $crawler->filter('[data-comment-id="'.$commentId.'"]');
        self::assertCount(1, $block);
        self::assertSame('pending', $block->attr('data-comment-status'));
    }

    public function test_javascript_url_renders_without_anchor(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Build the malicious comment via the entity directly, bypassing API validation.
        $owner = $this->user($em, 'reviews-page-f@example.com');
        $project = new Project($owner, 'reviews-site-f');
        $em->persist($project);
        $comment = new SiteReviewComment($project, 0, 'sneaky', 'javascript:alert(1)')->addAnchor('.x', 'text');
        $comment->status = SiteReviewCommentStatus::Pending;
        $em->persist($comment);
        $em->flush();
        $commentId = $comment->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/site-review');

        self::assertResponseIsSuccessful();
        $commentBlock = $crawler->filter('[data-comment-id="'.$commentId.'"]');
        self::assertCount(1, $commentBlock);
        // The url must render as plain text, never as a clickable anchor.
        self::assertCount(0, $commentBlock->filter('a.lp-site-review-context__url'));
        self::assertStringContainsString('javascript:alert(1)', $commentBlock->text());
    }
}
