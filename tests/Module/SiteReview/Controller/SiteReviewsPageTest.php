<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
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
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
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
        $c1 = new SiteReviewComment($project, 0, 'First comment', '.selector', 'Selected text', 'https://example.com/page');
        $c1->status = SiteReviewCommentStatus::Pending;
        $c2 = new SiteReviewComment($project, 1, 'Second comment', '', '', 'https://example.com/other');
        $c2->status = SiteReviewCommentStatus::Pending;
        $em->persist($c1);
        $em->persist($c2);
        $em->flush();

        return [$project, [$c1, $c2]];
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
        // Flat comment list: both comments rendered with a status-colored index.
        self::assertCount(2, $crawler->filter('[data-comment-id]'));
        self::assertCount(2, $crawler->filter('.lp-site-review-index'));
        self::assertGreaterThanOrEqual(1, $crawler->filter('[data-comment-status="pending"]')->count());
        self::assertCount(1, $crawler->filter('[data-comment-id="'.$commentId.'"]'));
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

    public function test_draft_comment_shows_no_action_buttons(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'reviews-page-e@example.com');
        $project = new Project($owner, 'reviews-site-e');
        $em->persist($project);
        $comment = new SiteReviewComment($project, 0, 'Draft comment', '.a', 'text', 'https://example.com');
        $em->persist($comment);
        $em->flush();
        $commentId = $comment->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/site-review');

        self::assertResponseIsSuccessful();
        // The draft comment still renders in the flat list...
        $commentBlock = $crawler->filter('[data-comment-id="'.$commentId.'"]');
        self::assertCount(1, $commentBlock);

        // ...but a Draft comment must not render resolve/reopen forms.
        self::assertCount(0, $commentBlock->filter('button:contains("Resolve")'));
        self::assertCount(0, $commentBlock->filter('button:contains("Reopen")'));
    }

    public function test_javascript_url_renders_without_anchor(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // Build the malicious comment via the entity directly, bypassing API validation.
        $owner = $this->user($em, 'reviews-page-f@example.com');
        $project = new Project($owner, 'reviews-site-f');
        $em->persist($project);
        $comment = new SiteReviewComment($project, 0, 'sneaky', '.x', 'text', 'javascript:alert(1)');
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

    public function test_resolve_on_draft_comment_is_rejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'reviews-page-g@example.com');
        $project = new Project($owner, 'reviews-site-g');
        $em->persist($project);
        $comment = new SiteReviewComment($project, 0, 'Draft comment', '.a', 'text', 'https://example.com');
        $em->persist($comment);
        $em->flush();
        $commentId = $comment->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/site-review');
        self::assertResponseIsSuccessful();

        // The UI hides the buttons on drafts -- POST the route directly. The handler
        // precondition must reject the transition and redirect back with a flash.
        $client->request(
            Request::METHOD_POST,
            '/site-review/comments/'.(string) $commentId.'/resolve',
            ['_csrf_token' => 'csrf-token'],
        );

        self::assertResponseRedirects('/projects/'.$project->id.'/site-review');

        $em->clear();
        $fresh = $em->find(SiteReviewComment::class, $commentId);
        self::assertNotNull($fresh);
        self::assertSame(SiteReviewCommentStatus::Draft, $fresh->status);
    }
}
