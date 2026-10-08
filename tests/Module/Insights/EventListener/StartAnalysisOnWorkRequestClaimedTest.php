<?php

declare(strict_types=1);

namespace App\Tests\Module\Insights\EventListener;

use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Insights\Entity\Analysis;
use App\Module\Insights\Entity\AnalysisState;
use App\Module\Insights\EventListener\StartAnalysisOnWorkRequestClaimed;
use App\Module\Insights\Repository\AnalysisRepository;
use App\Tests\Module\Insights\InsightsScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class StartAnalysisOnWorkRequestClaimedTest extends KernelTestCase
{
    use InsightsScenario;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_claim_starts_a_waiting_analysis(): void
    {
        $analysis = $this->seedAnalysis($this->em(), $this->scenarioProject('claim-starts'));

        $this->dispatch($analysis, 'analysis', WorkRequestState::Claimed, viaDispatcher: true);

        self::assertSame(AnalysisState::Running, $this->stored($analysis)->state);
    }

    /** @return iterable<string, array{AnalysisState, string, WorkRequestState}> */
    public static function ignored(): iterable
    {
        yield 'an open request' => [AnalysisState::Waiting, 'analysis', WorkRequestState::Open];
        yield 'a done request' => [AnalysisState::Waiting, 'analysis', WorkRequestState::Done];
        yield 'a card subject with the same id' => [AnalysisState::Waiting, 'card', WorkRequestState::Claimed];
        yield 'a paused analysis' => [AnalysisState::Paused, 'analysis', WorkRequestState::Claimed];
        yield 'a failed analysis' => [AnalysisState::Failed, 'analysis', WorkRequestState::Claimed];
    }

    #[DataProvider('ignored')]
    public function test_any_other_change_leaves_the_analysis_alone(AnalysisState $state, string $subjectType, WorkRequestState $requestState): void
    {
        $analysis = $this->seedAnalysis($this->em(), $this->scenarioProject('claim-ignored'), $state);

        $this->dispatch($analysis, $subjectType, $requestState);

        self::assertSame($state, $this->stored($analysis)->state);
    }

    private function dispatch(Analysis $analysis, string $subjectType, WorkRequestState $state, bool $viaDispatcher = false): void
    {
        $event = new WorkRequestChanged(
            $analysis->project->id ?? throw new \LogicException('A stored project has an id.'),
            $subjectType,
            $analysis->id ?? throw new \LogicException('A stored analysis has an id.'),
            Uuid::v7(),
            $state,
        );
        if ($viaDispatcher) {
            $events = self::getContainer()->get(EventDispatcherInterface::class);
            self::assertInstanceOf(EventDispatcherInterface::class, $events);
            $events->dispatch($event);

            return;
        }
        // Called directly, so a card subject wakes no workflow listener.
        $listener = self::getContainer()->get(StartAnalysisOnWorkRequestClaimed::class);
        self::assertInstanceOf(StartAnalysisOnWorkRequestClaimed::class, $listener);
        $listener($event);
    }

    private function stored(Analysis $analysis): Analysis
    {
        $this->em()->clear();
        $repository = self::getContainer()->get(AnalysisRepository::class);
        self::assertInstanceOf(AnalysisRepository::class, $repository);
        $stored = $repository->find($analysis->id);
        self::assertInstanceOf(Analysis::class, $stored);

        return $stored;
    }
}
