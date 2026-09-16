<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\ValueObject\Anchor;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CommentRecoveryControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Comment $root;
    private string $replyId;
    private string $url;

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
        $this->url = '/projects/'.$project->id.'/documents/'.$document->id.'/deleted-threads';
        $this->em->clear();
        $this->client->loginUser($owner);
    }

    public function test_restore_returns_an_old_thread_and_its_replies_to_the_original_version(): void
    {
        $rootId = (string) $this->root->id;
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-deleted-thread]', 'Retained reply');
        $this->client->submitForm('Restore thread');
        self::assertResponseRedirects(str_replace('/deleted-threads', '/review/versions/1', $this->url).'#comment-thread-'.$rootId);
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

    public function test_purge_removes_the_root_and_replies(): void
    {
        $rootId = (string) $this->root->id;
        $crawler = $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->url);
        $this->client->submit($crawler->filter('dialog form')->form());
        self::assertResponseRedirects($this->url);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.lp-deleted-threads', 'No deleted threads.');
        $this->em->clear();
        self::assertNull($this->em->find(Comment::class, $rootId));
        self::assertNull($this->em->find(Comment::class, $this->replyId));
    }

    #[DataProvider('invalidSubmissions')]
    public function test_invalid_sequence_keeps_the_thread_and_shows_the_bound_error(string $action, ?int $sequence): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->url);
        $name = $action.'_comment_'.$this->root->id?->toBase32();
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/comments/'.$this->root->id.'/'.$action, [
            $name => ['deletionSequence' => $sequence, '_token' => 'csrf-token'],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.lp-field-errors li');
        if ('purge' === $action) {
            self::assertSelectorExists('[data-modal-reopen-value="true"]');
        }
        $this->em->clear();
        $root = $this->em->find(Comment::class, $this->root->id);
        self::assertInstanceOf(Comment::class, $root);
        self::assertNotNull($root->deletedAt);
        self::assertSame(2, $root->deletionSequence);
    }

    public function test_another_owner_cannot_read_restore_or_purge_the_thread(): void
    {
        $outsider = new User('Other owner', 'other-'.uniqid().'@example.com', 'x');
        $outsider->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($outsider, static::getContainer());
        $this->em->persist($outsider);
        $this->em->flush();
        $this->client->loginUser($outsider);
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->url);
        self::assertResponseStatusCodeSame(403);
        foreach (['restore', 'purge'] as $action) {
            $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/comments/'.$this->root->id.'/'.$action);
            self::assertResponseStatusCodeSame(403);
        }
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
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, str_replace('/deleted-threads', '/review', $this->url));
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/comments/'.$activeId.'/delete', ['_csrf_token' => 'csrf-token'], [], ['HTTP_ACCEPT' => $accept]);
        if ('text/html' === $accept) {
            self::assertResponseRedirects($this->url);
            $this->client->followRedirect();
            self::assertSelectorTextContains('[data-deleted-thread="'.$activeId.'"]', 'Undo');
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
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, $this->url);
        self::assertSelectorTextContains('[data-deleted-thread="'.$activeId.'"]', 'Current thread');
    }

    /** @return iterable<string, array{string}> */
    public static function deleteFormats(): iterable
    {
        yield 'ordinary HTML' => ['text/html'];
        yield 'Turbo' => ['text/vnd.turbo-stream.html'];
    }

    /** @return iterable<string, array{string, ?int}> */
    public static function invalidSubmissions(): iterable
    {
        foreach (['restore', 'purge'] as $action) {
            yield $action.' stale' => [$action, 1];
            yield $action.' missing' => [$action, null];
        }
    }
}
