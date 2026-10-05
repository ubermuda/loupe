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
use App\Module\Board\Service\BoardAutomation;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Service\CardWorkflowPanelBuilder;
use App\Module\Workflow\Service\FactsBuilder;
use App\Module\Workflow\Service\WorkflowAutomation;
use App\Module\Workflow\Template\TemplateSource;
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

    public function test_the_panel_shows_the_slot_the_wait_the_next_action_and_the_last_refusal(): void
    {
        $card = $this->card('tech-design');
        $this->refusal($card, 'tech-design-approved', 'move-refused', '2026-10-02 09:00', 2);
        $this->refusal($card, 'tech-design-write', 'invalid-work-request', '2026-10-02 08:00', 1);

        $panel = $this->builder()->build($card);

        self::assertNull($panel->pause);
        $progress = $panel->progress ?? self::fail('The automation is on, so the panel shows the progress.');
        self::assertSame('Tech design', $progress->slot);
        self::assertSame('Waiting: no design document is approved.', $progress->waiting);
        self::assertSame('Move the card to Implementation', $progress->nextAction);
        $refusal = $progress->lastRefusal ?? self::fail('The card has a refusal.');
        self::assertSame('move-refused', $refusal->code);
        self::assertSame('The board refused the move.', $refusal->reason);
        self::assertSame(2, $refusal->attempts);
        self::assertEquals(new \DateTimeImmutable('2026-10-02 09:00'), $refusal->at);
    }

    /** @return iterable<string, array{string, string}> */
    public static function documentRefusals(): iterable
    {
        yield 'no document' => ['document-not-found', 'The card has no document with the tag that the rule names.'];
        yield 'several documents' => ['document-ambiguous', 'The card has more than one document with the tag that the rule names.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('documentRefusals')]
    public function test_a_document_refusal_has_its_own_text(string $code, string $text): void
    {
        $card = $this->card('tech-design');
        $this->refusal($card, 'tech-design-revise', $code, '2026-10-02 09:00', 1);

        $refusal = ($this->builder()->build($card)->progress ?? self::fail('The automation is on.'))->lastRefusal ?? self::fail('The card has a refusal.');

        self::assertSame($text, $refusal->reason);
    }

    public function test_a_slot_with_no_rule_of_its_own_shows_the_first_global_move_rule(): void
    {
        $card = $this->card('next');

        $progress = $this->builder()->build($card)->progress ?? self::fail('The automation is on.');

        self::assertSame('Next', $progress->slot);
        self::assertSame('Waiting: a pull request is still open, or none is merged.', $progress->waiting);
        self::assertSame('Move the card to a terminal column', $progress->nextAction);
        self::assertNull($progress->lastRefusal);
    }

    public function test_with_the_board_automation_off_a_card_with_no_pause_has_an_empty_panel(): void
    {
        $card = $this->card('tech-design');
        $this->service(BoardAutomation::class)->settingsForUpdate($this->project)->enabled = false;
        $this->em()->flush();

        self::assertTrue($this->builder()->build($card)->isEmpty());
    }

    public function test_a_held_card_shows_no_progress(): void
    {
        $card = $this->card('tech-design');
        $holds = self::getContainer()->get(CardHolds::class);
        self::assertInstanceOf(CardHolds::class, $holds);
        $holds->hold($this->project, $card->id ?? throw new \LogicException('A created card has an id.'), null);

        $panel = $this->builder()->build($card);

        self::assertTrue($panel->isEmpty());
    }

    public function test_a_retries_pause_shows_its_kind_its_reason_and_its_release_condition(): void
    {
        $card = $this->card('tech-design');
        $pause = self::getContainer()->get(PauseCardHandler::class);
        self::assertInstanceOf(PauseCardHandler::class, $pause);
        $pause(new PauseCardCommand($card, 'move-refused', 'tech-design-approved', CardPauseKind::Retries));

        $shown = $this->builder()->build($card)->pause ?? self::fail('The card is paused.');

        self::assertSame('move-refused', $shown->code);
        self::assertSame('too many attempts were refused', $shown->kind);
        self::assertSame('The board refused the move.', $shown->reason);
        self::assertSame('The pause ends when the facts that the rule reads change.', $shown->release);
    }

    public function test_a_template_that_cannot_be_read_still_shows_the_pause(): void
    {
        $card = $this->card('tech-design');
        $pause = self::getContainer()->get(PauseCardHandler::class);
        self::assertInstanceOf(PauseCardHandler::class, $pause);
        $pause(new PauseCardCommand($card, 'move-refused', 'tech-design-approved', CardPauseKind::Retries));
        $templates = $this->createStub(TemplateSource::class);
        $templates->method('forProject')->willThrowException(new \RuntimeException('broken'));

        $panel = $this->builder($templates)->build($card);

        self::assertSame('move-refused', $panel->pause?->code);
        self::assertNull($panel->progress);
    }

    private function builder(?TemplateSource $templates = null): CardWorkflowPanelBuilder
    {
        return new CardWorkflowPanelBuilder(
            $this->service(WorkflowAutomation::class),
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
