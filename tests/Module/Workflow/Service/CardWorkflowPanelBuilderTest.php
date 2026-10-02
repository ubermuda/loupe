<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Engine\EngineSwitch;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Service\CardWorkflowPanelBuilder;
use App\Module\Workflow\Service\FactsBuilder;
use App\Module\Workflow\Template\TemplateSource;
use App\Module\Workflow\View\CardManagement;
use App\Tests\Module\Workflow\WorkflowProjects;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CardWorkflowPanelBuilderTest extends KernelTestCase
{
    use WorkflowProjects;

    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('workflow-panel');
        $this->bindLifecycle($this->project);
    }

    public function test_with_the_engine_on_the_panel_shows_the_slot_the_wait_the_next_action_and_the_last_refusal(): void
    {
        $card = $this->card('tech-design');
        $this->refusal($card, 'tech-design-approved', 'move-refused', '2026-10-02 09:00', 2);
        $this->refusal($card, 'tech-design-write', 'invalid-work-request', '2026-10-02 08:00', 1);

        $panel = $this->builder(true)->build($card);

        self::assertSame(CardManagement::Managed, $panel->management);
        self::assertNull($panel->pause);
        $progress = $panel->progress ?? self::fail('The engine is on, so the panel shows the progress.');
        self::assertSame('Tech design', $progress->slot);
        self::assertSame('Waiting: no design document is approved.', $progress->waiting);
        self::assertSame('Move the card to Implementation', $progress->nextAction);
        $refusal = $progress->lastRefusal ?? self::fail('The card has a refusal.');
        self::assertSame('move-refused', $refusal->code);
        self::assertSame('The board refused the move.', $refusal->reason);
        self::assertSame(2, $refusal->attempts);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:00'), $refusal->at);
    }

    public function test_a_slot_with_no_rule_of_its_own_shows_the_first_global_move_rule(): void
    {
        $card = $this->card('next');

        $progress = $this->builder(true)->build($card)->progress ?? self::fail('The engine is on.');

        self::assertSame('Next', $progress->slot);
        self::assertSame('Waiting: a pull request is still open, or none is merged.', $progress->waiting);
        self::assertSame('Move the card to a terminal column', $progress->nextAction);
        self::assertNull($progress->lastRefusal);
    }

    public function test_with_the_engine_off_the_panel_shows_no_progress(): void
    {
        self::assertNull($this->builder(false)->build($this->card('tech-design'))->progress);
    }

    public function test_a_held_card_is_unmanaged_and_shows_no_progress(): void
    {
        $card = $this->card('tech-design');
        $holds = self::getContainer()->get(CardHolds::class);
        self::assertInstanceOf(CardHolds::class, $holds);
        $holds->hold($this->project, $card->id ?? throw new \LogicException('A created card has an id.'), null);

        $panel = $this->builder(true)->build($card);

        self::assertSame(CardManagement::Unmanaged, $panel->management);
        self::assertNull($panel->progress);
    }

    public function test_a_retries_pause_shows_its_kind_its_reason_and_its_release_condition(): void
    {
        $card = $this->card('tech-design');
        $pause = self::getContainer()->get(PauseCardHandler::class);
        self::assertInstanceOf(PauseCardHandler::class, $pause);
        $pause(new PauseCardCommand($card, 'move-refused', 'tech-design-approved', CardPauseKind::Retries));

        $shown = $this->builder(true)->build($card)->pause ?? self::fail('The card is paused.');

        self::assertSame('move-refused', $shown->code);
        self::assertSame('too many attempts were refused', $shown->kind);
        self::assertSame('The board refused the move.', $shown->reason);
        self::assertSame('The pause ends when the facts that the rule reads change.', $shown->release);
    }

    public function test_a_template_that_cannot_be_read_degrades_to_the_managed_state(): void
    {
        $card = $this->card('tech-design');
        $templates = $this->createStub(TemplateSource::class);
        $templates->method('forProject')->willThrowException(new \RuntimeException('broken'));

        $panel = $this->builder(true, $templates)->build($card);

        self::assertSame(CardManagement::Managed, $panel->management);
        self::assertNull($panel->progress);
    }

    private function builder(bool $engineOn, ?TemplateSource $templates = null): CardWorkflowPanelBuilder
    {
        return new CardWorkflowPanelBuilder(
            new EngineSwitch($engineOn),
            $this->service(CardHolds::class),
            $this->service(CardPauseRepository::class),
            $templates ?? $this->service(TemplateSource::class),
            $this->service(FactsBuilder::class),
            $this->service(WorkflowRuleStateRepository::class),
            $this->service(TranslatorInterface::class),
            new MockClock('2026-10-02 12:00'),
            new NullLogger(),
        );
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

    private function refusal(Card $card, string $ruleId, string $code, string $at, int $attempts): void
    {
        $state = new WorkflowRuleState($card, $this->project, $ruleId);
        $state->truth = true;
        $state->lastRefusal = $code;
        $state->lastRefusalAt = new \DateTimeImmutable($at);
        $state->attempts = $attempts;
        $this->em()->persist($state);
        $this->em()->flush();
    }

    private function card(string $column): Card
    {
        return $this->service(CreateCardHandler::class)(new CreateCardCommand(
            project: $this->project,
            title: 'Card',
            body: 'Body',
            type: CardType::Feature,
            column: $this->column($this->project, $column),
            reporter: CardReporter::Human,
        ));
    }
}
