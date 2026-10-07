<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\EventListener\WriteCardEventOnRunFinished;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;

final class WriteCardEventOnRunFinishedTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private Project $project;
    private Card $card;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->project = $this->makeProject('run-finished');
        $this->card = new Card($this->project, $this->column($this->project, 'backlog'), 'Plan it', '', 1);
        $this->em->persist($this->card);
        $this->em->flush();
    }

    public function test_a_finished_run_writes_one_row_with_its_detail(): void
    {
        $run = $this->workerRun($this->card->id, WorkerRunState::Succeeded);
        $run->startedAt = new \DateTimeImmutable('2026-09-30 10:00:00+00:00');
        $run->endedAt = new \DateTimeImmutable('2026-09-30 10:02:05+00:00');
        $this->em->flush();

        $this->listener()($this->event($run));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame(CardEventKind::RunFinished, $row->kind);
        self::assertSame(CardReporter::Agent, $row->actorKind);
        self::assertSame($this->project->owner->id?->toRfc4122(), $row->actorUser?->id?->toRfc4122());
        self::assertEquals(new \DateTimeImmutable('2026-09-30 10:02:05+00:00'), $row->occurredAt);
        $expected = [
            'runId' => (string) $run->id,
            'workKind' => 'plan',
            'state' => 'succeeded',
            'interactive' => false,
            'command' => false,
            'startedAt' => '2026-09-30T10:00:00+00:00',
            'endedAt' => '2026-09-30T10:02:05+00:00',
            'durationSeconds' => 125,
            'closedAt' => '2026-09-30T10:02:05+00:00',
            'stateSequence' => null,
        ];
        $detail = $row->detail;
        // JSONB does not keep key order.
        ksort($expected);
        ksort($detail);
        self::assertSame($expected, $detail);
    }

    public function test_a_finished_command_run_says_it_is_a_command(): void
    {
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $this->card->id ?? throw new \LogicException('A persisted card has an id.'),
            cardNumber: 1,
            workKind: 'sync',
            state: WorkerRunState::Failed,
            endedAt: new \DateTimeImmutable('2026-09-30 10:00:00+00:00'),
            exitCode: 1,
            kind: WorkerRunKind::Command,
        );
        $this->em->persist($run);
        $this->em->flush();

        $this->listener()($this->event($run));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertTrue($rows[0]->detail['command']);
        self::assertFalse($rows[0]->detail['interactive']);
    }

    public function test_a_repeated_dispatch_leaves_the_row_unchanged(): void
    {
        $run = $this->workerRun($this->card->id, WorkerRunState::Succeeded);
        $run->endedAt = new \DateTimeImmutable('2026-09-30 10:00:00+00:00');
        $this->em->flush();

        $this->listener()($this->event($run));
        $first = $this->rows();
        self::assertCount(1, $first);

        $this->listener()($this->event($run));
        $second = $this->rows();
        self::assertCount(1, $second);
        self::assertSame((string) $first[0]->id, (string) $second[0]->id);
        self::assertEquals($first[0]->occurredAt, $second[0]->occurredAt);
        self::assertSame($first[0]->detail, $second[0]->detail);
    }

    public function test_a_run_that_closes_again_in_the_same_state_takes_the_later_time(): void
    {
        $run = $this->workerRun($this->card->id, WorkerRunState::TimedOut);
        $run->endedAt = new \DateTimeImmutable('2026-09-30 10:00:00+00:00');
        $this->em->flush();
        $this->listener()($this->event($run));
        $first = $this->rows();
        self::assertCount(1, $first);

        $run = $this->em->find(WorkerRun::class, $run->id);
        self::assertInstanceOf(WorkerRun::class, $run);
        $run->endedAt = new \DateTimeImmutable('2026-09-30 11:00:00+00:00');
        $this->em->flush();
        $this->listener()($this->event($run));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame((string) $first[0]->id, (string) $rows[0]->id);
        self::assertSame('timed-out', $rows[0]->detail['state']);
        self::assertEquals(new \DateTimeImmutable('2026-09-30 11:00:00+00:00'), $rows[0]->occurredAt);
    }

    public function test_a_second_timeout_with_no_end_takes_the_time_of_its_state_change(): void
    {
        $run = $this->workerRun($this->card->id, WorkerRunState::TimedOut);
        $this->em->persist(new WorkerRunStateChange($run, WorkerRunState::TimedOut, new \DateTimeImmutable('2026-09-30 10:00:00+00:00'), new \DateTimeImmutable('2026-09-30 10:00:00+00:00')));
        $this->em->flush();
        $this->listener()($this->event($run));
        $this->listener()($this->event($run));
        $first = $this->rows();
        self::assertCount(1, $first);
        self::assertEquals(new \DateTimeImmutable('2026-09-30 10:00:00+00:00'), $first[0]->occurredAt);

        $run = $this->em->find(WorkerRun::class, $run->id);
        self::assertInstanceOf(WorkerRun::class, $run);
        $this->em->persist(new WorkerRunStateChange($run, WorkerRunState::Running, new \DateTimeImmutable('2026-09-30 10:10:00+00:00'), new \DateTimeImmutable('2026-09-30 10:10:00+00:00')));
        $this->em->persist(new WorkerRunStateChange($run, WorkerRunState::TimedOut, new \DateTimeImmutable('2026-09-30 10:30:00+00:00'), new \DateTimeImmutable('2026-09-30 10:30:00+00:00')));
        $this->em->flush();
        $this->listener()($this->event($run));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame((string) $first[0]->id, (string) $rows[0]->id);
        self::assertEquals(new \DateTimeImmutable('2026-09-30 10:30:00+00:00'), $rows[0]->occurredAt);
    }

    public function test_a_timed_out_run_that_later_succeeds_keeps_one_row_with_the_later_outcome(): void
    {
        $run = $this->workerRun($this->card->id, WorkerRunState::TimedOut);
        $run->endedAt = new \DateTimeImmutable('2026-09-30 10:00:00+00:00');
        $this->em->flush();
        $this->listener()($this->event($run));
        $first = $this->rows();
        self::assertCount(1, $first);
        self::assertSame('timed-out', $first[0]->detail['state']);

        $run = $this->em->find(WorkerRun::class, $run->id);
        self::assertInstanceOf(WorkerRun::class, $run);
        $run->state = WorkerRunState::Succeeded;
        $run->endedAt = new \DateTimeImmutable('2026-09-30 10:05:00+00:00');
        $this->em->flush();
        $this->listener()($this->event($run));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame((string) $first[0]->id, (string) $rows[0]->id);
        self::assertSame('succeeded', $rows[0]->detail['state']);
        self::assertEquals(new \DateTimeImmutable('2026-09-30 10:05:00+00:00'), $rows[0]->occurredAt);
        self::assertSame((string) $run->id, $rows[0]->runId?->toRfc4122());
    }

    public function test_the_table_refuses_a_second_row_for_one_run_of_one_card(): void
    {
        $run = $this->workerRun($this->card->id, WorkerRunState::Succeeded);
        $this->listener()($this->event($run));
        self::assertCount(1, $this->rows());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->getConnection()->executeStatement(
            "INSERT INTO board_card_events (id, card_id, project_id, kind, actor_kind, detail, occurred_at, run_id)
             VALUES (?, ?, ?, 'run-finished', 'agent', '{}', NOW(), ?)",
            [Uuid::v7()->toRfc4122(), (string) $this->card->id, (string) $this->project->id, (string) $run->id],
        );
    }

    public function test_a_write_from_an_older_state_change_does_not_replace_a_newer_outcome(): void
    {
        $events = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);
        $run = $this->workerRun($this->card->id, WorkerRunState::Succeeded);
        $runId = $run->id ?? throw new \LogicException('A stored run has an id.');
        $events->upsertRunFinished($this->card, $runId, null, ['runId' => (string) $runId, 'state' => 'succeeded', 'stateSequence' => 5], new \DateTimeImmutable('2026-09-30 10:05:00+00:00'));
        $this->setRunState($runId, WorkerRunState::TimedOut);
        $events->upsertRunFinished($this->card, $runId, null, ['runId' => (string) $runId, 'state' => 'timed-out', 'stateSequence' => 3], new \DateTimeImmutable('2026-09-30 10:10:00+00:00'));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame('succeeded', $rows[0]->detail['state']);
        self::assertEquals(new \DateTimeImmutable('2026-09-30 10:05:00+00:00'), $rows[0]->occurredAt);
    }

    public function test_the_newest_state_change_counts_even_when_it_carries_an_earlier_time(): void
    {
        $run = $this->workerRun($this->card->id, WorkerRunState::Succeeded);
        $this->em->persist(new WorkerRunStateChange($run, WorkerRunState::TimedOut, new \DateTimeImmutable('2026-09-30 10:30:00+00:00'), new \DateTimeImmutable('2026-09-30 10:30:00+00:00')));
        $this->em->flush();
        $this->em->persist(new WorkerRunStateChange($run, WorkerRunState::Succeeded, new \DateTimeImmutable('2026-09-30 10:20:00+00:00'), new \DateTimeImmutable('2026-09-30 10:40:00+00:00')));
        $this->em->flush();

        $this->listener()($this->event($run));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertEquals(new \DateTimeImmutable('2026-09-30 10:20:00+00:00'), $rows[0]->occurredAt);
        $sequences = $this->em->getConnection()->fetchFirstColumn('SELECT sequence FROM bridge_worker_run_states WHERE run_id = ? ORDER BY sequence', [(string) $run->id]);
        self::assertSame((int) end($sequences), $rows[0]->detail['stateSequence']);
    }

    public function test_a_stale_report_after_a_timeout_does_not_move_the_close_time(): void
    {
        $run = $this->workerRun($this->card->id, WorkerRunState::TimedOut);
        $this->em->persist(new WorkerRunStateChange($run, WorkerRunState::TimedOut, new \DateTimeImmutable('2026-09-30 10:30:00+00:00'), new \DateTimeImmutable('2026-09-30 10:30:00+00:00')));
        $this->em->flush();
        $this->em->persist(new WorkerRunStateChange($run, WorkerRunState::Running, new \DateTimeImmutable('2026-09-30 10:05:00+00:00'), new \DateTimeImmutable('2026-09-30 10:35:00+00:00')));
        $this->em->flush();

        $this->listener()($this->event($run));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertEquals(new \DateTimeImmutable('2026-09-30 10:30:00+00:00'), $rows[0]->occurredAt);
    }

    public function test_a_reopened_run_loses_its_row_until_it_closes_again(): void
    {
        $run = $this->workerRun($this->card->id, WorkerRunState::TimedOut);
        $this->em->persist(new WorkerRunStateChange($run, WorkerRunState::TimedOut, new \DateTimeImmutable('2026-09-30 10:30:00+00:00'), new \DateTimeImmutable('2026-09-30 10:30:00+00:00')));
        $this->em->flush();
        $this->listener()($this->event($run));
        self::assertCount(1, $this->rows());

        $run = $this->em->find(WorkerRun::class, $run->id);
        self::assertInstanceOf(WorkerRun::class, $run);
        $run->state = WorkerRunState::Running;
        $this->em->persist(new WorkerRunStateChange($run, WorkerRunState::Running, new \DateTimeImmutable('2026-09-30 10:35:00+00:00'), new \DateTimeImmutable('2026-09-30 10:35:00+00:00')));
        $this->em->flush();
        $this->listener()($this->event($run));
        self::assertCount(0, $this->rows());
    }

    public function test_a_late_reopen_does_not_remove_a_newer_close(): void
    {
        $events = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);
        $run = $this->workerRun($this->card->id, WorkerRunState::Succeeded);
        $runId = $run->id ?? throw new \LogicException('A stored run has an id.');
        $events->upsertRunFinished($this->card, $runId, null, ['runId' => (string) $runId, 'state' => 'succeeded', 'stateSequence' => 5], new \DateTimeImmutable('2026-09-30 10:05:00+00:00'));
        $events->deleteRunFinished($this->card, $runId, 3);

        self::assertCount(1, $this->rows());
    }

    public function test_a_delayed_close_write_for_a_run_that_reopened_writes_nothing(): void
    {
        $events = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);
        $run = $this->workerRun($this->card->id, WorkerRunState::Running);
        $runId = $run->id ?? throw new \LogicException('A stored run has an id.');
        $events->upsertRunFinished($this->card, $runId, null, ['runId' => (string) $runId, 'state' => 'timed-out', 'stateSequence' => 3], new \DateTimeImmutable('2026-09-30 10:10:00+00:00'));

        self::assertCount(0, $this->rows());
    }

    private function setRunState(Uuid $runId, WorkerRunState $state): void
    {
        $this->em->getConnection()->executeStatement('UPDATE bridge_worker_runs SET state = ? WHERE id = ?', [$state->value, $runId->toRfc4122()]);
    }

    public function test_an_open_run_writes_nothing(): void
    {
        $open = $this->workerRun($this->card->id, WorkerRunState::Running);
        $finished = $this->workerRun($this->card->id, WorkerRunState::Succeeded);

        $this->listener()($this->event($open, $finished));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame((string) $finished->id, $rows[0]->detail['runId']);
    }

    public function test_a_run_of_a_missing_card_writes_nothing(): void
    {
        $orphan = $this->workerRun(Uuid::v7(), WorkerRunState::Succeeded);
        $finished = $this->workerRun($this->card->id, WorkerRunState::Succeeded);

        $this->listener()($this->event($orphan, $finished));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame((string) $finished->id, $rows[0]->detail['runId']);
        self::assertSame(1, $this->countAll());
    }

    public function test_the_dispatcher_calls_the_listener(): void
    {
        $run = $this->workerRun($this->card->id, WorkerRunState::TimedOut);
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $dispatcher->dispatch($this->event($run));

        self::assertCount(1, $this->rows());
    }

    public function test_a_failed_write_leaves_the_entity_manager_and_the_transaction_usable(): void
    {
        $run = $this->workerRun($this->card->id, WorkerRunState::Succeeded);
        // Postgres rolls DDL back with the test transaction, so the constraint dies with the test.
        $this->em->getConnection()->executeStatement('ALTER TABLE board_card_events ADD CONSTRAINT test_refuse_rows CHECK (false) NOT VALID');

        $this->listener()($this->event($run));

        self::assertTrue($this->em->isOpen());
        self::assertSame(0, $this->countAll());
    }

    public function test_a_failed_write_for_one_run_still_writes_the_others(): void
    {
        $refused = $this->workerRun($this->card->id, WorkerRunState::Failed);
        $finished = $this->workerRun($this->card->id, WorkerRunState::Succeeded);
        $this->em->getConnection()->executeStatement(\sprintf(
            "ALTER TABLE board_card_events ADD CONSTRAINT test_refuse_one_run CHECK (detail->>'runId' IS DISTINCT FROM '%s') NOT VALID",
            (string) $refused->id,
        ));

        $this->listener()($this->event($refused, $finished));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame((string) $finished->id, $rows[0]->detail['runId']);
    }

    private function workerRun(?Uuid $cardId, WorkerRunState $state): WorkerRun
    {
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $cardId ?? throw new \LogicException('A persisted card has an id.'),
            cardNumber: 1,
            workKind: 'plan',
            state: $state,
        );
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function event(WorkerRun ...$runs): WorkerRunChanged
    {
        return new WorkerRunChanged(
            $this->project->id ?? throw new \LogicException('A persisted project has an id.'),
            [(string) $this->card->id],
            array_values(array_map(static fn (WorkerRun $run): string => (string) $run->id, $runs)),
        );
    }

    private function listener(): WriteCardEventOnRunFinished
    {
        $listener = self::getContainer()->get(WriteCardEventOnRunFinished::class);
        self::assertInstanceOf(WriteCardEventOnRunFinished::class, $listener);

        return $listener;
    }

    /** @return list<CardEvent> */
    private function rows(): array
    {
        $this->em->clear();
        $events = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);
        $card = $this->em->find(Card::class, $this->card->id);
        self::assertInstanceOf(Card::class, $card);

        return array_values(array_filter(
            $events->findForCard($card),
            static fn (CardEvent $event): bool => CardEventKind::RunFinished === $event->kind,
        ));
    }

    private function countAll(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM board_card_events WHERE project_id = ? AND kind = ?',
            [(string) $this->project->id, CardEventKind::RunFinished->value],
        );
    }
}
