<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Command;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\ListCardEventsCommand;
use App\Module\Board\Command\ListCardEventsHandler;
use App\Module\Board\Command\ListCardsHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Board\BoardColumnFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ListCardEventsHandlerTest extends KernelTestCase
{
    use BoardColumnFixtures;

    private EntityManagerInterface $em;
    private ListCardEventsHandler $handler;
    private CardEventRepository $events;
    private Card $card;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $events = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);
        $this->events = $events;
        $this->handler = new ListCardEventsHandler($events);

        $owner = new User(fullName: 'Riley', email: 'events-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, 'events-'.uniqid());
        $this->em->persist($project);
        $this->seedColumns($project);
        $this->card = new Card($project, $this->column($project, 'backlog'), 'History', '', 1);
        $this->em->persist($this->card);
        $this->em->flush();
    }

    public function test_events_read_newest_first_and_an_equal_time_falls_back_to_the_id(): void
    {
        $oldest = $this->record('2026-09-01T10:00:00+00:00');
        $tiedA = $this->record('2026-09-02T10:00:00+00:00');
        $tiedB = $this->record('2026-09-02T10:00:00+00:00');
        $newest = $this->record('2026-09-03T10:00:00+00:00');
        $this->em->flush();
        $this->em->clear();

        $tied = [(string) $tiedA->id, (string) $tiedB->id];
        rsort($tied);

        $view = ($this->handler)(new ListCardEventsCommand($this->card));

        self::assertSame(
            [(string) $newest->id, ...$tied, (string) $oldest->id],
            array_map(static fn (CardEvent $event): string => (string) $event->id, $view->events),
        );
        self::assertSame(1, $view->page);
        self::assertSame(ListCardsHandler::DEFAULT_PER_PAGE, $view->perPage);
        self::assertSame(4, $view->total);
        self::assertFalse($view->hasMore);
    }

    public function test_a_page_reads_its_slice_and_says_whether_more_follow(): void
    {
        foreach (range(1, 5) as $day) {
            $this->record(\sprintf('2026-09-%02dT10:00:00+00:00', $day));
        }
        $this->em->flush();

        $first = ($this->handler)(new ListCardEventsCommand($this->card, page: 1, perPage: 2));
        $last = ($this->handler)(new ListCardEventsCommand($this->card, page: 3, perPage: 2));

        self::assertSame(['2026-09-05', '2026-09-04'], $this->days($first->events));
        self::assertTrue($first->hasMore);
        self::assertSame(['2026-09-01'], $this->days($last->events));
        self::assertFalse($last->hasMore);
        self::assertSame(5, $last->total);
    }

    public function test_the_paging_is_clamped_into_range(): void
    {
        $this->record('2026-09-01T10:00:00+00:00');
        $this->em->flush();

        $low = ($this->handler)(new ListCardEventsCommand($this->card, page: 0, perPage: 0));
        $high = ($this->handler)(new ListCardEventsCommand($this->card, page: -4, perPage: 1000));

        self::assertSame([1, 1], [$low->page, $low->perPage]);
        self::assertCount(1, $low->events);
        self::assertSame([1, ListCardsHandler::MAX_PER_PAGE], [$high->page, $high->perPage]);
    }

    public function test_a_page_past_the_end_reads_empty(): void
    {
        $this->record('2026-09-01T10:00:00+00:00');
        $this->em->flush();

        foreach ([2, \PHP_INT_MAX] as $page) {
            $view = ($this->handler)(new ListCardEventsCommand($this->card, page: $page));

            self::assertSame([], $view->events);
            self::assertSame($page, $view->page);
            self::assertSame(1, $view->total);
            self::assertFalse($view->hasMore);
        }
    }

    public function test_a_card_reads_its_own_events_only(): void
    {
        $other = new Card($this->card->project, $this->card->column, 'Other', '', 2);
        $this->em->persist($other);
        $this->events->record($other, CardEventKind::Created, Actor::Agent, null, []);
        $mine = $this->record('2026-09-01T10:00:00+00:00');
        $this->em->flush();

        $view = ($this->handler)(new ListCardEventsCommand($this->card));

        self::assertSame([(string) $mine->id], array_map(static fn (CardEvent $event): string => (string) $event->id, $view->events));
        self::assertSame(1, $view->total);
    }

    private function record(string $at): CardEvent
    {
        return $this->events->record($this->card, CardEventKind::Created, Actor::Agent, null, [], new \DateTimeImmutable($at));
    }

    /**
     * @param list<CardEvent> $events
     *
     * @return list<string>
     */
    private function days(array $events): array
    {
        return array_map(static fn (CardEvent $event): string => $event->occurredAt->format('Y-m-d'), $events);
    }
}
