<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardAutomation;
use App\Module\Board\Entity\CardAutomationAction;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\CardAutomationRepository;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Event\PullRequestStateChanged;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;

final class RecordSyncOnPullRequestStateChangedTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private MockClock $clock;
    private Project $project;
    private ForgePullRequest $pullRequest;
    private int $cardNumber = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = new MockClock('2026-09-30 12:00:00');
        self::getContainer()->set('clock', $this->clock);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->enableBoard();
        $this->project = $this->makeProject('record-sync');
        $this->pullRequest = new ForgePullRequest($this->project, 'github', 'Acme/Widgets', 5);
        $this->em->persist($this->pullRequest);
        $this->em->flush();
    }

    public function test_a_read_that_confirms_the_sync_marks_each_open_card_once(): void
    {
        $card = $this->linkedCard();
        $this->em->persist(new CardPullRequest($card, 'https://github.com/acme/widgets/pull/5', Forge::GitHub, 'acme/widgets', 5));
        $doneCard = $this->linkedCard('done');
        $changes = $this->cardChanges();

        $this->read(from: 'approved1', to: 'synced1', syncedSha: 'synced1');

        $automation = $this->automationOf($card);
        self::assertNotNull($automation);
        self::assertSame(CardAutomationAction::Synced, $automation->lastAction);
        self::assertEquals($this->clock->now(), $automation->lastActionAt);
        $events = $this->cardEvents($card);
        self::assertCount(1, $events);
        self::assertSame(CardEventKind::Synced, $events[0]->kind);
        self::assertSame(['pullRequest' => 5], $events[0]->detail);
        self::assertContains([(string) $card->id, CardChanged::UPDATED], $changes->getArrayCopy());

        self::assertNull($this->automationOf($doneCard));
        self::assertSame([], $this->cardEvents($doneCard));
    }

    public function test_a_force_push_back_to_a_synced_head_records_nothing(): void
    {
        $card = $this->linkedCard();

        $this->read(from: 'pushed1', to: 'synced1', syncedSha: null);

        self::assertSame([], $this->cardEvents($card));
    }

    public function test_a_head_move_that_is_not_the_sync_records_nothing(): void
    {
        $card = $this->linkedCard();

        $this->read(from: 'approved1', to: 'pushed1', syncedSha: null);

        self::assertSame([], $this->cardEvents($card));
    }

    public function test_a_head_move_past_an_older_sync_records_nothing(): void
    {
        $card = $this->linkedCard();

        $this->read(from: 'synced1', to: 'pushed1', syncedSha: 'synced1');

        self::assertSame([], $this->cardEvents($card));
    }

    public function test_a_later_read_of_the_same_synced_head_records_nothing_more(): void
    {
        $card = $this->linkedCard();
        $this->read(from: 'approved1', to: 'synced1', syncedSha: 'synced1');

        $this->read(from: 'synced1', to: 'synced1', syncedSha: 'synced1');

        self::assertCount(1, $this->cardEvents($card));
    }

    public function test_nothing_records_while_the_board_is_off(): void
    {
        $card = $this->linkedCard();
        $this->disableBoard();

        $this->read(from: 'approved1', to: 'synced1', syncedSha: 'synced1');

        self::assertSame([], $this->cardEvents($card));
    }

    /** The row as apply() leaves it, and the event Forge dispatches inside the transaction of the read. */
    private function read(string $from, string $to, ?string $syncedSha): void
    {
        $this->pullRequest->headSha = $to;
        $this->pullRequest->syncedSha = $syncedSha;
        $this->em->flush();
        $event = new PullRequestStateChanged($this->pullRequest, new PullRequestSnapshot(headSha: $from), new PullRequestSnapshot(headSha: $to));

        $events = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        $this->em->wrapInTransaction(static fn (): object => $events->dispatch($event));
    }

    private function linkedCard(string $slug = 'in-progress'): Card
    {
        $card = new Card($this->project, $this->column($this->project, $slug), 'Ship it', '', ++$this->cardNumber);
        $this->em->persist($card);
        $this->em->persist(new CardPullRequest($card, 'https://github.com/Acme/Widgets/pull/5', Forge::GitHub, 'Acme/Widgets', 5));
        $this->em->flush();

        return $card;
    }

    private function automationOf(Card $card): ?CardAutomation
    {
        $automations = self::getContainer()->get(CardAutomationRepository::class);
        self::assertInstanceOf(CardAutomationRepository::class, $automations);
        $automation = $automations->findOneBy(['card' => $card]);
        if (null !== $automation) {
            $this->em->refresh($automation);
        }

        return $automation;
    }

    /** @return list<CardEvent> */
    private function cardEvents(Card $card): array
    {
        $cardEvents = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $cardEvents);

        return $cardEvents->findForCard($card);
    }

    /** @return \ArrayObject<int, array{string, string}> */
    private function cardChanges(): \ArrayObject
    {
        /** @var \ArrayObject<int, array{string, string}> $changes */
        $changes = new \ArrayObject();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(SymfonyEventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(CardChanged::class, static function (CardChanged $event) use ($changes): void {
            $changes[] = [(string) $event->cardId, $event->change];
        });

        return $changes;
    }
}
