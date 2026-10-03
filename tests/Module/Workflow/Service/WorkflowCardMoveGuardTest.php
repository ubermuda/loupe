<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Service\CardEventCause;
use App\Module\Board\Service\CardMoveGuard;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Engine\EngineSwitch;
use App\Module\Workflow\Service\FactsBuilder;
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

    public function test_with_the_engine_off_every_move_is_allowed_and_a_person_releases_the_hold(): void
    {
        $this->bindLifecycle($this->project);
        $card = $this->card('next');
        $guard = $this->guard(false);

        self::assertTrue($guard->allows($card, $this->column($this->project, 'in-progress'), CardReporter::Human, null));
        self::assertTrue($guard->releasesHoldOnMove());
    }

    public function test_with_the_engine_on_a_person_keeps_the_hold(): void
    {
        self::assertFalse($this->guard(true)->releasesHoldOnMove());
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

        self::assertFalse($this->guard(true)->allows($card, $this->column($this->project, 'in-progress'), $actor, null));
    }

    public function test_the_app_itself_may_make_any_move(): void
    {
        $this->bindLifecycle($this->project);
        $card = $this->card('next');

        self::assertTrue($this->guard(true)->allows($card, $this->column($this->project, 'in-progress'), CardReporter::System, null));
    }

    public function test_a_held_card_is_unmanaged_and_moves_anywhere(): void
    {
        $this->bindLifecycle($this->project);
        $card = $this->card('next');
        $this->holds()->hold($this->project, $this->idOf($card), null);

        self::assertTrue($this->guard(true)->allows($card, $this->column($this->project, 'in-progress'), CardReporter::Human, null));
    }

    public function test_a_project_with_no_template_manages_no_card(): void
    {
        $card = $this->card('next');

        self::assertTrue($this->guard(true)->allows($card, $this->column($this->project, 'in-progress'), CardReporter::Human, null));
    }

    public function test_a_move_to_the_column_the_card_is_in_is_allowed(): void
    {
        $this->bindLifecycle($this->project);
        $card = $this->card('in-progress');

        self::assertTrue($this->guard(true)->allows($card, $this->column($this->project, 'in-progress'), CardReporter::Human, null));
    }

    public function test_a_breakdown_worker_run_may_move_its_card(): void
    {
        $this->bindLifecycle($this->project);
        $card = $this->card('next');
        $guard = $this->guard(true);
        $target = $this->column($this->project, 'in-progress');

        self::assertTrue($guard->allows($card, $target, CardReporter::Agent, $this->runCause($card, 'work:breakdown')));
        self::assertFalse($guard->allows($card, $target, CardReporter::Agent, $this->runCause($card, 'work:implement')));
        self::assertFalse($guard->allows($card, $target, CardReporter::Agent, CardEventCause::workflowRule('work:breakdown')));
    }

    public function test_a_run_that_only_carries_the_breakdown_name_is_refused(): void
    {
        $this->bindLifecycle($this->project);
        $card = $this->card('next');
        $guard = $this->guard(true);
        $target = $this->column($this->project, 'in-progress');
        $elsewhere = $this->workflowProject('move-guard-elsewhere');

        self::assertFalse($guard->allows($card, $target, CardReporter::Agent, $this->runCause($card, 'work:breakdown', WorkerRunKind::Interactive)), 'an interactive run takes any name');
        self::assertFalse($guard->allows($card, $target, CardReporter::Agent, $this->runCause($card, 'work:breakdown', project: $elsewhere)), 'a run of another project');
        self::assertFalse($guard->allows($card, $target, CardReporter::Agent, CardEventCause::run(Uuid::v7(), 'work:breakdown')), 'no stored run');
    }

    private function runCause(Card $card, string $rule, WorkerRunKind $kind = WorkerRunKind::Worker, ?Project $project = null): CardEventCause
    {
        $run = new WorkerRun(
            project: $project ?? $this->project,
            bridgeId: Uuid::v7(),
            cardId: $this->idOf($card),
            cardNumber: $card->number,
            ruleName: $rule,
            state: WorkerRunState::Running,
            kind: $kind,
        );
        $this->em()->persist($run);
        $this->em()->flush();

        return CardEventCause::run($run->id ?? throw new \LogicException('A stored run has an id.'), $run->ruleName);
    }

    public function test_a_manual_move_of_the_template_is_allowed_in_its_direction_only(): void
    {
        $this->bindLifecycle($this->project);
        $guard = $this->guard(true);

        self::assertTrue($guard->allows($this->card('backlog'), $this->column($this->project, 'next'), CardReporter::Human, null));
        self::assertTrue($guard->allows($this->card('next'), $this->column($this->project, 'tech-design'), CardReporter::Human, null));
        self::assertFalse($guard->allows($this->card('tech-design'), $this->column($this->project, 'next'), CardReporter::Human, null));
        self::assertFalse($guard->allows($this->card('next'), $this->column($this->project, 'done'), CardReporter::Human, null));
    }

    public function test_a_wildcard_manual_move_matches_any_column(): void
    {
        $this->bindHandler()(new BindWorkflowTemplateCommand($this->project, 'simple', []));
        $guard = $this->guard(true);

        self::assertTrue($guard->allows($this->card('next'), $this->column($this->project, 'done'), CardReporter::Human, null));
        self::assertTrue($guard->allows($this->card('backlog'), $this->column($this->project, 'in-progress'), CardReporter::Human, null));
    }

    private function guard(bool $engineOn): WorkflowCardMoveGuard
    {
        $holds = $this->holds();
        $templates = self::getContainer()->get(TemplateSource::class);
        self::assertInstanceOf(TemplateSource::class, $templates);
        $facts = self::getContainer()->get(FactsBuilder::class);
        self::assertInstanceOf(FactsBuilder::class, $facts);

        $workerRuns = self::getContainer()->get(WorkerRunRepository::class);
        self::assertInstanceOf(WorkerRunRepository::class, $workerRuns);

        return new WorkflowCardMoveGuard(new EngineSwitch($engineOn), $holds, $templates, $facts, $workerRuns);
    }

    private function holds(): CardHolds
    {
        $holds = self::getContainer()->get(CardHolds::class);
        self::assertInstanceOf(CardHolds::class, $holds);

        return $holds;
    }

    private function card(string $column): Card
    {
        $create = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);

        return $create(new CreateCardCommand(
            project: $this->project,
            title: 'Card',
            body: 'Body',
            type: CardType::Feature,
            column: $this->column($this->project, $column),
            reporter: CardReporter::Human,
        ));
    }

    private function idOf(Card $card): Uuid
    {
        return $card->id ?? throw new \LogicException('A created card has an id.');
    }
}
