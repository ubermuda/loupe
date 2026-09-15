<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Mercure\LiveUpdates;
use App\Mercure\UserTopicBuilder;
use App\Module\Inbox\Command\ShowInboxHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemState;
use App\Module\Inbox\Service\InboxSearchIndexer;
use App\Tests\Module\Inbox\InboxScenario;
use App\Tests\Support\MercureCookies;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class ShowInboxControllerTest extends WebTestCase
{
    use InboxScenario;
    use MercureCookies;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function test_the_page_answers_404_while_the_inbox_is_off(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-off');
        $project = $this->inboxProject($this->em, $owner);
        $this->setInboxFlag(false);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_sidebar_hides_the_entry_while_the_inbox_is_off(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-nav-off');
        $project = $this->inboxProject($this->em, $owner);
        $this->setInboxFlag(false);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/projects/'.$project->id.'/inbox"]');
    }

    public function test_a_stranger_is_refused(): void
    {
        $project = $this->inboxProject($this->em, $this->signedUpUser($this->em, 'inbox-owner'));
        $this->setInboxFlag(true);

        $this->client->loginUser($this->signedUpUser($this->em, 'inbox-stranger'));
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_an_empty_inbox_says_so(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-empty');
        $project = $this->inboxProject($this->em, $owner);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lp-empty-state', 'No agent has asked you anything yet.');
        self::assertSelectorNotExists('[data-inbox-open-count]');
        // The frame stays, so a live reload has somewhere to put a count.
        self::assertSelectorExists('a[data-controller="inbox-pill"] turbo-frame#inbox-open-count-'.$project->id);
    }

    public function test_the_pill_listens_on_the_owner_inbox_topic(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-pill-topic');
        $project = $this->inboxProject($this->em, $owner);
        $this->question($this->em, $project, 1);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');

        self::assertResponseIsSuccessful();
        $topics = static::getContainer()->get(UserTopicBuilder::class);
        self::assertInstanceOf(UserTopicBuilder::class, $topics);
        $inboxTopic = $topics->forInbox($owner->id ?? throw new \LogicException('The owner has no id.'));
        self::assertSame([$inboxTopic], self::subscribedTopics($this->client->getResponse()));
        self::assertSame([$inboxTopic], $crawler->filter('form#mercure-subscriptions input[data-mercure-topic]')->each(static fn ($input): ?string => $input->attr('value')));

        $link = $crawler->filter('a[data-controller="inbox-pill"]');
        self::assertCount(1, $link);
        self::assertSame((string) $project->id, $link->attr('data-inbox-pill-project-value'));
        self::assertSame('/projects/'.$project->id.'/inbox/open-count', $link->attr('data-inbox-pill-url-value'));
        self::assertSame('1', $link->filter('turbo-frame[data-inbox-pill-target="frame"] [data-inbox-open-count]')->text());
    }

    public function test_the_open_count_frame_renders_the_pill_alone(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-pill-frame');
        $project = $this->inboxProject($this->em, $owner);
        $this->question($this->em, $project, 1);
        $this->question($this->em, $project, 2);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox/open-count');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertCount(1, $crawler->filter('turbo-frame#inbox-open-count-'.$project->id));
        self::assertSelectorTextSame('[data-inbox-open-count]', '2');
        self::assertSelectorNotExists('nav');
    }

    public function test_the_open_count_frame_is_refused_to_a_stranger_and_absent_while_the_inbox_is_off(): void
    {
        $project = $this->inboxProject($this->em, $this->signedUpUser($this->em, 'inbox-pill-frame-owner'));
        $this->setInboxFlag(true);

        $this->client->loginUser($this->signedUpUser($this->em, 'inbox-pill-frame-stranger'));
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox/open-count');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($project->owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox/open-count');
        self::assertResponseIsSuccessful();

        $this->setInboxFlag(false);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox/open-count');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_with_live_updates_off_the_pill_renders_its_count_and_nothing_subscribes(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-pill-no-live');
        $project = $this->inboxProject($this->em, $owner);
        $this->question($this->em, $project, 1);
        $this->question($this->em, $project, 2);
        $this->setInboxFlag(true);
        $flags = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[LiveUpdates::FLAG]->value = false;
        $this->em->flush();

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('a[data-controller="inbox-pill"] [data-inbox-open-count]', '2');
        self::assertNull(self::subscribedTopics($this->client->getResponse()));
        self::assertSelectorNotExists('form#mercure-subscriptions');
    }

    public function test_no_topic_is_granted_while_the_inbox_is_off(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-pill-off');
        $project = $this->inboxProject($this->em, $owner);
        $this->setInboxFlag(false);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');

        self::assertResponseIsSuccessful();
        self::assertNull(self::subscribedTopics($this->client->getResponse()));
    }

    public function test_an_ask_shows_its_session_its_sanitized_context_and_its_numbered_items(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-render');
        $project = $this->inboxProject($this->em, $owner);
        $question = $this->question($this->em, $project, 12, ['JSON', 'CSV'], freeText: true, title: 'Which format?');
        $todo = $this->todo($this->em, $project, 13);
        $ask = $this->askHolding($this->em, $project, [$question, $todo], context: "Working on **the export**.\n\n<script>alert('x')</script>");
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        self::assertResponseIsSuccessful();
        $block = $crawler->filter('[data-inbox-ask-id="'.$ask->id.'"]');
        self::assertCount(1, $block);
        self::assertStringContainsString((string) $ask->sessionId, $block->filter('.lp-inbox-ask__session')->text());
        self::assertSame('the export', $block->filter('.lp-inbox-ask__context strong')->text());
        self::assertCount(0, $block->filter('script'));
        self::assertSame('item 12', $block->filter('#inbox-item-12 .lp-inbox-item__number')->text());
        self::assertSame('item 13', $block->filter('#inbox-item-13 .lp-inbox-item__number')->text());
        self::assertCount(2, $block->filter('#inbox-item-12 input[data-inbox-answer-target="option"]'));
        self::assertCount(1, $block->filter('#inbox-item-12 textarea[name="inbox_answer_'.$question->id.'[answerText]"]'));
        self::assertCount(1, $block->filter('#inbox-item-13 form[name="inbox_done_'.$todo->id.'"]'));
        self::assertSelectorTextSame('[data-inbox-open-count]', '2');
        self::assertSelectorTextSame('a[href="/projects/'.$project->id.'/inbox"] .sr-only', '2 open items');
    }

    public function test_open_asks_come_oldest_first_then_loose_items_then_closed_asks(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-order');
        $project = $this->inboxProject($this->em, $owner);
        $newer = $this->askHolding($this->em, $project, [$this->question($this->em, $project, 1)], createdAt: new \DateTimeImmutable('-1 hour'));
        $older = $this->askHolding($this->em, $project, [$this->question($this->em, $project, 2)], createdAt: new \DateTimeImmutable('-2 hours'));
        $leftOpen = $this->todo($this->em, $project, 3);
        $closed = $this->askHolding($this->em, $project, [$leftOpen], closedAt: new \DateTimeImmutable('-3 hours'));
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        self::assertSame(
            ['open-asks', 'loose-items', 'closed-asks'],
            $crawler->filter('[data-inbox-section]')->each(static fn ($node): string => (string) $node->attr('data-inbox-section')),
        );
        self::assertSame(
            [(string) $older->id, (string) $newer->id],
            $crawler->filter('[data-inbox-section="open-asks"] [data-inbox-ask-id]')->each(static fn ($node): string => (string) $node->attr('data-inbox-ask-id')),
        );
        // The to-do still open in a closed ask gets its forms once, on its own.
        self::assertCount(1, $crawler->filter('[data-inbox-section="loose-items"] #inbox-item-3 form[name="inbox_done_'.$leftOpen->id.'"]'));
        $inClosed = $crawler->filter('[data-inbox-ask-id="'.$closed->id.'"] [data-inbox-item="3"]');
        self::assertCount(0, $inClosed->filter('form'));
        self::assertCount(1, $inClosed->filter('a[href="#inbox-item-3"]'));
    }

    public function test_the_page_says_which_answers_are_still_editable(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-editable');
        $project = $this->inboxProject($this->em, $owner);
        $editable = $this->answered($this->em, $this->question($this->em, $project, 1));
        $final = $this->answered($this->em, $this->question($this->em, $project, 2));
        $this->askHolding($this->em, $project, [$editable]);
        $this->askHolding($this->em, $project, [$final], closedAt: new \DateTimeImmutable());
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        self::assertSame('yes', $crawler->filter('#inbox-item-1 [data-inbox-editable]')->attr('data-inbox-editable'));
        self::assertCount(1, $crawler->filter('#inbox-item-1 form[name="inbox_answer_'.$editable->id.'"]'));
        self::assertSame('no', $crawler->filter('#inbox-item-2 [data-inbox-editable]')->attr('data-inbox-editable'));
        self::assertStringContainsString('This response is final', $crawler->filter('#inbox-item-2')->text());
        self::assertCount(0, $crawler->filter('#inbox-item-2 form'));
    }

    public function test_an_answer_of_zero_still_shows(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-zero');
        $project = $this->inboxProject($this->em, $owner);
        $item = $this->question($this->em, $project, 1, [], freeText: true);
        $item->state = InboxItemState::Answered;
        $item->answerText = '0';
        $this->em->flush();
        $this->askHolding($this->em, $project, [$item]);
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');

        self::assertSame('0', $crawler->filter('#inbox-item-1 .lp-inbox-item__answer-text')->text());
    }

    public function test_closed_asks_page(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-paging');
        $project = $this->inboxProject($this->em, $owner);
        for ($number = 1; $number <= ShowInboxHandler::CLOSED_ASKS_PER_PAGE + 1; ++$number) {
            $item = $this->answered($this->em, $this->question($this->em, $project, $number));
            $this->askHolding($this->em, $project, [$item], closedAt: new \DateTimeImmutable('-'.$number.' minutes'));
        }
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $first = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox');
        self::assertCount(ShowInboxHandler::CLOSED_ASKS_PER_PAGE, $first->filter('[data-inbox-section="closed-asks"] [data-inbox-ask-id]'));
        self::assertCount(1, $first->filter('[data-inbox-item="1"]'));

        $second = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?page=2');
        self::assertCount(1, $second->filter('[data-inbox-section="closed-asks"] [data-inbox-ask-id]'));
        self::assertCount(1, $second->filter('[data-inbox-item="'.(ShowInboxHandler::CLOSED_ASKS_PER_PAGE + 1).'"]'));
    }

    public function test_a_search_lists_the_matching_items_closed_ones_included(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-search');
        $project = $this->inboxProject($this->em, $owner);
        $open = $this->indexed($this->question($this->em, $project, 1, title: 'Which export format?'));
        $closed = $this->indexed($this->answered($this->em, $this->question($this->em, $project, 2, title: 'Export the archive too?')));
        $this->askHolding($this->em, $project, [$closed], closedAt: new \DateTimeImmutable('-1 hour'));
        $this->indexed($this->todo($this->em, $project, 3, title: 'Review pull request 482'));
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?q=export');

        self::assertResponseIsSuccessful();
        self::assertSame('export', $crawler->filter('input[name="q"]')->attr('value'));
        $results = $crawler->filter('[data-inbox-section="search-results"]');
        self::assertCount(1, $results);
        self::assertCount(2, $results->filter('[data-inbox-item]'));
        self::assertGreaterThan(0, $results->filter('[data-inbox-item="'.$open->number.'"] form')->count());
        self::assertCount(0, $results->filter('[data-inbox-item="'.$closed->number.'"] form'));
        self::assertSame('no', $results->filter('[data-inbox-item="'.$closed->number.'"] [data-inbox-editable]')->attr('data-inbox-editable'));
        self::assertCount(0, $crawler->filter('[data-inbox-item="3"]'));
        self::assertCount(0, $crawler->filter('[data-inbox-section="open-asks"], [data-inbox-section="closed-asks"]'));
    }

    public function test_a_search_with_no_match_says_so_and_a_blank_one_shows_the_inbox(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-search-none');
        $project = $this->inboxProject($this->em, $owner);
        $this->indexed($this->question($this->em, $project, 1, title: 'Which export format?'));
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?q=invoice');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-inbox-search-empty]');
        self::assertSelectorNotExists('[data-inbox-item]');

        $crawler = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?q=%20%20');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-inbox-section="search-results"]'));
        self::assertCount(1, $crawler->filter('[data-inbox-item="1"]'));
    }

    public function test_search_results_page_and_keep_the_query_in_the_page_links(): void
    {
        $owner = $this->signedUpUser($this->em, 'inbox-search-paging');
        $project = $this->inboxProject($this->em, $owner);
        for ($number = 1; $number <= ShowInboxHandler::SEARCH_RESULTS_PER_PAGE + 1; ++$number) {
            $this->indexed($this->question($this->em, $project, $number, title: 'Rename column '.$number));
        }
        $this->setInboxFlag(true);

        $this->client->loginUser($owner);
        $first = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?q=column');
        self::assertCount(ShowInboxHandler::SEARCH_RESULTS_PER_PAGE, $first->filter('[data-inbox-section="search-results"] [data-inbox-item]'));
        self::assertStringContainsString('q=column', (string) $first->filter('.lp-pagination a[href*="page=2"]')->attr('href'));

        $second = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?q=column&page=2');
        self::assertCount(1, $second->filter('[data-inbox-section="search-results"] [data-inbox-item]'));
        self::assertStringContainsString('q=column', (string) $second->filter('[data-inbox-item] form')->attr('action'));

        // A page past the end shows the last page, not an empty list.
        $pastTheEnd = $this->client->request(Request::METHOD_GET, '/projects/'.$project->id.'/inbox?q=column&page=99');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $pastTheEnd->filter('[data-inbox-section="search-results"] [data-inbox-item]'));
        self::assertSelectorTextSame('.lp-pagination [aria-current="page"]', '2');
        self::assertSelectorNotExists('[data-inbox-search-empty]');
    }

    private function indexed(InboxItem $item): InboxItem
    {
        $indexer = static::getContainer()->get(InboxSearchIndexer::class);
        self::assertInstanceOf(InboxSearchIndexer::class, $indexer);
        $indexer->index($item);

        return $item;
    }
}
