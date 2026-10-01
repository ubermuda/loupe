<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Service\InboxSearchIndexer;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** A notice on the inbox page: Loupe as its sender, its body, and no response form. */
final class NoticeItemPageTest extends WebTestCase
{
    use InboxScenario;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Project $project;
    private InboxItem $notice;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $owner = $this->signedUpUser($em, 'inbox-notice-page');
        $this->project = $this->inboxProject($em, $owner);
        $this->notice = new InboxItem(
            project: $this->project,
            number: 1,
            kind: InboxItemKind::Notice,
            title: 'A bridge rule races the app sync',
            blocking: false,
            body: "- `sync-behind` on bridge `01990000`\n\nRemove each rule on `pull_request.behind` from rules.yaml.",
        );
        $em->persist($this->notice);
        $em->flush();
        $this->setInboxFlag(true);
        $this->client->loginUser($owner);
    }

    public function test_an_open_notice_shows_its_body_from_loupe_with_no_form(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/inbox');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lp-inbox-request__byline', 'Loupe');
        self::assertSelectorTextSame('.lp-inbox-request__badges', 'Notice');
        $item = $crawler->filter('#inbox-item-1');
        self::assertCount(1, $item);
        self::assertStringContainsString('sync-behind', $item->filter('.lp-inbox-item__body')->text());
        self::assertCount(0, $item->filter('form'));
        self::assertCount(0, $item->filter('.lp-inbox-item__blocking'));
    }

    public function test_a_closed_notice_says_loupe_closed_it(): void
    {
        $this->notice->state = InboxItemState::Done;
        $this->notice->closedAt = new \DateTimeImmutable();
        $this->em->flush();
        $indexer = static::getContainer()->get(InboxSearchIndexer::class);
        self::assertInstanceOf(InboxSearchIndexer::class, $indexer);
        $indexer->index($this->notice);

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$this->project->id.'/inbox?q=races');

        self::assertResponseIsSuccessful();
        $editable = $crawler->filter('[data-inbox-item="1"] [data-inbox-editable]');
        self::assertCount(1, $editable);
        self::assertSame('no', $editable->attr('data-inbox-editable'));
        self::assertCount(0, $crawler->filter('[data-inbox-item="1"] form'));
    }
}
