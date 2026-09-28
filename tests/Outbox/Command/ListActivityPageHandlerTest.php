<?php

declare(strict_types=1);

namespace App\Tests\Outbox\Command;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Outbox\ActivityEntry;
use App\Outbox\ActivityFamily;
use App\Outbox\Command\ListActivityPageCommand;
use App\Outbox\Command\ListActivityPageHandler;
use App\Outbox\Entity\OutboxEvent;
use App\Outbox\View\ActivityListQuery;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ListActivityPageHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ListActivityPageHandler $handler;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $handler = self::getContainer()->get(ListActivityPageHandler::class);
        self::assertInstanceOf(ListActivityPageHandler::class, $handler);
        $this->handler = $handler;
    }

    public function test_it_pages_the_events_of_the_project(): void
    {
        $project = $this->projectWithEvents('activity-paging@example.com', 5);

        $view = ($this->handler)(new ListActivityPageCommand($project, new ActivityListQuery(page: 2), perPage: 2));

        self::assertSame(['event.2', 'event.3'], $this->types($view->entries));
        self::assertSame(5, $view->filteredTotal);
        self::assertSame(3, $view->totalPages);
        self::assertSame([1, 2, 3], $view->pageList);
        self::assertNull($view->clampedPage);
    }

    public function test_a_page_past_the_end_names_the_last_page(): void
    {
        $project = $this->projectWithEvents('activity-clamp@example.com', 3);

        $view = ($this->handler)(new ListActivityPageCommand($project, new ActivityListQuery(page: 7), perPage: 2));

        self::assertSame(2, $view->clampedPage);
    }

    public function test_an_empty_list_has_one_page_and_no_clamp(): void
    {
        $project = $this->projectWithEvents('activity-none@example.com', 0);

        $view = ($this->handler)(new ListActivityPageCommand($project, new ActivityListQuery(page: 4)));

        self::assertSame([], $view->entries);
        self::assertSame(0, $view->filteredTotal);
        self::assertSame(1, $view->totalPages);
        self::assertNull($view->clampedPage);
    }

    public function test_the_filters_narrow_the_total(): void
    {
        $project = $this->projectWithEvents('activity-narrow@example.com', 2);
        $this->em->persist(new OutboxEvent($project, 'board.card_moved', 'topic', '{}'));
        $this->em->flush();

        $view = ($this->handler)(new ListActivityPageCommand($project, new ActivityListQuery(family: ActivityFamily::Board)));

        self::assertSame(['board.card_moved'], $this->types($view->entries));
        self::assertSame(1, $view->filteredTotal);
    }

    public function test_the_page_size_is_held_inside_its_bounds(): void
    {
        $project = $this->projectWithEvents('activity-bounds@example.com', 3);

        $tooSmall = ($this->handler)(new ListActivityPageCommand($project, new ActivityListQuery(), perPage: 0));
        $tooLarge = ($this->handler)(new ListActivityPageCommand($project, new ActivityListQuery(page: \PHP_INT_MAX), perPage: 1000));

        self::assertCount(1, $tooSmall->entries);
        self::assertSame(3, $tooSmall->totalPages);
        self::assertSame(1, $tooLarge->clampedPage);
    }

    /**
     * @param list<ActivityEntry> $entries
     *
     * @return list<string>
     */
    private function types(array $entries): array
    {
        return array_map(static fn (ActivityEntry $entry): string => $entry->event->type, $entries);
    }

    /** @param non-empty-string $email */
    private function projectWithEvents(string $email, int $count): Project
    {
        $user = new User(fullName: 'U', email: $email, password: 'x');
        $this->em->persist($user);
        $project = new Project($user, 'activity-'.bin2hex(random_bytes(4)));
        $this->em->persist($project);
        for ($index = 0; $index < $count; ++$index) {
            $this->em->persist(new OutboxEvent($project, 'event.'.$index, 'topic', '{}', new \DateTimeImmutable('-'.($index + 1).' minutes')));
        }
        $this->em->flush();

        return $project;
    }
}
