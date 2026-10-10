<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Service\CardLiveWork;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Tests\Module\Board\CardStateFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardLiveWorkTest extends KernelTestCase
{
    use CardStateFixtures;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_lists_the_live_requests_and_the_open_runs_of_each_card_with_their_start(): void
    {
        $project = $this->stateProject('live-work');
        $busy = $this->stateCard($project);
        $idle = $this->stateCard($project);
        $this->requestWork($busy, 'implement', '2026-10-02 09:30:00');
        $this->openRun($busy, 'fix', '2026-10-02 09:45:00');

        $work = $this->liveWork()->forCards($project, [(string) $busy->id, (string) $idle->id]);

        self::assertSame([(string) $busy->id], array_keys($work));
        self::assertSame(['implement', 'fix'], array_map(static fn ($item) => $item->kind, $work[(string) $busy->id]));
        self::assertSame([false, true], array_map(static fn ($item) => $item->isRun, $work[(string) $busy->id]));
        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:45:00'), $work[(string) $busy->id][1]->since);
    }

    public function test_a_settled_request_and_a_closed_run_are_not_live(): void
    {
        $project = $this->stateProject('live-work-settled');
        $card = $this->stateCard($project);
        $request = $this->requestWork($card);
        $request->state = WorkRequestState::Done;
        $run = $this->openRun($card);
        $run->moveTo(\App\Module\Bridge\ValueObject\WorkerRunState::Succeeded);
        $this->em()->flush();

        self::assertSame([], $this->liveWork()->forCards($project, [(string) $card->id]));
    }

    public function test_it_reads_nothing_for_no_card(): void
    {
        self::assertSame([], $this->liveWork()->forCards($this->stateProject('live-work-none'), []));
    }

    private function liveWork(): CardLiveWork
    {
        $service = self::getContainer()->get(CardLiveWork::class);
        self::assertInstanceOf(CardLiveWork::class, $service);

        return $service;
    }
}
