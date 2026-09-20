<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemCard;
use App\Module\Inbox\Entity\InboxItemDocument;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class LinkedInboxSectionTest extends WebTestCase
{
    use BoardColumnFixtures;
    use InboxScenario;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private Project $project;
    private Card $card;
    private Document $document;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->owner = $this->signedUpUser($em, 'inbox-linked');
        $this->project = $this->inboxProject($em, $this->owner);
        $this->seedColumns($this->project);
        $this->card = new Card(project: $this->project, column: $this->column($this->project, 'backlog'), title: 'Ship the export', body: 'Body', number: 1);
        $em->persist($this->card);
        $this->document = new Document(owner: $this->owner, project: $this->project, title: 'The export design');
        $this->document->addVersion('# Export', '<h1>Export</h1>');
        $em->persist($this->document);
        $em->flush();

        $this->setInboxFlag(true);
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = true;
        $em->flush();

        $this->client->loginUser($this->owner);
    }

    #[TestWith([null])]
    #[TestWith(['card-drawer-frame'])]
    public function test_the_card_page_lists_open_items_first_with_their_ask_context(?string $frame): void
    {
        $closed = $this->answered($this->em, $this->question($this->em, $this->project, 1, title: 'Which column?'));
        $open = $this->todo($this->em, $this->project, 2, title: 'Review pull request 482');
        $this->askHolding($this->em, $this->project, [$closed, $open], context: 'Working on the **export** card.');
        $this->linkCard($closed);
        $this->linkCard($open);

        $crawler = $this->client->request(Request::METHOD_GET, $this->cardUrl(), server: null === $frame ? [] : ['HTTP_TURBO_FRAME' => $frame]);

        self::assertResponseIsSuccessful();
        self::assertContains('Turbo-Frame', $this->client->getResponse()->getVary());
        $section = $crawler->filter('[data-inbox-linked="card"]');
        self::assertCount(1, $section);
        self::assertSame(['2', '1'], $section->filter('[data-inbox-item]')->each(static fn (Crawler $node): string => (string) $node->attr('data-inbox-item')));
        self::assertCount(1, $section->filter('[data-inbox-linked-open] [data-inbox-item="2"]'));
        self::assertCount(1, $section->filter('[data-inbox-linked-closed] [data-inbox-item="1"]'));
        self::assertStringContainsString('export', $section->filter('#inbox-item-2')->closest('[data-inbox-linked-entry]')?->filter('[data-inbox-linked-context]')->text() ?? '');
        // The forms post back with the card as the page to return to.
        $action = (string) $section->filter('form[name="inbox_done_'.$open->id.'"]')->attr('action');
        self::assertStringContainsString('returnTo=card', $action);
        self::assertStringContainsString('returnId='.$this->card->id, $action);
        self::assertSame($frame ?? '_top', $section->filter('form[name="inbox_done_'.$open->id.'"]')->attr('data-turbo-frame'));
    }

    public function test_the_document_page_lists_its_linked_items(): void
    {
        $item = $this->question($this->em, $this->project, 3);
        $this->askHolding($this->em, $this->project, [$item]);
        $this->linkDocument($item);

        $crawler = $this->client->request(Request::METHOD_GET, $this->documentUrl());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-inbox-linked="document"] #inbox-item-3'));
        $action = (string) $crawler->filter('form[name="inbox_answer_'.$item->id.'"]')->attr('action');
        self::assertStringContainsString('returnTo=document', $action);
    }

    public function test_the_sections_render_nothing_while_the_inbox_is_off(): void
    {
        $item = $this->question($this->em, $this->project, 1);
        $this->linkCard($item);
        $this->linkDocument($item);
        $this->setInboxFlag(false);

        $this->client->request(Request::METHOD_GET, $this->cardUrl());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-card-docs');
        self::assertSelectorNotExists('[data-inbox-linked]');

        $this->client->request(Request::METHOD_GET, $this->documentUrl());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-review-doc');
        self::assertSelectorNotExists('[data-inbox-linked]');
    }

    public function test_inbox_markdown_above_a_document_takes_no_heading_id_of_the_document(): void
    {
        $this->document->addVersion('## Export', '<h2 id="heading-export">Export</h2>');
        $this->em->flush();
        $item = $this->question($this->em, $this->project, 1);
        $item->body = "## Export\n\nWhich one?";
        $this->askHolding($this->em, $this->project, [$item], context: "## Export\n\nBefore the migration.");
        $this->linkDocument($item);

        $crawler = $this->client->request(Request::METHOD_GET, $this->documentUrl());

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-inbox-linked] h2')->reduce(static fn (Crawler $heading): bool => 'Export' === trim($heading->text())));
        self::assertCount(1, $crawler->filter('[id="heading-export"]'));
        self::assertCount(1, $crawler->filter('.lp-review-doc [id="heading-export"]'));
    }

    #[TestWith([null, '3'])]
    #[TestWith([1, '1'])]
    #[TestWith([12, '3'])]
    #[TestWith([13, '3'])]
    #[TestWith([14, '3'])]
    #[TestWith([99, '3'])]
    public function test_the_section_shows_ten_closed_items_including_the_link_target(?int $focusedNumber, string $lastNumber): void
    {
        $items = [];
        for ($number = 1; $number <= 12; ++$number) {
            $item = $this->answered($this->em, $this->question($this->em, $this->project, $number));
            $item->closedAt = new \DateTimeImmutable('-'.(20 - $number).' minutes');
            $this->em->flush();
            $this->linkCard($item);
            $items[] = $item;
        }
        // Every item enters the inbox through an ask, which is how the inbox page reaches it.
        $this->askHolding($this->em, $this->project, $items, closedAt: new \DateTimeImmutable('-1 minute'));
        $this->answered($this->em, $this->question($this->em, $this->project, 13, title: 'Unlinked question'));
        $other = $this->inboxProject($this->em, $this->owner);
        $this->linkCard($this->answered($this->em, $this->question($this->em, $other, 14, title: 'Foreign question')));

        $crawler = $this->client->request(Request::METHOD_GET, $this->cardUrl(), null === $focusedNumber ? [] : ['inboxItem' => $focusedNumber]);

        self::assertResponseIsSuccessful();
        $shown = $crawler->filter('[data-inbox-linked-closed] [data-inbox-item]')->each(static fn (Crawler $node): string => (string) $node->attr('data-inbox-item'));
        self::assertSame(['12', '11', '10', '9', '8', '7', '6', '5', '4', $lastNumber], $shown);
        $more = $crawler->filter('[data-inbox-linked-more]');
        self::assertCount(1, $more);

        $inbox = $this->client->click($more->link());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $inbox->filter('[data-inbox-item="1"]'));
        self::assertCount(1, $inbox->filter('[data-inbox-item="2"]'));

        $link = $inbox->filter('#inbox-item-1 a[aria-label="Open Ship the export conversation"]');
        self::assertCount(1, $link);
        self::assertSame($this->cardUrl().'?tab=conversation&inboxItem=1#inbox-item-1', $link->attr('href'));
        $destination = $this->client->click($link->link());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $destination->filter('[data-inbox-linked-closed] #inbox-item-1'));
    }

    public function test_the_section_links_to_no_more_closed_items_when_all_of_them_show(): void
    {
        $item = $this->answered($this->em, $this->question($this->em, $this->project, 1));
        $this->linkCard($item);

        $this->client->request(Request::METHOD_GET, $this->cardUrl());

        self::assertSelectorExists('[data-inbox-linked-closed] [data-inbox-item="1"]');
        self::assertSelectorNotExists('[data-inbox-linked-more]');
    }

    public function test_the_sections_render_nothing_without_a_linked_item(): void
    {
        // An item of the project that links elsewhere, so the empty result is about the link.
        $this->question($this->em, $this->project, 1);

        $this->client->request(Request::METHOD_GET, $this->cardUrl());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-card-docs');
        self::assertSelectorNotExists('[data-inbox-linked]');

        $this->client->request(Request::METHOD_GET, $this->documentUrl());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.lp-review-doc');
        self::assertSelectorNotExists('[data-inbox-linked]');
    }

    public function test_an_item_of_another_project_linked_to_the_card_is_not_shown(): void
    {
        $other = $this->inboxProject($this->em, $this->owner);
        $foreign = $this->question($this->em, $other, 1, title: 'A foreign question');
        $this->linkCard($foreign);
        $own = $this->question($this->em, $this->project, 1);
        $this->linkCard($own);

        $crawler = $this->client->request(Request::METHOD_GET, $this->cardUrl());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-inbox-linked] [data-inbox-item]'));
        self::assertStringNotContainsString('A foreign question', $crawler->filter('[data-inbox-linked]')->text());
    }

    public function test_the_items_and_their_asks_load_in_one_query(): void
    {
        for ($number = 1; $number <= 3; ++$number) {
            $item = $this->question($this->em, $this->project, $number);
            $this->askHolding($this->em, $this->project, [$item], context: 'Ask '.$number);
            $this->askHolding($this->em, $this->project, [$item], closedAt: new \DateTimeImmutable(), context: 'Earlier ask '.$number);
            $this->linkCard($item);
        }
        $this->em->clear();

        $this->client->enableProfiler();
        $this->client->request(Request::METHOD_GET, $this->cardUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(3, '[data-inbox-linked] [data-inbox-item]');
        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        // The holder also kept the fixture inserts, so only reads count. The
        // sidebar pill adds one count of open items beside the section's query.
        $inboxReads = array_values(array_map(
            static fn (array $query): string => (string) $query['sql'],
            array_filter(
                array_merge(...array_values($collector->getQueries())),
                static fn (array $query): bool => str_starts_with((string) $query['sql'], 'SELECT') && str_contains((string) $query['sql'], 'inbox_'),
            ),
        ));
        self::assertCount(2, $inboxReads, implode("\n", $inboxReads));
        self::assertCount(1, array_filter($inboxReads, static fn (string $sql): bool => str_contains($sql, 'COUNT(')));
    }

    public function test_an_answer_from_the_card_page_returns_to_the_card_page(): void
    {
        $item = $this->question($this->em, $this->project, 4);
        $this->linkCard($item);

        $this->post($item, 'answer', ['selectedOptions' => '1'], $this->cardQuery());

        self::assertResponseRedirects($this->cardUrl().'?tab=conversation');
        self::assertSame(InboxItemState::Answered, $this->reload($item)->state);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.lp-flash', 'Item 4 is answered.');
    }

    public function test_a_refused_answer_from_the_card_page_shows_its_refusal_there(): void
    {
        $item = $this->question($this->em, $this->project, 1);
        $this->linkCard($item);

        $crawler = $this->post($item, 'answer', ['selectedOptions' => '0,1'], $this->cardQuery());

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $crawler->filter('.lp-card-docs'));
        self::assertSelectorTextContains('[data-inbox-linked="card"] #inbox-item-1', 'This question takes one option only.');
        self::assertSame(InboxItemState::Open, $this->reload($item)->state);
    }

    public function test_a_refused_form_leaves_the_other_items_forms_clean(): void
    {
        $refused = $this->question($this->em, $this->project, 1);
        $other = $this->question($this->em, $this->project, 2, freeText: true);
        $this->linkCard($refused);
        $this->linkCard($other);

        $crawler = $this->post($refused, 'answer', ['selectedOptions' => '0,1', 'answerText' => ''], $this->cardQuery());

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#inbox-item-1', 'This question takes one option only.');
        $otherItem = $crawler->filter('#inbox-item-2');
        self::assertCount(1, $otherItem->filter('form[name="inbox_answer_'.$other->id.'"]'));
        self::assertSame('', trim(implode('', $otherItem->filter('.lp-field-errors:not([hidden])')->each(static fn (Crawler $node): string => $node->text()))));
        self::assertCount(0, $otherItem->filter('[data-inbox-refusal]'));
        self::assertSame('', (string) $otherItem->filter('input[name="inbox_answer_'.$other->id.'[selectedOptions]"]')->attr('value'));
    }

    public function test_a_response_naming_a_version_the_document_lacks_returns_to_the_current_version(): void
    {
        $item = $this->question($this->em, $this->project, 5);
        $this->linkDocument($item);

        $this->post($item, 'answer', ['selectedOptions' => '0'], ['returnTo' => 'document', 'returnId' => (string) $this->document->id, 'returnVersion' => '99']);

        self::assertResponseRedirects($this->documentUrl());
        self::assertSame(InboxItemState::Answered, $this->reload($item)->state);
    }

    public function test_a_refused_change_to_a_final_answer_shows_on_the_card_page(): void
    {
        $item = $this->answered($this->em, $this->question($this->em, $this->project, 1));
        $this->askHolding($this->em, $this->project, [$item], closedAt: new \DateTimeImmutable());
        $this->linkCard($item);

        $this->post($item, 'answer', ['selectedOptions' => '1'], $this->cardQuery());

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-inbox-linked="card"] [data-inbox-linked-closed] #inbox-item-1 [data-inbox-refusal]', 'This response is final');
        self::assertSame([0], $this->reload($item)->selectedOptions);
    }

    public function test_a_decline_from_the_document_page_returns_to_the_document_page(): void
    {
        $item = $this->todo($this->em, $this->project, 2);
        $this->linkDocument($item);

        $this->post($item, 'decline', ['closeNote' => 'Not mine.'], ['returnTo' => 'document', 'returnId' => (string) $this->document->id]);

        self::assertResponseRedirects($this->documentUrl());
        self::assertSame(InboxItemState::Declined, $this->reload($item)->state);
    }

    public function test_a_refused_done_from_the_document_page_shows_its_refusal_there(): void
    {
        $item = $this->answered($this->em, $this->todo($this->em, $this->project, 2), InboxItemState::Withdrawn);
        $this->linkDocument($item);

        $this->post($item, 'done', [], ['returnTo' => 'document', 'returnId' => (string) $this->document->id]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.lp-review-doc');
        self::assertSelectorTextContains('[data-inbox-linked="document"] #inbox-item-2 [data-inbox-refusal]', 'The agent closed this item');
    }

    public function test_a_response_from_an_older_document_version_stays_on_that_version(): void
    {
        $this->document->addVersion('# Export v2', '<h1>Export v2</h1>');
        $this->em->flush();
        $item = $this->question($this->em, $this->project, 5);
        $todo = $this->answered($this->em, $this->todo($this->em, $this->project, 6), InboxItemState::Withdrawn);
        $this->linkDocument($item);
        $this->linkDocument($todo);
        $versionUrl = $this->documentUrl().'/versions/1';

        $crawler = $this->client->request(Request::METHOD_GET, $versionUrl);
        $action = (string) $crawler->filter('form[name="inbox_answer_'.$item->id.'"]')->attr('action');
        self::assertStringContainsString('returnVersion=1', $action);

        $query = ['returnTo' => 'document', 'returnId' => (string) $this->document->id, 'returnVersion' => '1'];
        $this->post($todo, 'done', [], $query);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.lp-version-banner');
        self::assertSelectorTextContains('[data-inbox-linked="document"] #inbox-item-6 [data-inbox-refusal]', 'The agent closed this item');

        $this->post($item, 'answer', ['selectedOptions' => '0'], $query);
        self::assertResponseRedirects($versionUrl);
    }

    public function test_a_search_query_returns_to_the_inbox_search_and_never_rides_to_a_card_page(): void
    {
        $fromCard = $this->todo($this->em, $this->project, 2);
        $this->linkCard($fromCard);
        $fromInbox = $this->todo($this->em, $this->project, 3);

        $this->post($fromCard, 'done', [], [...$this->cardQuery(), 'q' => 'export']);
        self::assertResponseRedirects($this->cardUrl().'?tab=conversation');

        $this->post($fromInbox, 'done', [], ['returnTo' => 'card', 'returnId' => (string) $this->card->id, 'q' => '  export  ']);
        self::assertResponseRedirects('/projects/'.$this->project->id.'/inbox?q=export#inbox-item-3');
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function untrustedReturns(): iterable
    {
        yield 'an unknown page' => [['returnTo' => 'https://evil.example', 'returnId' => '']];
        yield 'an id that is no uuid' => [['returnTo' => 'card', 'returnId' => '../../admin']];
        yield 'a card the item does not link' => [['returnTo' => 'card', 'returnId' => 'unlinked']];
        yield 'a document named as a card' => [['returnTo' => 'card', 'returnId' => 'document']];
    }

    /** @param array<string, string> $query */
    #[\PHPUnit\Framework\Attributes\DataProvider('untrustedReturns')]
    public function test_an_untrusted_return_falls_back_to_the_inbox_page(array $query): void
    {
        $item = $this->todo($this->em, $this->project, 2);
        $this->linkDocument($item);
        $unlinked = new Card(project: $this->project, column: $this->column($this->project, 'backlog'), title: 'Another card', body: 'Body', number: 2);
        $this->em->persist($unlinked);
        $this->em->flush();
        $query['returnId'] = match ($query['returnId']) {
            'unlinked' => (string) $unlinked->id,
            'document' => (string) $this->document->id,
            default => $query['returnId'],
        };

        $this->post($item, 'done', [], $query);

        self::assertResponseRedirects('/projects/'.$this->project->id.'/inbox');
    }

    private function linkCard(InboxItem $item): void
    {
        $item->cards->add(new InboxItemCard($item, $this->card));
        $this->em->flush();
    }

    private function linkDocument(InboxItem $item): void
    {
        $item->documents->add(new InboxItemDocument($item, $this->document));
        $this->em->flush();
    }

    /** @return array<string, string> */
    private function cardQuery(): array
    {
        return ['returnTo' => 'card', 'returnId' => (string) $this->card->id];
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $query
     */
    private function post(InboxItem $item, string $action, array $fields, array $query): Crawler
    {
        $prefix = ['answer' => 'inbox_answer_', 'done' => 'inbox_done_', 'decline' => 'inbox_decline_'][$action];
        $url = '/projects/'.$this->project->id.'/inbox/items/'.$item->id.'/'.$action.'?'.http_build_query($query);

        // 'csrf-token' is the SameOriginCsrfTokenManager sentinel, which a same-origin Referer lets stand in for a signed token.
        return $this->client->request(Request::METHOD_POST, $url, [$prefix.$item->id => [...$fields, '_token' => 'csrf-token']], [], ['HTTP_REFERER' => 'http://localhost'.$url]);
    }

    private function cardUrl(): string
    {
        return '/projects/'.$this->project->id.'/board/cards/'.$this->card->id;
    }

    private function documentUrl(): string
    {
        return '/projects/'.$this->project->id.'/documents/'.$this->document->id.'/review';
    }

    private function reload(InboxItem $item): InboxItem
    {
        $this->em->clear();
        $stored = $this->em->find(InboxItem::class, $item->id);
        self::assertInstanceOf(InboxItem::class, $stored);

        return $stored;
    }
}
