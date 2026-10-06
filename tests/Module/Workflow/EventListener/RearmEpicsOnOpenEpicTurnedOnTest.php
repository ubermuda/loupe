<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\EventListener;

use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Command\SaveBoardAutomationSettingsCommand;
use App\Module\Board\Command\SaveBoardAutomationSettingsHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Action\ForgeWrite;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\EventListener\RearmEpicsOnOpenEpicTurnedOn;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RearmEpicsOnOpenEpicTurnedOnTest extends KernelTestCase
{
    use ActionScenario;

    private const string LATER = '2026-12-01 12:00:00';

    public function test_turning_the_open_write_on_releases_a_paused_epic_and_rearms_its_rule(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('open-epic-paused');
        $epic = $this->card($project, 'next');
        $state = $this->refusedState($epic, null);
        $pause = $this->pause($epic, ForgeWrite::OPEN_EPIC_OFF);
        $this->transport()->reset();

        $this->save($project, true, true);

        $this->em()->clear();
        $released = $this->em()->find(CardPause::class, $pause->id);
        self::assertInstanceOf(CardPause::class, $released);
        self::assertNotNull($released->releasedAt);
        self::assertSame(RearmEpicsOnOpenEpicTurnedOn::REASON, $released->releaseReason);
        $rearmed = $this->em()->find(WorkflowRuleState::class, $state->id);
        self::assertInstanceOf(WorkflowRuleState::class, $rearmed);
        self::assertSame([false, 0, null, null], [$rearmed->truth, $rearmed->attempts, $rearmed->dueAt, $rearmed->lastRefusal]);
        self::assertSame(['system'], $this->em()->getConnection()->fetchFirstColumn(
            "SELECT actor_kind FROM board_card_events WHERE card_id = ? AND kind = 'pause-released'",
            [(string) $epic->id],
        ));
        self::assertEquals([new EvaluateCard((string) $epic->id)], $this->queued());
    }

    public function test_turning_the_open_write_on_makes_a_waiting_retry_due_now(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('open-epic-backoff');
        $epic = $this->card($project, 'next');
        $state = $this->refusedState($epic, new \DateTimeImmutable(self::LATER));
        $this->transport()->reset();

        $this->save($project, true, true);

        $this->em()->clear();
        $due = $this->em()->find(WorkflowRuleState::class, $state->id);
        self::assertInstanceOf(WorkflowRuleState::class, $due);
        self::assertNotNull($due->dueAt);
        self::assertLessThanOrEqual(new \DateTimeImmutable(), $due->dueAt);
        self::assertSame(ForgeWrite::OPEN_EPIC_OFF, $due->lastRefusal);
        self::assertEquals([new EvaluateCard((string) $epic->id)], $this->queued());
    }

    public function test_a_save_that_keeps_the_open_write_on_rearms_nothing(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('open-epic-kept');
        $epic = $this->card($project, 'next');
        $this->save($project, true, true);
        $state = $this->refusedState($epic, new \DateTimeImmutable(self::LATER));
        $pause = $this->pause($epic, ForgeWrite::OPEN_EPIC_OFF);
        $this->transport()->reset();

        $this->save($project, true, true);

        $this->assertUntouched($epic, $state, $pause);
    }

    public function test_a_save_that_also_turns_the_automation_on_leaves_the_pause_for_a_person(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('open-epic-baseline');
        $epic = $this->card($project, 'next');
        $state = $this->refusedState($epic, null);
        $pause = $this->pause($epic, ForgeWrite::OPEN_EPIC_OFF);
        $this->save($project, false, false);
        $this->transport()->reset();

        $this->save($project, true, true);

        $this->assertUntouched($epic, $state, $pause);
    }

    public function test_a_pause_for_another_refusal_stays(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('open-epic-other');
        $epic = $this->card($project, 'next');
        $this->refusedState($epic, null);
        $pause = $this->pause($epic, 'no-merged-child');
        $this->transport()->reset();

        $this->save($project, true, true);

        self::assertSame($pause->id?->toRfc4122(), $this->service(CardPauseRepository::class)->findActiveForCard($epic)?->id?->toRfc4122());
        self::assertSame([], $this->queued());
    }

    private function assertUntouched(Card $epic, WorkflowRuleState $state, CardPause $pause): void
    {
        self::assertSame($pause->id?->toRfc4122(), $this->service(CardPauseRepository::class)->findActiveForCard($epic)?->id?->toRfc4122());
        $this->em()->clear();
        $stored = $this->em()->find(WorkflowRuleState::class, $state->id);
        self::assertInstanceOf(WorkflowRuleState::class, $stored);
        self::assertEquals($state->dueAt, $stored->dueAt);
        self::assertSame($state->attempts, $stored->attempts);
        self::assertSame(ForgeWrite::OPEN_EPIC_OFF, $stored->lastRefusal);
        self::assertSame([], $this->queued());
    }

    private function refusedState(Card $epic, ?\DateTimeImmutable $dueAt): WorkflowRuleState
    {
        $state = $this->state($epic, 'epic-open-pull-request');
        $state->truth = true;
        $state->attempts = 2;
        $state->dueAt = $dueAt;
        $state->lastRefusal = ForgeWrite::OPEN_EPIC_OFF;
        $state->lastRefusalAt = new \DateTimeImmutable('2026-10-02 12:00:00');
        $this->em()->persist($state);
        $this->em()->flush();

        return $state;
    }

    private function pause(Card $epic, string $reason): CardPause
    {
        return $this->service(PauseCardHandler::class)(new PauseCardCommand($epic, $reason, 'epic-open-pull-request', CardPauseKind::Retries))
            ?? throw new \LogicException('The card had no pause.');
    }

    private function save(Project $project, bool $enabled, bool $openEpicPullRequests): void
    {
        $settings = $this->service(BoardAutomation::class)->settingsOf($project);
        $this->service(SaveBoardAutomationSettingsHandler::class)(new SaveBoardAutomationSettingsCommand(
            project: $project,
            enabled: $enabled,
            commentOnFixQueued: $settings->commentOnFixQueued,
            commentOnStaleApproval: $settings->commentOnStaleApproval,
            syncBehind: $settings->syncBehind,
            mergePullRequests: $settings->mergePullRequests,
            changeBase: $settings->changeBase,
            openEpicPullRequests: $openEpicPullRequests,
        ));
    }

    /** @return list<EvaluateCard> */
    private function queued(): array
    {
        return array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $this->transport()->getSent()),
            static fn (object $message): bool => $message instanceof EvaluateCard,
        ));
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
