<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\RequestSessionUsageCollectionCommand;
use App\Module\Bridge\Command\RequestSessionUsageCollectionHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class RequestSessionUsageCollectionHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    private const array CAPABLE = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_SESSION_USAGE];

    public function test_no_capable_bridge_writes_nothing(): void
    {
        [$owner, $project, $run] = $this->scenario('collect-none');
        $this->bridge($owner, $project, [Bridge::CAPABILITY_COMMANDS]);
        $this->bridge($owner, $project, [Bridge::CAPABILITY_SESSION_USAGE]);

        self::assertSame([], $this->collect($run));
        self::assertSame(0, $this->countCommands($this->em()));
        self::assertSame([], $this->outboxPayloads());
    }

    public function test_each_capable_bridge_that_follows_the_project_gets_one_command(): void
    {
        [$owner, $project, $run] = $this->scenario('collect-two');
        $first = $this->bridge($owner, $project, self::CAPABLE);
        $second = $this->bridge($owner, $project, self::CAPABLE);
        $this->bridge($owner, $project, [Bridge::CAPABILITY_COMMANDS]);
        $this->bridge($owner, $this->project($this->em(), $owner, 'Collect elsewhere'), self::CAPABLE);
        $this->seedBridge($this->em(), $owner, projects: [])->capabilities = self::CAPABLE;
        $this->em()->flush();

        $commands = $this->collect($run);

        self::assertCount(2, $commands);
        $expected = [(string) $first->id, (string) $second->id];
        sort($expected);
        $bridgeIds = array_map(static fn (BridgeCommand $c): string => (string) $c->bridgeId, $commands);
        sort($bridgeIds);
        self::assertSame($expected, $bridgeIds);
        foreach ($commands as $command) {
            self::assertSame(BridgeCommandKind::CollectSessionUsage, $command->kind);
            self::assertSame(BridgeCommandState::Pending, $command->state);
            self::assertNull($command->requestedBy);
            self::assertSame((string) $run->id, (string) $command->workerRun->id);
        }

        $payloads = $this->outboxPayloads();
        self::assertCount(2, $payloads);
        $payloadBridges = array_column($payloads, 'bridgeId');
        sort($payloadBridges);
        self::assertSame($expected, $payloadBridges);
        self::assertSame('collect-session-usage', $payloads[0]['kind']);
        self::assertSame((string) $run->id, $payloads[0]['runId']);
        self::assertSame((string) $run->sessionId, $payloads[0]['sessionId']);
        self::assertSame($run->startedAt?->format(\DateTimeInterface::ATOM), $payloads[0]['startedAt']);
        self::assertSame($run->endedAt?->format(\DateTimeInterface::ATOM), $payloads[0]['endedAt']);
    }

    public function test_a_launched_run_asks_only_its_own_bridge(): void
    {
        $em = $this->boot();
        $owner = $this->user($em, 'collect-launched@example.com');
        $project = $this->project($em, $owner, 'Collect launched');
        $own = $this->bridge($owner, $project, self::CAPABLE);
        $this->bridge($owner, $project, self::CAPABLE);
        $run = $this->closedRun($project, $own->id);

        $commands = $this->collect($run);

        self::assertCount(1, $commands);
        self::assertSame((string) $own->id, (string) $commands[0]->bridgeId);
    }

    public function test_a_second_call_writes_nothing_while_a_collection_waits(): void
    {
        [$owner, $project, $run] = $this->scenario('collect-repeat');
        $this->bridge($owner, $project, self::CAPABLE);

        self::assertCount(1, $this->collect($run));
        self::assertSame([], $this->collect($run));
        self::assertSame(1, $this->countCommands($this->em()));
        self::assertTrue($this->em()->isOpen(), 'the pending check answers, and the unique index never fires');
    }

    public function test_a_settled_collection_lets_a_new_one_through(): void
    {
        [$owner, $project, $run] = $this->scenario('collect-settled');
        $this->bridge($owner, $project, self::CAPABLE);
        $first = $this->collect($run)[0];
        $first->settle(BridgeCommandState::Expired, null, new \DateTimeImmutable());
        $this->em()->flush();

        self::assertCount(1, $this->collect($run));
    }

    public function test_a_run_with_usage_writes_nothing(): void
    {
        [$owner, $project, $run] = $this->scenario('collect-has-usage');
        $this->bridge($owner, $project, self::CAPABLE);
        $this->seedUsage($this->em(), $run);

        self::assertSame([], $this->collect($run));
    }

    /** @return iterable<string, array{\Closure(WorkerRun): void}> */
    public static function notCollectable(): iterable
    {
        yield 'a run that still runs' => [static function (WorkerRun $run): void {
            $run->state = WorkerRunState::Running;
            $run->endedAt = null;
        }];
        yield 'a run with no session' => [static function (WorkerRun $run): void {
            $run->sessionId = null;
        }];
        yield 'a run with no start' => [static function (WorkerRun $run): void {
            $run->startedAt = null;
        }];
        yield 'a run with no end' => [static function (WorkerRun $run): void {
            $run->endedAt = null;
        }];
    }

    /** @param \Closure(WorkerRun): void $change */
    #[DataProvider('notCollectable')]
    public function test_a_run_that_cannot_be_collected_writes_nothing(\Closure $change): void
    {
        [$owner, $project, $run] = $this->scenario('collect-not-'.md5((string) $this->dataName()));
        $this->bridge($owner, $project, self::CAPABLE);
        $change($run);
        $this->em()->flush();

        self::assertSame([], $this->collect($run));
    }

    public function test_a_worker_run_writes_nothing(): void
    {
        $em = $this->boot();
        $owner = $this->user($em, 'collect-worker@example.com');
        $project = $this->project($em, $owner, 'Collect worker');
        $bridge = $this->bridge($owner, $project, self::CAPABLE);
        $run = $this->seedRun($em, $project, bridgeId: $bridge->id, state: WorkerRunState::Succeeded);

        self::assertSame([], $this->collect($run));
    }

    private function boot(): EntityManagerInterface
    {
        self::bootKernel();
        self::getContainer()->set('clock', new MockClock('2026-10-06T12:00:00+00:00'));

        return $this->em();
    }

    /** @return array{User, Project, WorkerRun} */
    private function scenario(string $name): array
    {
        $em = $this->boot();
        $owner = $this->user($em, $name.'@example.com');
        $project = $this->project($em, $owner, 'Project '.substr(md5($name), 0, 8));

        return [$owner, $project, $this->closedRun($project, null)];
    }

    private function closedRun(Project $project, ?Uuid $bridgeId): WorkerRun
    {
        return $this->seedRun($this->em(), $project, exitCode: null, bridgeId: $bridgeId, state: WorkerRunState::Closed, kind: WorkerRunKind::Interactive);
    }

    /** @param list<string> $capabilities */
    private function bridge(User $owner, Project $project, array $capabilities): Bridge
    {
        $bridge = $this->seedBridge($this->em(), $owner, projects: [($project->id ?? throw new \LogicException('A persisted project has an id.'))->toRfc4122()]);
        $bridge->capabilities = $capabilities;
        $this->em()->flush();

        return $bridge;
    }

    /** @return list<BridgeCommand> */
    private function collect(WorkerRun $run): array
    {
        $handler = self::getContainer()->get(RequestSessionUsageCollectionHandler::class);
        self::assertInstanceOf(RequestSessionUsageCollectionHandler::class, $handler);

        return $handler(new RequestSessionUsageCollectionCommand($run));
    }

    /** @return list<array<string, mixed>> */
    private function outboxPayloads(): array
    {
        /** @var list<string> $payloads */
        $payloads = $this->em()->getConnection()->fetchFirstColumn(
            "SELECT payload FROM outbox_events WHERE type = 'bridge.command' ORDER BY sequence",
        );

        return array_map(static fn (string $payload): array => json_decode($payload, true, flags: \JSON_THROW_ON_ERROR), $payloads);
    }
}
