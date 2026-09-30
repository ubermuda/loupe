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
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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

        $this->enableBoard();
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
        $run->resumeIndex = 1;
        $run->resumeCap = 3;
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
            'ruleName' => 'plan',
            'state' => 'succeeded',
            'interactive' => false,
            'startedAt' => '2026-09-30T10:00:00+00:00',
            'endedAt' => '2026-09-30T10:02:05+00:00',
            'durationSeconds' => 125,
            'resumeIndex' => 1,
            'resumeCap' => 3,
        ];
        $detail = $row->detail;
        // JSONB does not keep key order.
        ksort($expected);
        ksort($detail);
        self::assertSame($expected, $detail);
    }

    public function test_a_second_dispatch_for_the_same_run_writes_nothing_more(): void
    {
        $run = $this->workerRun($this->card->id, WorkerRunState::Failed);

        $this->listener()($this->event($run));
        self::assertCount(1, $this->rows());

        $this->listener()($this->event($run));
        self::assertCount(1, $this->rows());
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

    private function workerRun(?Uuid $cardId, WorkerRunState $state): WorkerRun
    {
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: Uuid::v7(),
            cardId: $cardId ?? throw new \LogicException('A persisted card has an id.'),
            cardNumber: 1,
            ruleName: 'plan',
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
