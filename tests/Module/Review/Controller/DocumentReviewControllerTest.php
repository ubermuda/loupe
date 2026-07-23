<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\ValueObject\Anchor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\UX\Turbo\TurboBundle;

final class DocumentReviewControllerTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function createUser(EntityManagerInterface $em, string $username, string $email): User
    {
        $user = new User(
            username: $username,
            fullName: ucfirst($username),
            email: $email,
            password: 'hashed-password-placeholder',
        );
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);

        return $user;
    }

    private function project(EntityManagerInterface $em, User $owner): Project
    {
        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);

        return $project;
    }

    public function test_owner_sees_review_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner1', 'owner1@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'My Review Doc');
        $doc->addVersion('# Hello', '<h1>Hello</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'My Review Doc');
        self::assertSelectorExists('.lp-review-sidebar');
    }

    public function test_review_page_renders_ribbon_byline_and_verdict_bar(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'ribbonowner', 'ribbon@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Ribbon Doc');
        $doc->addVersion('# Hello', '<h1>Hello</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        // Loop ribbon renders with the current step highlighted (In review).
        self::assertSelectorExists('.lp-ribbon .lp-ribbon__circle--current');
        // Byline names the reviewer (the author side is the literal "Claude").
        self::assertSelectorTextContains('.lp-review-doc__byline', 'reviewed by');
        // Verdict bar shows both verdict buttons and NOT the approved confirmation.
        self::assertSelectorExists('button[name="verdict"][value="approved"]');
        self::assertSelectorExists('button[name="verdict"][value="changes-requested"]');
        self::assertSelectorNotExists('.lp-verdict-approved');
    }

    public function test_review_page_groups_threads_into_status_ladder(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'ladderowner', 'ladder@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Ladder Doc');
        $version = $doc->addVersion('# Body', '<h1>Body</h1>');
        $em->persist($doc);

        $pending = new Comment($version, $owner, 'A pending comment', new Anchor('Body', '', '', 0));
        $resolved = new Comment($version, $owner, 'A resolved comment', new Anchor('Body', '', '', 10));
        $resolved->resolved = true;
        $em->persist($pending);
        $em->persist($resolved);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        // Both ladder eyebrows render (grouping done in Twig).
        self::assertSelectorTextContains('.lp-ladder-group__eyebrow--pending', 'Pending');
        self::assertSelectorTextContains('.lp-ladder-group__eyebrow--resolved', 'Resolved');
        // Each thread carries its derived anchor status + a status chip.
        self::assertSelectorExists('[data-anchor-status="pending"]');
        self::assertSelectorExists('[data-anchor-status="resolved"]');
        self::assertSelectorExists('.lp-comment-thread--resolved');
    }

    public function test_resolving_a_comment_returns_the_whole_list_stream_regrouped(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'resolveowner', 'resolve@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Resolve Doc');
        $version = $doc->addVersion('# Body', '<h1>Body</h1>');
        $em->persist($doc);

        $comment = new Comment($version, $owner, 'Please fix this', new Anchor('Body', '', '', 0));
        $em->persist($comment);
        $em->flush();

        $commentId = (string) $comment->id;
        $em->clear();

        $client->loginUser($owner);
        // 'csrf-token' is the SameOriginCsrfTokenManager sentinel: a same-origin
        // Referer lets it stand in for the stateless comment-action token. The
        // Turbo Accept header selects the stream branch over the redirect fallback.
        $client->request(
            Request::METHOD_POST,
            '/comments/'.$commentId.'/resolve',
            ['_csrf_token' => 'csrf-token'],
            server: [
                'HTTP_ACCEPT' => TurboBundle::STREAM_MEDIA_TYPE,
                'HTTP_REFERER' => 'http://localhost/comments/'.$commentId.'/resolve',
            ],
        );

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        // The stream replaces the whole #comment-threads region, not a single card.
        self::assertStringContainsString('target="comment-threads"', $content);
        self::assertStringNotContainsString('target="comment-thread-'.$commentId.'"', $content);

        // The re-rendered list places the card under RESOLVED with a refreshed
        // count (1/1) and a full progress bar.
        self::assertStringContainsString('lp-ladder-group__eyebrow--resolved', $content);
        self::assertStringContainsString('lp-comment-thread--resolved', $content);
        self::assertStringContainsString('1/1 resolved', $content);
        self::assertStringContainsString('width: 100%', $content);

        // The comment is actually persisted as resolved.
        $fetched = $em->find(Comment::class, $comment->id);
        self::assertNotNull($fetched);
        self::assertTrue($fetched->resolved);
    }

    public function test_approved_document_shows_locked_confirmation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'approvedowner', 'approved@example.com');
        $project = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $project, title: 'Approved Doc');
        $doc->addVersion('# Done', '<h1>Done</h1>');
        $doc->status = DocumentStatus::Approved;
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseIsSuccessful();
        // Approved state replaces the verdict buttons with a locked confirmation.
        self::assertSelectorExists('.lp-verdict-approved');
        self::assertSelectorNotExists('button[name="verdict"]');
        // Ribbon shows Approved as the current step (last step).
        self::assertSelectorExists('.lp-ribbon__circle--current');
    }

    public function test_non_owner_gets_403(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner2', 'owner2@example.com');
        $other = $this->createUser($em, 'other2', 'other2@example.com');

        $project = $this->project($em, $owner);
        $doc = new Document(owner: $owner, project: $project, title: 'Owner Only Doc');
        $doc->addVersion('# Private', '<h1>Private</h1>');
        $em->persist($doc);
        $em->flush();

        $projectId = (string) $project->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($other);
        $client->request(Request::METHOD_GET, '/projects/'.$projectId.'/documents/'.$id.'/review');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_document_under_the_wrong_project_is_not_found(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createUser($em, 'owner3', 'owner3@example.com');
        $projectA = $this->project($em, $owner);
        $projectB = $this->project($em, $owner);

        $doc = new Document(owner: $owner, project: $projectA, title: 'Belongs To A');
        $doc->addVersion('# A', '<h1>A</h1>');
        $em->persist($doc);
        $em->flush();

        $projectBId = (string) $projectB->id;
        $id = (string) $doc->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$projectBId.'/documents/'.$id.'/review');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $client = static::createClient();
        $client->request(
            Request::METHOD_GET,
            '/projects/00000000-0000-0000-0000-000000000000/documents/00000000-0000-0000-0000-000000000000/review',
        );

        self::assertResponseRedirects('/login');
    }
}
