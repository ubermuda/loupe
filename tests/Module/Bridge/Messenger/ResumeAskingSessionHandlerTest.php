<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Messenger;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Messenger\ResumeAskingSession;
use App\Module\Bridge\Messenger\ResumeAskingSessionHandler;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Review\Entity\Document;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class ResumeAskingSessionHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_asks_the_bridge_to_resume_the_run_of_the_session(): void
    {
        self::bootKernel();
        $message = $this->blockedRun('ask-session-resume');

        $this->handler()($message);

        self::assertSame(1, $this->countCommands($this->em()));
    }

    public function test_inside_an_unflushed_write_it_queues_the_message_and_sends_nothing(): void
    {
        self::bootKernel();
        $message = $this->blockedRun('ask-session-inline');
        $project = $this->project($this->em(), $this->user($this->em(), 'ask-session-inline-other@example.com'), 'Other');
        $this->em()->persist(new Document($project->owner, $project, 'Not flushed yet'));
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $this->handler()($message);

        self::assertSame(0, $this->countCommands($this->em()));
        self::assertSame([$message], array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent()));
    }

    public function test_a_deleted_project_is_skipped(): void
    {
        self::bootKernel();

        $this->handler()(new ResumeAskingSession((string) Uuid::v7(), (string) Uuid::v4(), (string) Uuid::v4(), new \DateTimeImmutable()));

        self::assertSame(0, $this->countCommands($this->em()));
    }

    private function blockedRun(string $name): ResumeAskingSession
    {
        $owner = $this->user($this->em(), $name.'@example.com');
        $project = $this->project($this->em(), $owner, 'Project '.substr(md5($name), 0, 8));
        $bridge = $this->seedBridge($this->em(), $owner);
        $bridge->capabilities = [Bridge::CAPABILITY_COMMANDS];
        $this->em()->flush();
        $run = $this->seedRun($this->em(), $project, bridgeId: $bridge->id, state: WorkerRunState::Blocked);

        return new ResumeAskingSession((string) $project->id, (string) $bridge->id, (string) $run->sessionId, new \DateTimeImmutable('2026-01-01 10:10:00'));
    }

    private function handler(): ResumeAskingSessionHandler
    {
        $handler = self::getContainer()->get(ResumeAskingSessionHandler::class);
        self::assertInstanceOf(ResumeAskingSessionHandler::class, $handler);

        return $handler;
    }
}
