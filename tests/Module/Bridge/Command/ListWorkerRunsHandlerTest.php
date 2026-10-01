<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\ListWorkerRunsCommand;
use App\Module\Bridge\Command\ListWorkerRunsHandler;
use App\Module\Bridge\Command\ListWorkerRunsView;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerRunStateChangeRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\CardTitleSourceInterface;
use App\Module\Bridge\View\WorkerRunAction;
use App\Module\Bridge\View\WorkerRunControls;
use App\Module\Bridge\View\WorkerRunListItem;
use App\Module\Bridge\View\WorkerRunListQuery;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ListWorkerRunsHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    /** A run whose card is gone from the board shows its number alone. */
    public function test_each_item_carries_its_card_title_and_a_gone_card_none(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'titles-'.uniqid().'@example.com'), 'Titles');
        $kept = Uuid::v7();
        $gone = Uuid::v7();
        $this->seedRun($em, $project, cardNumber: 1, cardId: $kept);
        $this->seedRun($em, $project, cardNumber: 1, cardId: $kept);
        $this->seedRun($em, $project, cardNumber: 2, cardId: $gone);

        $asked = [];
        $source = new readonly class($kept, static function (array $cardIds) use (&$asked): void {
            $asked[] = $cardIds;
        }) implements CardTitleSourceInterface {
            /** @param \Closure(list<Uuid>): void $record */
            public function __construct(
                private Uuid $kept,
                private \Closure $record,
            ) {
            }

            #[\Override]
            public function titlesFor(Project $project, array $cardIds): array
            {
                ($this->record)($cardIds);

                return [(string) $this->kept => 'Fix the login'];
            }
        };
        $runs = self::getContainer()->get(WorkerRunRepository::class);
        $changes = self::getContainer()->get(WorkerRunStateChangeRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $runs);
        self::assertInstanceOf(WorkerRunStateChangeRepository::class, $changes);
        $controls = self::getContainer()->get(WorkerRunControls::class);
        self::assertInstanceOf(WorkerRunControls::class, $controls);
        $handler = new ListWorkerRunsHandler($runs, $changes, $source, $controls, new MockClock('2026-09-25 12:00:00'));

        $view = $handler(new ListWorkerRunsCommand($project, new WorkerRunListQuery()));

        self::assertCount(3, $view->items);
        $titles = [];
        foreach ($view->items as $item) {
            $titles[$item->run->cardNumber][] = $item->cardTitle;
        }
        ksort($titles);
        self::assertSame([1 => ['Fix the login', 'Fix the login'], 2 => [null]], $titles);
        // One call per page, each card once.
        self::assertCount(1, $asked);
        self::assertEqualsCanonicalizing([(string) $kept, (string) $gone], array_map(static fn (Uuid $id): string => (string) $id, $asked[0]));
    }

    public function test_each_item_carries_its_control(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'controls-'.uniqid().'@example.com');
        $project = $this->project($em, $owner, 'Controls');
        $bridge = $this->seedBridge($em, $owner);
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $running = $this->seedRun($em, $project, bridgeId: $bridge->id, state: WorkerRunState::Running);
        $succeeded = $this->seedRun($em, $project, bridgeId: $bridge->id, state: WorkerRunState::Succeeded);
        $handler = self::getContainer()->get(ListWorkerRunsHandler::class);
        self::assertInstanceOf(ListWorkerRunsHandler::class, $handler);

        $view = $handler(new ListWorkerRunsCommand($project, new WorkerRunListQuery()));

        $actions = [];
        foreach ($view->items as $item) {
            $actions[(string) $item->run->id] = $item->control?->action;
        }
        self::assertCount(2, $actions);
        self::assertSame(WorkerRunAction::Stop, $actions[(string) $running->id]);
        self::assertNull($actions[(string) $succeeded->id]);
    }

    public function test_the_states_filter_keeps_every_named_state(): void
    {
        [$project, $handler] = $this->filterScenario('states');
        $failed = $this->seedRun($this->em(), $project, exitCode: 1);
        $gaveUp = $this->seedRun($this->em(), $project, state: WorkerRunState::GaveUp);
        $this->seedRun($this->em(), $project, state: WorkerRunState::Succeeded);

        $view = $handler(new ListWorkerRunsCommand($project, new WorkerRunListQuery(states: [WorkerRunState::Failed, WorkerRunState::GaveUp])));

        self::assertEqualsCanonicalizing([(string) $failed->id, (string) $gaveUp->id], $this->ids($view));
    }

    public function test_the_card_number_filter_keeps_the_runs_of_that_card(): void
    {
        [$project, $handler] = $this->filterScenario('card-number');
        $kept = $this->seedRun($this->em(), $project, cardNumber: 7);
        $this->seedRun($this->em(), $project, cardNumber: 8);

        $view = $handler(new ListWorkerRunsCommand($project, new WorkerRunListQuery(cardNumber: 7)));

        self::assertSame([(string) $kept->id], $this->ids($view));
    }

    public function test_the_rule_filter_keeps_the_runs_of_that_rule(): void
    {
        [$project, $handler] = $this->filterScenario('rule');
        $kept = $this->seedRun($this->em(), $project, ruleName: 'implement');
        $this->seedRun($this->em(), $project, ruleName: 'plan');

        $view = $handler(new ListWorkerRunsCommand($project, new WorkerRunListQuery(rule: 'implement')));

        self::assertSame([(string) $kept->id], $this->ids($view));
    }

    /** Both bounds are inclusive, and a run with no end counts at the time its first report arrived. */
    public function test_the_time_window_reads_the_end_or_else_the_first_report(): void
    {
        [$project, $handler] = $this->filterScenario('window');
        $em = $this->em();
        $inside = $this->seedRun($em, $project);
        $inside->endedAt = new \DateTimeImmutable('2026-09-10 12:00:00');
        $early = $this->seedRun($em, $project);
        $early->endedAt = new \DateTimeImmutable('2026-09-01 12:00:00');
        $late = $this->seedRun($em, $project);
        $late->endedAt = new \DateTimeImmutable('2026-09-20 12:00:00');
        $timedOut = $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-09-11 08:00:00'), state: WorkerRunState::TimedOut);
        $timedOut->endedAt = null;
        $em->flush();

        $view = $handler(new ListWorkerRunsCommand($project, new WorkerRunListQuery(
            endedAfter: new \DateTimeImmutable('2026-09-10 12:00:00'),
            endedBefore: new \DateTimeImmutable('2026-09-15 00:00:00'),
        )));

        self::assertEqualsCanonicalizing([(string) $inside->id, (string) $timedOut->id], $this->ids($view));
    }

    public function test_the_view_carries_the_page_and_the_clamped_page_size(): void
    {
        [$project, $handler] = $this->filterScenario('paging');
        $this->seedRun($this->em(), $project);

        $view = $handler(new ListWorkerRunsCommand($project, new WorkerRunListQuery(page: 3), perPage: 500));

        self::assertSame(3, $view->page);
        self::assertSame(ListWorkerRunsHandler::MAX_PER_PAGE, $view->perPage);
    }

    /** @return array{Project, ListWorkerRunsHandler} */
    private function filterScenario(string $name): array
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'filter-'.$name.'-'.uniqid().'@example.com'), 'Filter '.$name);
        $handler = self::getContainer()->get(ListWorkerRunsHandler::class);
        self::assertInstanceOf(ListWorkerRunsHandler::class, $handler);

        return [$project, $handler];
    }

    /** @return list<string> */
    private function ids(ListWorkerRunsView $view): array
    {
        return array_map(static fn (WorkerRunListItem $item): string => (string) $item->run->id, $view->items);
    }
}
