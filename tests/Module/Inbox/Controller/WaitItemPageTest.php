<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Inbox\Entity\InboxCardWaitEndReason;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Repository\InboxCardWatchRepository;
use App\Module\Inbox\Service\CardWaitReconciler;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Tests\Module\Inbox\InboxFixtures;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** A wait item on the inbox page: Loupe as its sender, its waits, and Dismiss as its one response. */
final class WaitItemPageTest extends WebTestCase
{
    use InboxScenario;
    use InboxFixtures;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Project $project;
    private Card $card;
    private Document $document;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $owner = $this->signedUpUser($em, 'inbox-wait-page');
        $this->project = $this->inboxProject($em, $owner);
        $this->seedColumns($this->project);
        $this->card = new Card($this->project, $this->column($this->project, 'backlog'), 'Ship it', 'Body', number: 4);
        $this->document = new Document($owner, $this->project, 'Tech design');
        $this->document->addVersion('# One', '<h1>One</h1>');
        $this->card->documents->add(new CardDocument($this->card, $this->document));
        $this->stageDocument($em, $this->document, $this->card);
        $em->persist($this->card);
        $em->persist($this->document);
        $em->flush();
        $this->setInboxFlag(true);
        $this->client->loginUser($owner);
    }

    public function test_the_row_and_the_panel_name_loupe_as_the_sender_with_no_presence(): void
    {
        $this->reconcile();

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lp-inbox-request__byline', 'Loupe');
        self::assertSelectorTextNotContains('.lp-inbox-request__byline', 'Agent request');
        self::assertSelectorTextSame('.lp-inbox-ask__source', 'Loupe');
        self::assertCount(1, $crawler->filter('.lp-inbox-ask__head [data-inbox-source="loupe"]'));
        self::assertCount(0, $crawler->filter('[data-inbox-presence]'));
        self::assertCount(0, $crawler->filter('.lp-presence__dot'));
    }

    public function test_the_panel_names_the_card_once_and_shows_no_ask_context(): void
    {
        $this->reconcile();

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.lp-inbox-ask__title', '#4 Ship it');
        self::assertCount(0, $crawler->filter('.lp-inbox-ask__context'));
        self::assertCount(1, $crawler->filter('#inbox-item-1 [data-linked-card="'.$this->card->id.'"]'));
    }

    /** @return iterable<string, array{string}> */
    public static function loupeCloses(): iterable
    {
        yield 'done, after a verdict' => ['approve'];
        yield 'obsolete, after the card finished' => ['finish'];
    }

    #[DataProvider('loupeCloses')]
    public function test_an_item_loupe_closed_says_so_and_refuses_a_dismissal(string $cause): void
    {
        $this->reconcile();
        if ('approve' === $cause) {
            $this->document->status = DocumentStatus::Approved;
        } else {
            $this->card->column = $this->column($this->project, 'done');
        }
        $this->em->flush();
        $this->reconcile();
        $item = $this->onlyWatch()->item;
        self::assertSame('approve' === $cause ? InboxItemState::Done : InboxItemState::Obsolete, $item->state);

        $this->client->request(Request::METHOD_GET, $this->pageUrl().'?queue=completed');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#inbox-item-1 [data-inbox-editable]', 'Loupe closed this item, so it takes no response.');
        self::assertSelectorTextNotContains('#inbox-item-1', 'The agent closed');

        $url = '/projects/'.$this->project->id.'/inbox/items/'.$item->id.'/decline';
        $this->client->request(Request::METHOD_POST, $url, ['inbox_decline_'.$item->id => ['closeNote' => '', '_token' => 'csrf-token']], [], ['HTTP_REFERER' => 'http://localhost'.$url]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#inbox-item-1 [data-inbox-refusal]', 'Loupe closed this item');
        self::assertSame($item->state, $this->onlyWatch()->item->state);
    }

    public function test_a_search_result_outside_its_ask_still_names_loupe(): void
    {
        $this->reconcile();

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl().'?q=Tech');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-inbox-request__byline [data-inbox-source="loupe"]'));
        self::assertCount(0, $crawler->filter('[data-inbox-source="agent"]'));
    }

    public function test_the_panel_lists_the_current_and_ended_waits_and_offers_only_dismiss(): void
    {
        $this->reconcile();
        $this->document->addVersion('# Two', '<h1>Two</h1>');
        $this->em->flush();
        $this->reconcile();
        $item = $this->onlyWatch()->item;

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        $current = $crawler->filter('#inbox-item-1 [data-inbox-wait="current"]');
        self::assertCount(1, $current);
        self::assertStringContainsString('Tech design in review, version 2', $current->text());
        self::assertSame('/projects/'.$this->project->id.'/documents/'.$this->document->id.'/review', $current->filter('a')->attr('href'));
        $ended = $crawler->filter('#inbox-item-1 [data-inbox-wait="ended"]');
        self::assertCount(1, $ended);
        self::assertStringContainsString('Tech design in review, version 1', $ended->text());

        self::assertCount(0, $crawler->filter('form[name="inbox_done_'.$item->id.'"]'));
        self::assertCount(0, $crawler->filter('form[name="inbox_answer_'.$item->id.'"]'));
        $dismiss = $crawler->filter('form[name="inbox_decline_'.$item->id.'"] button[type="submit"]');
        self::assertCount(1, $dismiss);
        self::assertSame('Dismiss', trim($dismiss->text()));
        self::assertCount(1, $crawler->filter('#inbox-item-1 button[type="submit"]'));
    }

    public function test_a_wait_of_a_deleted_document_shows_its_reason_with_no_link(): void
    {
        $this->reconcile();
        $this->em->remove($this->document);
        $this->em->flush();
        $this->em->clear();

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        $wait = $crawler->filter('#inbox-item-1 [data-inbox-wait]');
        self::assertCount(1, $wait);
        self::assertStringContainsString('Tech design in review, version 1', $wait->text());
        self::assertCount(0, $wait->filter('a'));
    }

    public function test_a_run_wait_links_its_reason_to_the_worker_runs_page(): void
    {
        $this->document->status = DocumentStatus::Approved;
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: Uuid::v7(),
            cardId: $this->card->id ?? throw new \LogicException('Card has no id.'),
            cardNumber: 4,
            workKind: 'implement',
            state: WorkerRunState::Blocked,
            output: 'Needs the API key',
        );
        $this->em->persist($run);
        $this->em->flush();
        $this->reconcile();

        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        self::assertResponseIsSuccessful();
        $link = $crawler->filter('#inbox-item-1 [data-inbox-wait="current"] a');
        self::assertCount(1, $link);
        self::assertSame('Run blocked: Needs the API key', trim($link->text()));
        self::assertSame('/projects/'.$this->project->id.'/worker-runs?search='.$run->id, $link->attr('href'));
        self::assertSame('_top', $link->attr('data-turbo-frame'));
    }

    public function test_dismiss_closes_the_item_declined_and_moves_it_to_completed(): void
    {
        $this->reconcile();
        $crawler = $this->client->request(Request::METHOD_GET, $this->pageUrl());

        $this->client->submit($crawler->selectButton('Dismiss')->form());

        self::assertResponseRedirects($this->pageUrl().'?queue=completed#inbox-item-1');
        $watch = $this->onlyWatch();
        self::assertSame(InboxItemState::Declined, $watch->item->state);
        self::assertNotNull($watch->dismissedAt);
        self::assertNotNull($watch->closedAt);
        $wait = $watch->waits->first();
        self::assertNotFalse($wait);
        self::assertSame(InboxCardWaitEndReason::Dismissed, $wait->endReason);

        $crawler = $this->client->followRedirect();
        self::assertCount(0, $crawler->filter('#inbox-item-1 form'));
    }

    private function reconcile(): void
    {
        $reconciler = static::getContainer()->get(CardWaitReconciler::class);
        self::assertInstanceOf(CardWaitReconciler::class, $reconciler);
        $reconciler->reconcile($this->project, [(string) $this->card->id]);
    }

    private function onlyWatch(): InboxCardWatch
    {
        $this->em->clear();
        $watches = static::getContainer()->get(InboxCardWatchRepository::class);
        self::assertInstanceOf(InboxCardWatchRepository::class, $watches);
        $found = $watches->findBy(['cardId' => $this->card->id]);
        self::assertCount(1, $found);

        return $found[0];
    }

    private function pageUrl(): string
    {
        return '/projects/'.$this->project->id.'/inbox';
    }
}
