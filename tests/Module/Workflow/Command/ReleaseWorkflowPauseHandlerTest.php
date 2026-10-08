<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\ReleaseWorkflowPauseCommand;
use App\Module\Workflow\Command\ReleaseWorkflowPauseHandler;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Tests\Module\Workflow\WorkflowProjects;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class ReleaseWorkflowPauseHandlerTest extends KernelTestCase
{
    use WorkflowProjects;

    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('workflow-release');
        $this->bindLifecycle($this->project);
    }

    /** @return iterable<string, array{CardPauseKind}> */
    public static function releasableKinds(): iterable
    {
        yield 'retries' => [CardPauseKind::Retries];
        yield 'work timeout' => [CardPauseKind::WorkTimeout];
        yield 'work limit' => [CardPauseKind::WorkLimit];
    }

    #[DataProvider('releasableKinds')]
    public function test_a_person_releases_the_pause_rearms_the_rule_records_the_history_and_queues_an_evaluation(CardPauseKind $kind): void
    {
        $card = $this->card();
        $state = $this->spentState($card, 'tech-design-write');
        $pause = $this->pause($card, $kind, 'tech-design-write');
        $this->transport()->reset();

        $released = $this->handler()(new ReleaseWorkflowPauseCommand($card, $this->project->owner, CardReporter::Human, $pause->id));

        self::assertSame([$kind, 'move-refused', 'tech-design-write'], [$released->kind, $released->reason, $released->ruleId]);
        $this->em()->clear();
        $stored = $this->em()->find(CardPause::class, $pause->id);
        self::assertInstanceOf(CardPause::class, $stored);
        self::assertNotNull($stored->releasedAt);
        self::assertSame(ReleaseWorkflowPauseCommand::REASON, $stored->releaseReason);

        $rearmed = $this->em()->find(WorkflowRuleState::class, $state->id);
        self::assertInstanceOf(WorkflowRuleState::class, $rearmed);
        self::assertSame([false, 0, 0, null, null, null], [$rearmed->truth, $rearmed->attempts, $rearmed->fires, $rearmed->dueAt, $rearmed->lastRefusal, $rearmed->lastRefusalAt]);

        $rows = $this->releasedRows($card);
        self::assertCount(1, $rows);
        self::assertSame('human', $rows[0]['actor_kind']);
        self::assertSame((string) $this->project->owner->id, $rows[0]['actor_user_id']);
        self::assertSame(['kind' => $kind->value, 'reason' => 'move-refused', 'ruleId' => 'tech-design-write'], json_decode((string) $rows[0]['detail'], true));

        self::assertEquals([new EvaluateCard((string) $card->id)], $this->queued());
    }

    public function test_an_agent_releases_the_active_pause_with_no_pause_id(): void
    {
        $card = $this->card();
        $this->pause($card, CardPauseKind::Retries, 'tech-design-write');

        $released = $this->handler()(new ReleaseWorkflowPauseCommand($card, $this->project->owner, CardReporter::Agent, null));

        self::assertSame(CardPauseKind::Retries, $released->kind);
        self::assertNull($this->service(CardPauseRepository::class)->findActiveForCard($card));
        self::assertSame('agent', $this->releasedRows($card)[0]['actor_kind']);
    }

    public function test_a_release_whose_rule_has_no_state_writes_none(): void
    {
        $card = $this->card();
        $this->pause($card, CardPauseKind::WorkTimeout, 'tech-design-write');

        $this->handler()(new ReleaseWorkflowPauseCommand($card, $this->project->owner, CardReporter::Human, null));

        self::assertSame([], $this->service(WorkflowRuleStateRepository::class)->findForCard($card));
    }

    public function test_a_held_card_is_refused_as_unmanaged(): void
    {
        $card = $this->card();
        $pause = $this->pause($card, CardPauseKind::Retries, 'tech-design-write');
        $this->service(CardHolds::class)->hold($this->project, $card->id ?? throw new \LogicException('A created card has an id.'), null);

        $this->assertRefused($card, $pause->id, ReleaseWorkflowPauseHandler::CARD_UNMANAGED);
    }

    public function test_a_card_whose_board_automation_is_off_is_refused_as_unmanaged(): void
    {
        $card = $this->card();
        $pause = $this->pause($card, CardPauseKind::Retries, 'tech-design-write');
        $this->service(BoardAutomation::class)->settingsForUpdate($this->project)->enabled = false;
        $this->em()->flush();

        $this->assertRefused($card, $pause->id, ReleaseWorkflowPauseHandler::CARD_UNMANAGED);
    }

    public function test_a_card_with_no_active_pause_is_refused(): void
    {
        $this->assertRefused($this->card(), null, ReleaseWorkflowPauseHandler::NOT_PAUSED);
    }

    public function test_a_released_pause_is_refused_as_not_paused(): void
    {
        $card = $this->card();
        $pause = $this->pause($card, CardPauseKind::Retries, 'tech-design-write');
        $this->handler()(new ReleaseWorkflowPauseCommand($card, $this->project->owner, CardReporter::Human, $pause->id));

        $this->assertRefused($card, $pause->id, ReleaseWorkflowPauseHandler::NOT_PAUSED, rows: 1);
    }

    public function test_a_pause_id_that_is_not_the_active_pause_is_refused(): void
    {
        $card = $this->card();
        $this->pause($card, CardPauseKind::Retries, 'tech-design-write');

        $this->assertRefused($card, Uuid::v7(), ReleaseWorkflowPauseHandler::PAUSE_CHANGED);
    }

    public function test_a_rule_pause_is_refused(): void
    {
        $card = $this->card();
        $pause = $this->pause($card, CardPauseKind::Rule, 'tech-design-write');

        $this->assertRefused($card, $pause->id, ReleaseWorkflowPauseHandler::KIND_NOT_RELEASABLE);
    }

    private function assertRefused(Card $card, ?Uuid $pauseId, string $refusal, int $rows = 0): void
    {
        $active = $this->service(CardPauseRepository::class)->findActiveForCard($card);
        $this->transport()->reset();

        try {
            $this->handler()(new ReleaseWorkflowPauseCommand($card, $this->project->owner, CardReporter::Human, $pauseId));
            self::fail('The release is refused.');
        } catch (DomainErrors $e) {
            self::assertSame(['pause' => $refusal], $e->errors);
        }

        self::assertSame($active?->id?->toRfc4122(), $this->service(CardPauseRepository::class)->findActiveForCard($card)?->id?->toRfc4122());
        self::assertCount($rows, $this->releasedRows($card));
        self::assertSame([], $this->queued());
    }

    private function handler(): ReleaseWorkflowPauseHandler
    {
        return $this->service(ReleaseWorkflowPauseHandler::class);
    }

    private function pause(Card $card, CardPauseKind $kind, string $ruleId): CardPause
    {
        return $this->service(PauseCardHandler::class)(new PauseCardCommand($card, 'move-refused', $ruleId, $kind))
            ?? throw new \LogicException('The card had no pause.');
    }

    private function spentState(Card $card, string $ruleId): WorkflowRuleState
    {
        $state = new WorkflowRuleState($card, $this->project, $ruleId);
        $state->truth = true;
        $state->attempts = 3;
        $state->fires = 2;
        $state->dueAt = new \DateTimeImmutable('2026-10-02 13:00');
        $state->lastRefusal = 'move-refused';
        $state->lastRefusalAt = new \DateTimeImmutable('2026-10-02 12:00');
        $this->em()->persist($state);
        $this->em()->flush();

        return $state;
    }

    /** @return list<array<string, mixed>> */
    private function releasedRows(Card $card): array
    {
        return $this->em()->getConnection()->fetchAllAssociative(
            "SELECT actor_kind, actor_user_id, detail FROM board_card_events WHERE card_id = ? AND kind = 'pause-released'",
            [(string) $card->id],
        );
    }

    /** @return list<EvaluateCard> */
    private function queued(): array
    {
        $evaluations = [];
        foreach ($this->transport()->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof EvaluateCard) {
                $evaluations[] = $envelope->getMessage();
            }
        }

        return $evaluations;
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function card(): Card
    {
        return $this->service(CreateCardHandler::class)(new CreateCardCommand(
            project: $this->project,
            title: 'Card',
            body: 'Body',
            type: 'feature',
            column: $this->column($this->project, 'tech-design'),
            reporter: CardReporter::Human,
        ));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
