<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Board\Service\CardEventCause;
use App\Module\Board\Service\CardMoveGuard;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Service\FactsBuilder;
use App\Module\Workflow\Service\WorkflowAutomation;
use App\Module\Workflow\Service\WorkflowCardMoveGuard;
use App\Module\Workflow\Template\TemplateSource;
use App\Tests\Module\Workflow\WorkflowProjects;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WorkflowCardMoveGuardTest extends KernelTestCase
{
    use WorkflowProjects;

    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->project = $this->workflowProject('move-guard');
    }

    public function test_the_container_wires_the_workflow_guard_to_the_board_port(): void
    {
        self::assertInstanceOf(WorkflowCardMoveGuard::class, self::getContainer()->get(CardMoveGuard::class));
    }

    public function test_with_the_board_automation_off_every_move_is_allowed(): void
    {
        $this->bindLifecycle($this->project);
        $card = $this->card('next');
        $this->boardAutomation()->settingsForUpdate($this->project)->enabled = false;
        $this->em()->flush();

        self::assertTrue($this->guard()->allows($card, $this->column($this->project, 'in-progress'), CardReporter::Human, null));
    }

    /** @return iterable<string, array{CardReporter}> */
    public static function refusedActors(): iterable
    {
        yield 'human' => [CardReporter::Human];
        yield 'agent' => [CardReporter::Agent];
        yield 'reviewer' => [CardReporter::Reviewer];
    }

    #[DataProvider('refusedActors')]
    public function test_a_move_the_template_does_not_list_is_refused(CardReporter $actor): void
    {
        $this->bindLifecycle($this->project);
        $card = $this->card('next');

        self::assertFalse($this->guard()->allows($card, $this->column($this->project, 'in-progress'), $actor, null));
    }

    public function test_the_app_itself_may_make_any_move(): void
    {
        $this->bindLifecycle($this->project);
        $card = $this->card('next');

        self::assertTrue($this->guard()->allows($card, $this->column($this->project, 'in-progress'), CardReporter::System, null));
    }

    public function test_a_held_card_is_unmanaged_and_moves_anywhere(): void
    {
        $this->bindLifecycle($this->project);
        $card = $this->card('next');
        $this->holds()->hold($this->project, $this->idOf($card), null);

        self::assertTrue($this->guard()->allows($card, $this->column($this->project, 'in-progress'), CardReporter::Human, null));
    }

    public function test_a_project_with_no_template_manages_no_card(): void
    {
        $card = $this->card('next');

        self::assertTrue($this->guard()->allows($card, $this->column($this->project, 'in-progress'), CardReporter::Human, null));
    }

    public function test_a_move_to_the_column_the_card_is_in_is_allowed(): void
    {
        $this->bindLifecycle($this->project);
        $card = $this->card('in-progress');

        self::assertTrue($this->guard()->allows($card, $this->column($this->project, 'in-progress'), CardReporter::Human, null));
    }

    /** @return iterable<string, array{string}> */
    public static function parentRunKinds(): iterable
    {
        yield 'breakdown' => ['breakdown'];
        yield 'implement' => ['implement'];
    }

    #[DataProvider('parentRunKinds')]
    public function test_a_worker_run_of_the_parent_epic_may_move_its_child_from_the_backlog_to_implementation(string $workKind): void
    {
        $this->bindLifecycle($this->project);
        $epic = $this->card('in-progress', CardType::Epic);
        $child = $this->card('backlog', parent: $epic);

        self::assertTrue($this->guard()->allows($child, $this->column($this->project, 'in-progress'), CardReporter::Agent, $this->runCause($epic, $workKind)));
    }

    public function test_a_run_of_the_parent_epic_may_make_only_the_move_the_template_names(): void
    {
        $this->bindLifecycle($this->project);
        $epic = $this->card('in-progress', CardType::Epic);
        $guard = $this->guard();
        $cause = $this->runCause($epic, 'breakdown');

        self::assertFalse($guard->allows($this->card('backlog', parent: $epic), $this->column($this->project, 'in-review'), CardReporter::Agent, $cause), 'a column the entry does not name');
        self::assertFalse($guard->allows($this->card('next', parent: $epic), $this->column($this->project, 'in-progress'), CardReporter::Agent, $cause), 'a source the entry does not name');
    }

    public function test_only_a_stored_worker_run_of_the_parent_matches_a_parent_run_move(): void
    {
        $this->bindLifecycle($this->project);
        $epic = $this->card('in-progress', CardType::Epic);
        $child = $this->card('backlog', parent: $epic);
        $orphan = $this->card('backlog');
        $guard = $this->guard();
        $target = $this->column($this->project, 'in-progress');
        $elsewhere = $this->workflowProject('move-guard-elsewhere');

        self::assertFalse($guard->allows($child, $target, CardReporter::Human, null), 'a person');
        self::assertFalse($guard->allows($child, $target, CardReporter::Agent, CardEventCause::workflowRule('breakdown')), 'a rule');
        self::assertFalse($guard->allows($child, $target, CardReporter::Agent, $this->runCause($epic, 'breakdown', WorkerRunKind::Interactive)), 'an interactive run');
        self::assertFalse($guard->allows($child, $target, CardReporter::Agent, $this->runCause($epic, 'breakdown', project: $elsewhere)), 'a run of another project');
        self::assertFalse($guard->allows($child, $target, CardReporter::Agent, CardEventCause::run(Uuid::v7(), 'breakdown')), 'no stored run');
        self::assertFalse($guard->allows($child, $target, CardReporter::Agent, $this->runCause($orphan, 'breakdown')), 'a run of a card that is not the parent');
        self::assertFalse($guard->allows($orphan, $target, CardReporter::Agent, $this->runCause($orphan, 'breakdown')), 'a card with no parent');
        self::assertFalse($guard->allows($child, $target, CardReporter::Agent, $this->runCause($epic, 'breakdown', state: WorkerRunState::Succeeded)), 'a run that ended');
    }

    private function runCause(Card $card, string $rule, WorkerRunKind $kind = WorkerRunKind::Worker, ?Project $project = null, WorkerRunState $state = WorkerRunState::Running): CardEventCause
    {
        $run = new WorkerRun(
            project: $project ?? $this->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $this->idOf($card),
            cardNumber: $card->number,
            workKind: $rule,
            state: $state,
            kind: $kind,
        );
        $this->em()->persist($run);
        $this->em()->flush();

        return CardEventCause::run($run->id ?? throw new \LogicException('A stored run has an id.'), $run->workKind);
    }

    public function test_a_manual_move_of_the_template_is_allowed_in_its_direction_only(): void
    {
        $this->bindLifecycle($this->project);
        $guard = $this->guard();

        self::assertTrue($guard->allows($this->card('backlog'), $this->column($this->project, 'next'), CardReporter::Human, null));
        self::assertTrue($guard->allows($this->card('next'), $this->column($this->project, 'tech-design'), CardReporter::Human, null));
        self::assertFalse($guard->allows($this->card('tech-design'), $this->column($this->project, 'next'), CardReporter::Human, null));
        self::assertTrue($guard->allows($this->card('in-progress'), $this->column($this->project, 'tech-design'), CardReporter::Human, null));
        self::assertFalse($guard->allows($this->card('in-progress'), $this->column($this->project, 'product-design'), CardReporter::Human, null));
        self::assertFalse($guard->allows($this->card('next'), $this->column($this->project, 'done'), CardReporter::Human, null));
    }

    public function test_a_wildcard_manual_move_matches_any_column(): void
    {
        $this->bindHandler()(new BindWorkflowTemplateCommand($this->project, 'simple', []));
        $guard = $this->guard();

        self::assertTrue($guard->allows($this->card('next'), $this->column($this->project, 'done'), CardReporter::Human, null));
        self::assertTrue($guard->allows($this->card('backlog'), $this->column($this->project, 'in-progress'), CardReporter::Human, null));
    }

    private function guard(): WorkflowCardMoveGuard
    {
        $holds = $this->holds();
        $templates = self::getContainer()->get(TemplateSource::class);
        self::assertInstanceOf(TemplateSource::class, $templates);
        $facts = self::getContainer()->get(FactsBuilder::class);
        self::assertInstanceOf(FactsBuilder::class, $facts);

        $workerRuns = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $workerRuns);

        $automation = self::getContainer()->get(WorkflowAutomation::class);
        self::assertInstanceOf(WorkflowAutomation::class, $automation);

        return new WorkflowCardMoveGuard($automation, $holds, $templates, $facts, $workerRuns);
    }

    private function boardAutomation(): BoardAutomation
    {
        $automation = self::getContainer()->get(BoardAutomation::class);
        self::assertInstanceOf(BoardAutomation::class, $automation);

        return $automation;
    }

    private function holds(): CardHolds
    {
        $holds = self::getContainer()->get(CardHolds::class);
        self::assertInstanceOf(CardHolds::class, $holds);

        return $holds;
    }

    private function card(string $column, CardType $type = CardType::Feature, ?Card $parent = null): Card
    {
        $create = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);

        return $create(new CreateCardCommand(
            project: $this->project,
            title: 'Card',
            body: 'Body',
            type: $type,
            column: $this->column($this->project, $column),
            reporter: CardReporter::Human,
            parentCardId: null === $parent ? null : (string) $parent->id,
        ));
    }

    private function idOf(Card $card): Uuid
    {
        return $card->id ?? throw new \LogicException('A created card has an id.');
    }
}
