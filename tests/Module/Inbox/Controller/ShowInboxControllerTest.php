<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Controller;

use App\Module\Inbox\Command\ShowInboxHandler;
use App\Tests\Module\Inbox\InboxScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowInboxControllerTest extends WebTestCase
{
    use InboxScenario;

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
}
