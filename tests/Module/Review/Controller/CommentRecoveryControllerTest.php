<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\ValueObject\Anchor;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class CommentRecoveryControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Comment $root;
    private string $replyId;
    private string $reviewUrl;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = new User('Recovery owner', 'recovery-'.uniqid().'@example.com', 'x');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($owner, static::getContainer());
        $project = new Project($owner, 'Recovery');
        $document = new Document($owner, $project, 'Recoverable document');
        $version = $document->addVersion('Original', '<p>Original</p>');
        $this->root = new Comment($version, $owner, 'Retained root', new Anchor('Original', '', '', 0));
        $this->root->deletedAt = new \DateTimeImmutable('2000-01-01');
        $this->root->deletionSequence = 2;
        $reply = new Comment($version, $owner, 'Retained reply', $this->root->anchor, $this->root);
        $document->addVersion('Later', '<p>Later</p>');
        foreach ([$owner, $project, $document, $this->root, $reply] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->replyId = (string) $reply->id;
        $this->reviewUrl = '/projects/'.$project->id.'/documents/'.$document->id.'/review';
        $this->em->clear();
        $this->client->loginUser($owner);
    }

    public function test_restore_returns_an_old_thread_and_its_replies_to_the_original_version(): void
    {
        $rootId = (string) $this->root->id;
        $this->submitRecovery($rootId, 2);

        self::assertResponseRedirects($this->reviewUrl.'/versions/1#comment-thread-'.$rootId);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#comment-thread-'.$rootId, 'Retained root');
        $this->em->clear();
        $root = $this->em->find(Comment::class, $rootId);
        self::assertInstanceOf(Comment::class, $root);
        self::assertNull($root->deletedAt);
        self::assertSame(1, $root->version->versionNumber);
        self::assertNotNull($this->em->find(Comment::class, $this->replyId));
    }

    #[DataProvider('invalidSubmissions')]
    public function test_invalid_sequence_keeps_the_thread_and_reports_the_failure(?int $sequence): void
    {
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $this->submitRecovery((string) $this->root->id, $sequence);

        self::assertResponseRedirects($this->reviewUrl.'/versions/1');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.lp-flash--error', 'The deletion state has changed.');
        self::assertSame([], $audit->operations());
        $this->em->clear();
        $root = $this->em->find(Comment::class, $this->root->id);
        self::assertInstanceOf(Comment::class, $root);
        self::assertNotNull($root->deletedAt);
        self::assertSame(2, $root->deletionSequence);
    }

    public function test_another_owner_cannot_restore_the_thread(): void
    {
        $outsider = new User('Other owner', 'other-'.uniqid().'@example.com', 'x');
        $outsider->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($outsider, static::getContainer());
        $this->em->persist($outsider);
        $this->em->flush();
        $this->client->loginUser($outsider);

        $this->client->request(Request::METHOD_POST, '/comments/'.$this->root->id.'/restore');

        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('deleteFormats')]
    public function test_delete_retains_a_current_thread_and_offers_undo(string $accept): void
    {
        $document = $this->em->find(Document::class, $this->root->version->document->id);
        self::assertInstanceOf(Document::class, $document);
        $active = new Comment($document->currentVersion(), $document->owner, 'Current thread', new Anchor('Later', '', '', 0));
        $this->em->persist($active);
        $this->em->flush();
        $activeId = (string) $active->id;
        $this->client->request(Request::METHOD_GET, $this->reviewUrl);
        $this->client->request(Request::METHOD_POST, '/comments/'.$activeId.'/delete', ['_csrf_token' => 'csrf-token'], [], ['HTTP_ACCEPT' => $accept]);
        if ('text/html' === $accept) {
            self::assertResponseRedirects($this->reviewUrl.'/versions/2');
        } else {
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('turbo-stream[target="comment-recovery"]');
            self::assertStringContainsString('Undo', $this->client->getResponse()->getContent() ?: '');
        }
        $this->em->clear();
        $retained = $this->em->find(Comment::class, $activeId);
        self::assertInstanceOf(Comment::class, $retained);
        self::assertNotNull($retained->deletedAt);
        self::assertSame(1, $retained->deletionSequence);
    }

    public function test_undo_restores_the_thread_the_delete_notice_offers(): void
    {
        $document = $this->em->find(Document::class, $this->root->version->document->id);
        self::assertInstanceOf(Document::class, $document);
        $active = new Comment($document->currentVersion(), $document->owner, 'Current thread', new Anchor('Later', '', '', 0));
        $this->em->persist($active);
        $this->em->flush();
        $activeId = (string) $active->id;
        $this->client->request(Request::METHOD_GET, $this->reviewUrl);
        $this->client->request(Request::METHOD_POST, '/comments/'.$activeId.'/delete', ['_csrf_token' => 'csrf-token'], [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html']);

        $this->submitRecovery($activeId, 1);

        self::assertResponseRedirects($this->reviewUrl.'/versions/2#comment-thread-'.$activeId);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#comment-thread-'.$activeId, 'Current thread');
        $this->em->clear();
        $restored = $this->em->find(Comment::class, $activeId);
        self::assertInstanceOf(Comment::class, $restored);
        self::assertNull($restored->deletedAt);
    }

    /** @return iterable<string, array{string}> */
    public static function deleteFormats(): iterable
    {
        yield 'ordinary HTML' => ['text/html'];
        yield 'Turbo' => ['text/vnd.turbo-stream.html'];
    }

    /** @return iterable<string, array{?int}> */
    public static function invalidSubmissions(): iterable
    {
        yield 'stale' => [1];
        yield 'missing' => [null];
    }

    /**
     * The GET seeds the CSRF cookie the same-origin manager wants. The sequence
     * goes as a string, because IntegerType's transformer refuses anything else.
     */
    private function submitRecovery(string $commentId, ?int $sequence): void
    {
        $this->client->request(Request::METHOD_GET, $this->reviewUrl);
        $name = 'restore_comment_'.Uuid::fromString($commentId)->toBase32();
        $this->client->request(Request::METHOD_POST, '/comments/'.$commentId.'/restore', [
            $name => ['deletionSequence' => null === $sequence ? '' : (string) $sequence, '_token' => 'csrf-token'],
        ]);
    }
}
