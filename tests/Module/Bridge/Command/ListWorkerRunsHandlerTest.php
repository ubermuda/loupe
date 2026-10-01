<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\ListWorkerRunsCommand;
use App\Module\Bridge\Command\ListWorkerRunsHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerRunStateChangeRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\View\CardTitleSourceInterface;
use App\Module\Bridge\View\WorkerRunAction;
use App\Module\Bridge\View\WorkerRunControls;
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
}
