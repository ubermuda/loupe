<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Scheduler;

use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Messenger\ReconcileCardWaits;
use App\Module\Inbox\Scheduler\SweepCardWaitsTask;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxFixtures;
use App\Tests\Support\ScheduledTasks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class SweepCardWaitsTaskTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private InMemoryTransport $transport;
    private Project $watched;
    private Project $closedWatch;
    private Project $quiet;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->transport = $transport;

        $owner = $this->owner($em, 'sweep-waits');
        $this->watched = $this->project($em, $owner, 'sweep-watched');
        $this->closedWatch = $this->project($em, $owner, 'sweep-closed');
        $this->quiet = $this->project($em, $owner, 'sweep-quiet');
        $this->watch($this->watched, 1);
        $this->watch($this->watched, 2);
        $this->watch($this->closedWatch, 1)->closedAt = new \DateTimeImmutable();
        $em->flush();
    }

    public function test_the_sweep_is_registered_on_the_default_schedule_every_fifteen_minutes(): void
    {
        self::assertSame(
            '*/15 * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[SweepCardWaitsTask::class] ?? null,
        );
    }

    public function test_while_the_inbox_is_on_every_project_is_reconciled_once(): void
    {
        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
        $this->transport->reset();

        $this->task()();

        $sent = $this->sent();
        foreach ([$this->watched, $this->closedWatch, $this->quiet] as $project) {
            self::assertSame(1, $sent[(string) $project->id] ?? 0);
        }
    }

    public function test_while_the_inbox_is_off_only_projects_with_an_open_watch_are_reconciled(): void
    {
        $this->switchFlag($this->em, InboxInstallFlags::FLAG_INBOX_ENABLED, false);
        $this->transport->reset();

        $this->task()();

        $sent = $this->sent();
        self::assertSame(1, $sent[(string) $this->watched->id] ?? 0);
        self::assertArrayNotHasKey((string) $this->closedWatch->id, $sent);
        self::assertArrayNotHasKey((string) $this->quiet->id, $sent);
    }

    private function task(): SweepCardWaitsTask
    {
        $task = self::getContainer()->get(SweepCardWaitsTask::class);
        self::assertInstanceOf(SweepCardWaitsTask::class, $task);

        return $task;
    }

    private function watch(Project $project, int $number): InboxCardWatch
    {
        $item = new InboxItem(project: $project, number: $number, kind: InboxItemKind::Wait, title: '#'.$number.' Ship it', blocking: true);
        $this->em->persist($item);
        $watch = new InboxCardWatch($item, Uuid::v7(), $number);
        $this->em->persist($watch);

        return $watch;
    }

    /** @return array<string, int> project id => messages for the whole project */
    private function sent(): array
    {
        $sent = [];
        foreach ($this->transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            self::assertInstanceOf(ReconcileCardWaits::class, $message);
            self::assertNull($message->cardIds);
            $sent[$message->projectId] = ($sent[$message->projectId] ?? 0) + 1;
        }

        return $sent;
    }
}
