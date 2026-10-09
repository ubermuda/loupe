<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Messenger;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Messenger\CollectSessionUsage;
use App\Module\Bridge\Messenger\CollectSessionUsageHandler;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class CollectSessionUsageHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_a_closed_run_asks_its_capable_bridge(): void
    {
        self::bootKernel();
        $run = $this->closedRun('collect-message');

        $this->handler()($this->messageOf($run));

        $rows = $this->em()->getConnection()->fetchAllAssociative('SELECT kind, worker_run_id FROM bridge_commands');
        self::assertSame([['kind' => 'collect-session-usage', 'worker_run_id' => (string) $run->id]], $rows);
    }

    public function test_a_run_with_usage_asks_nothing(): void
    {
        self::bootKernel();
        $run = $this->closedRun('collect-message-has-usage');
        $run->usageSource = WorkerRunUsageSource::Reported;
        $this->em()->flush();

        $this->handler()($this->messageOf($run));

        self::assertSame(0, $this->countCommands($this->em()));
    }

    public function test_a_missing_run_asks_nothing(): void
    {
        self::bootKernel();
        $run = $this->closedRun('collect-message-missing');

        $this->handler()(new CollectSessionUsage((string) $run->project->id, (string) Uuid::v7()));
        $this->handler()(new CollectSessionUsage((string) Uuid::v7(), (string) $run->id));

        self::assertSame(0, $this->countCommands($this->em()));
    }

    public function test_inside_an_unflushed_write_it_queues_the_message_and_asks_nothing(): void
    {
        self::bootKernel();
        $run = $this->closedRun('collect-message-inline');
        $this->em()->persist(new Document($run->project->owner, $run->project, 'Not flushed yet'));
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
        $message = $this->messageOf($run);

        $this->handler()($message);

        self::assertSame(0, $this->countCommands($this->em()));
        self::assertSame([$message], array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent()));
    }

    private function closedRun(string $name): WorkerRun
    {
        $owner = $this->user($this->em(), $name.'@example.com');
        $project = $this->project($this->em(), $owner, 'Project '.substr(md5($name), 0, 8));
        $bridge = $this->seedBridge($this->em(), $owner, projects: [($project->id ?? throw new \LogicException('A persisted project has an id.'))->toRfc4122()]);
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS, Bridge::CAPABILITY_SESSION_USAGE];
        $this->em()->flush();

        return $this->seedRun($this->em(), $project, exitCode: null, state: WorkerRunState::Closed, kind: WorkerRunKind::Interactive);
    }

    private function messageOf(WorkerRun $run): CollectSessionUsage
    {
        return new CollectSessionUsage((string) $run->project->id, (string) $run->id);
    }

    private function handler(): CollectSessionUsageHandler
    {
        $handler = self::getContainer()->get(CollectSessionUsageHandler::class);
        self::assertInstanceOf(CollectSessionUsageHandler::class, $handler);

        return $handler;
    }
}
