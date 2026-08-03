<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Repository\CommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\UX\Turbo\TurboBundle;

/**
 * The two edit-proposing endpoints. They share a handler, so what is worth proving
 * per endpoint is the shape each one produces: a strike posts an anchor and nothing
 * else, a rewording posts the text to substitute.
 */
final class StrikeAndSuggestControllerTest extends WebTestCase
{
    // The stateless CSRF manager accepts the cookie sentinel; a short literal is
    // rejected, so every form POST here must carry this exact value.
    private const string CSRF_TOKEN = 'csrf-token';

    // A Symfony-form POST is validated by SameOriginCsrfTokenManager, which needs
    // BOTH the sentinel token value and evidence of same-origin. A browser sends
    // the latter for free; BrowserKit only sets a Referer once a prior request has
    // happened, so the first POST in a test must state its origin explicitly.
    private const array TURBO_SERVER = [
        'HTTP_ACCEPT' => TurboBundle::STREAM_MEDIA_TYPE,
        'HTTP_ORIGIN' => 'http://localhost',
    ];

    private const string MARKDOWN = "# Title\n\nWe utilise a bespoke solution for authentication.";

    public function test_striking_a_passage_stores_an_empty_replacement(): void
    {
        [$client, $owner, $document] = $this->seed('striker');

        $client->request(
            Request::METHOD_POST,
            $this->url($document, 'strikes'),
            ['strike_passage_form' => [
                'quote' => 'bespoke ',
                'prefix' => 'We utilise a ',
                'suffix' => 'solution for',
                '_token' => self::CSRF_TOKEN,
            ]],
            server: self::TURBO_SERVER,
        );

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('target="comment-threads"', (string) $client->getResponse()->getContent());

        $comment = $this->onlyComment($document);
        self::assertSame('', $comment->replacement);
        self::assertTrue($comment->isStrike);
        self::assertSame('', $comment->body, 'a strike asks for no rationale, so none is stored');
        self::assertSame('bespoke ', $comment->anchor->quote);
        self::assertSame($owner->id, $comment->author->id);
    }

    public function test_suggesting_a_rewording_stores_the_replacement_and_the_rationale(): void
    {
        [$client, , $document] = $this->seed('rewriter');

        $client->request(
            Request::METHOD_POST,
            $this->url($document, 'suggestions'),
            ['suggest_rewording_form' => [
                'quote' => 'utilise',
                'prefix' => 'We ',
                'suffix' => ' a bespoke',
                'replacement' => 'use',
                'body' => 'Plainer word.',
                '_token' => self::CSRF_TOKEN,
            ]],
            server: self::TURBO_SERVER,
        );

        self::assertResponseIsSuccessful();

        $comment = $this->onlyComment($document);
        self::assertSame('use', $comment->replacement);
        self::assertFalse($comment->isStrike);
        self::assertSame('Plainer word.', $comment->body);
    }

    public function test_a_rewording_needs_no_rationale(): void
    {
        [$client, , $document] = $this->seed('terse');

        $client->request(
            Request::METHOD_POST,
            $this->url($document, 'suggestions'),
            ['suggest_rewording_form' => [
                'quote' => 'utilise',
                'prefix' => 'We ',
                'suffix' => ' a bespoke',
                'replacement' => 'use',
                'body' => '',
                '_token' => self::CSRF_TOKEN,
            ]],
            server: self::TURBO_SERVER,
        );

        self::assertResponseIsSuccessful();
        self::assertSame('', $this->onlyComment($document)->body);
    }

    /**
     * The one shape the interface must never accept: an empty replacement submitted
     * through the rewording form. Striking is its own action, and letting this
     * through would make the commonest edit reachable only via the slowest path.
     */
    public function test_an_empty_replacement_is_rejected_by_the_rewording_form(): void
    {
        [$client, , $document] = $this->seed('blanker');

        $client->request(
            Request::METHOD_POST,
            $this->url($document, 'suggestions'),
            ['suggest_rewording_form' => [
                'quote' => 'utilise',
                'prefix' => 'We ',
                'suffix' => ' a bespoke',
                'replacement' => '',
                'body' => 'Drop this word.',
                '_token' => self::CSRF_TOKEN,
            ]],
            server: self::TURBO_SERVER,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString(
            'target="suggest-composer-error"',
            (string) $client->getResponse()->getContent(),
        );
        self::assertSame([], $this->comments($document));
    }

    public function test_a_strike_with_no_selection_reports_into_the_standing_error_region(): void
    {
        [$client, , $document] = $this->seed('unanchored');

        $client->request(
            Request::METHOD_POST,
            $this->url($document, 'strikes'),
            ['strike_passage_form' => ['quote' => '', 'prefix' => '', 'suffix' => '', '_token' => self::CSRF_TOKEN]],
            server: self::TURBO_SERVER,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        // A strike has no composer open, so the message has nowhere else to land.
        self::assertStringContainsString(
            'target="review-action-error"',
            (string) $client->getResponse()->getContent(),
        );
        self::assertSame([], $this->comments($document));
    }

    /**
     * The agent is what applies a suggestion, so blocking the verdict on one would
     * deadlock the review. Approving while leaving small rewordings outstanding is
     * an ordinary reviewer outcome, and this is the record that it stays possible.
     */
    public function test_a_document_can_be_approved_with_an_outstanding_suggestion(): void
    {
        [$client, , $document] = $this->seed('approver');

        $client->request(
            Request::METHOD_POST,
            $this->url($document, 'suggestions'),
            ['suggest_rewording_form' => [
                'quote' => 'utilise',
                'prefix' => 'We ',
                'suffix' => ' a bespoke',
                'replacement' => 'use',
                'body' => '',
                '_token' => self::CSRF_TOKEN,
            ]],
            server: self::TURBO_SERVER,
        );
        self::assertResponseIsSuccessful();

        $client->request(
            Request::METHOD_POST,
            '/projects/'.$document->project->id.'/documents/'.$document->id.'/review/submit',
            ['submit_review_form' => ['verdict' => 'approved'], '_csrf_token' => 'csrf-token'],
        );

        self::assertResponseRedirects();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fetched = $em->find(Document::class, $document->id);
        self::assertInstanceOf(Document::class, $fetched);
        self::assertSame(DocumentStatus::Approved, $fetched->status);

        // Guard against a vacuous pass: the approval must have happened *while* the
        // suggestion was still unapplied, not after it quietly disappeared.
        $outstanding = $this->onlyComment($document);
        self::assertSame('use', $outstanding->replacement);
    }

    /** @return array{KernelBrowser, User, Document} */
    private function seed(string $username): array
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(
            username: $username,
            fullName: ucfirst($username),
            email: $username.'@example.com',
            password: 'hashed-password-placeholder',
        );
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($owner);

        $project = new Project($owner, 'p-'.uniqid());
        $em->persist($project);

        $document = new Document(owner: $owner, project: $project, title: 'Auth PRD');
        $document->addVersion(self::MARKDOWN, '<h1>Title</h1><p>We utilise a bespoke solution for authentication.</p>');
        $em->persist($document);
        $em->flush();

        $client->loginUser($owner);

        return [$client, $owner, $document];
    }

    private function url(Document $document, string $segment): string
    {
        return '/projects/'.$document->project->id.'/documents/'.$document->id.'/'.$segment;
    }

    /** @return list<Comment> */
    private function comments(Document $document): array
    {
        $comments = static::getContainer()->get(CommentRepository::class);
        self::assertInstanceOf(CommentRepository::class, $comments);

        return $comments->findByVersion($document->currentVersion());
    }

    private function onlyComment(Document $document): Comment
    {
        $comments = $this->comments($document);
        self::assertCount(1, $comments);

        return $comments[0];
    }
}
