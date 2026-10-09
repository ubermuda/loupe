<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Board\Service\BoardAutomation;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardPauses;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Contract\WorkLedger;
use App\Module\Workflow\Engine\RuleSubject;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Service\CardWorkflowPanelBuilder;
use App\Module\Workflow\Service\FactsBuilder;
use App\Module\Workflow\Service\WorkflowAutomation;
use App\Module\Workflow\Template\TemplateSource;
use App\Tests\Module\Workflow\Fact\ProvidedFactsProvider;
use App\Tests\Module\Workflow\Fact\ProvidedFactsReady;
use App\Tests\Module\Workflow\Fact\UnprovidedFactsReady;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\RecordingLogger;
use Psr\Log\LoggerInterface;
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

        $panel = $this->builder()->build($card->snapshot());

        self::assertNull($panel->pause);
        $progress = $panel->progress ?? self::fail('The automation is on, so the panel shows the progress.');
        self::assertSame('Tech design', $progress->slot);
        self::assertSame('Waiting: no tech-design document is approved.', $progress->waiting);
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

        $refusal = ($this->builder()->build($card->snapshot())->progress ?? self::fail('The automation is on.'))->lastRefusal ?? self::fail('The card has a refusal.');

        self::assertSame($text, $refusal->reason);
    }

    public function test_a_slot_with_no_rule_of_its_own_shows_the_first_global_move_rule(): void
    {
        $card = $this->card('backlog');

        $progress = $this->builder()->build($card->snapshot())->progress ?? self::fail('The automation is on.');

        self::assertSame('Backlog', $progress->slot);
        self::assertSame('Waiting: the pull request is not open.', $progress->waiting);
        self::assertSame('Move the card to Implementation', $progress->nextAction);
        self::assertNull($progress->lastRefusal);
    }

    public function test_with_the_board_automation_off_a_card_with_no_pause_has_an_empty_panel(): void
    {
        $card = $this->card('tech-design');
        $this->service(BoardAutomation::class)->settingsForUpdate($this->project)->enabled = false;
        $this->em()->flush();

        self::assertTrue($this->builder()->build($card->snapshot())->isEmpty());
    }

    public function test_a_held_card_shows_no_progress(): void
    {
        $card = $this->card('tech-design');
        $holds = self::getContainer()->get(CardHolds::class);
        self::assertInstanceOf(CardHolds::class, $holds);
        $holds->hold($this->project, $card->id ?? throw new \LogicException('A created card has an id.'), null);

        $panel = $this->builder()->build($card->snapshot());

        self::assertTrue($panel->isEmpty());
    }

    public function test_a_retries_pause_shows_its_kind_its_reason_and_its_release_condition(): void
    {
        $card = $this->card('tech-design');
        $pause = self::getContainer()->get(PauseCardHandler::class);
        self::assertInstanceOf(PauseCardHandler::class, $pause);
        $pause(new PauseCardCommand($card, 'move-refused', 'tech-design-approved', PauseKind::Retries));

        $shown = $this->builder()->build($card->snapshot())->pause ?? self::fail('The card is paused.');

        self::assertSame('move-refused', $shown->code);
        self::assertSame('too many attempts were refused', $shown->kind);
        self::assertSame('The board refused the move.', $shown->reason);
        self::assertSame('The pause ends when the facts that the rule reads change.', $shown->release);
    }

    public function test_a_retries_pause_of_a_managed_card_is_releasable_and_names_its_id(): void
    {
        $card = $this->card('tech-design');
        $pause = $this->service(PauseCardHandler::class)(new PauseCardCommand($card, 'move-refused', 'tech-design-approved', PauseKind::Retries));

        $shown = $this->builder()->build($card->snapshot())->pause ?? self::fail('The card is paused.');

        self::assertSame((string) $pause?->id, $shown->id);
        self::assertTrue($shown->releasable);
    }

    public function test_a_rule_pause_is_not_releasable(): void
    {
        $card = $this->card('tech-design');
        $this->service(PauseCardHandler::class)(new PauseCardCommand($card, 'on-hold', 'tech-design-approved', PauseKind::Rule));

        self::assertFalse(($this->builder()->build($card->snapshot())->pause ?? self::fail('The card is paused.'))->releasable);
    }

    public function test_a_pause_of_a_held_card_is_not_releasable(): void
    {
        $card = $this->card('tech-design');
        $this->service(PauseCardHandler::class)(new PauseCardCommand($card, 'move-refused', 'tech-design-approved', PauseKind::Retries));
        $this->service(CardHolds::class)->hold($this->project, $card->id ?? throw new \LogicException('A created card has an id.'), null);

        self::assertFalse(($this->builder()->build($card->snapshot())->pause ?? self::fail('The card is paused.'))->releasable);
    }

    public function test_a_template_that_cannot_be_read_still_shows_the_pause(): void
    {
        $card = $this->card('tech-design');
        $pause = self::getContainer()->get(PauseCardHandler::class);
        self::assertInstanceOf(PauseCardHandler::class, $pause);
        $pause(new PauseCardCommand($card, 'move-refused', 'tech-design-approved', PauseKind::Retries));
        $templates = $this->createStub(TemplateSource::class);
        $templates->method('forProject')->willThrowException(new \RuntimeException('broken'));

        $panel = $this->builder($templates)->build($card->snapshot());

        self::assertSame('move-refused', $panel->pause?->code);
        self::assertNull($panel->progress);
    }

    public function test_a_rule_that_cannot_read_its_facts_blocks_with_its_reason_and_logs_nothing(): void
    {
        $card = $this->card('tech-design');
        $binding = $this->service(WorkflowBindingRepository::class)->findOneByProjectId($this->project->id ?? throw new \LogicException('A flushed project has an id.'));
        self::assertNotNull($binding);
        $binding->definition = [
            'key' => 'test',
            'version' => 1,
            'defaultType' => 'feature',
            'types' => [
                ['key' => 'feature', 'label' => 'board.card.type.feature', 'tone' => 'lime'],
                ['key' => 'bug', 'label' => 'board.card.type.bug', 'tone' => 'amber'],
                ['key' => 'security', 'label' => 'board.card.type.security', 'tone' => 'red'],
                ['key' => 'tooling', 'label' => 'board.card.type.tooling', 'tone' => 'neutral'],
                ['key' => 'docs', 'label' => 'board.card.type.docs', 'tone' => 'green'],
                ['key' => 'idea', 'label' => 'board.card.type.idea', 'tone' => 'purple'],
                ['key' => 'epic', 'label' => 'board.card.type.epic', 'tone' => 'blue', 'capabilities' => ['children', 'lane']],
            ],
            'slots' => [['key' => 'tech-design', 'label' => 'workflow.slot.tech_design'], ['key' => 'implementation', 'label' => 'workflow.slot.implementation']],
            'manualMoves' => [],
            'backoffMinutes' => [10],
            'workTimeoutMinutes' => 120,
            'rules' => [
                ['id' => 'missing', 'slot' => 'tech-design', 'when' => ['card.gone' => []], 'then' => ['request' => ['kind' => 'gone']]],
                ['id' => 'unprovided', 'slot' => 'tech-design', 'when' => [UnprovidedFactsReady::KEY => []], 'then' => ['request' => ['kind' => 'unprovided']]],
                ['id' => 'provided', 'slot' => 'tech-design', 'when' => [ProvidedFactsReady::KEY => []], 'then' => ['move' => ['to' => 'implementation']]],
                ['id' => 'hold', 'slot' => 'tech-design', 'when' => ['all' => []], 'then' => ['pause' => ['reason' => 'on-hold', 'until' => [ProvidedFactsReady::KEY => []]]]],
            ],
        ];
        $this->em()->flush();
        $this->service(PauseCardHandler::class)(new PauseCardCommand($card, 'on-hold', 'hold', PauseKind::Rule));
        $this->service(ProvidedFactsProvider::class)->failure = new \RuntimeException('The source is down.');
        $logger = new RecordingLogger();

        $panel = $this->builder(logger: $logger)->build($card->snapshot());

        $progress = $panel->progress ?? self::fail('The automation is on, so the panel shows the progress.');
        self::assertSame('Tech design', $progress->slot);
        self::assertSame('Waiting: could not read Board.', $progress->waiting);
        self::assertSame('Move the card to Implementation', $progress->nextAction);
        self::assertSame('The workflow ends the pause when it next evaluates the card.', $panel->pause?->release);
        self::assertSame([], $logger->records);
    }

    public function test_a_pause_rule_whose_until_cannot_be_read_blocks(): void
    {
        $card = $this->card('tech-design');
        $binding = $this->service(WorkflowBindingRepository::class)->findOneByProjectId($this->project->id ?? throw new \LogicException('A flushed project has an id.'));
        self::assertNotNull($binding);
        $binding->definition = [
            'key' => 'test',
            'version' => 1,
            'defaultType' => 'feature',
            'types' => [
                ['key' => 'feature', 'label' => 'board.card.type.feature', 'tone' => 'lime'],
                ['key' => 'bug', 'label' => 'board.card.type.bug', 'tone' => 'amber'],
                ['key' => 'security', 'label' => 'board.card.type.security', 'tone' => 'red'],
                ['key' => 'tooling', 'label' => 'board.card.type.tooling', 'tone' => 'neutral'],
                ['key' => 'docs', 'label' => 'board.card.type.docs', 'tone' => 'green'],
                ['key' => 'idea', 'label' => 'board.card.type.idea', 'tone' => 'purple'],
                ['key' => 'epic', 'label' => 'board.card.type.epic', 'tone' => 'blue', 'capabilities' => ['children', 'lane']],
            ],
            'slots' => [['key' => 'tech-design', 'label' => 'workflow.slot.tech_design']],
            'manualMoves' => [],
            'backoffMinutes' => [10],
            'workTimeoutMinutes' => 120,
            'rules' => [
                ['id' => 'hold', 'slot' => 'tech-design', 'when' => ['all' => []], 'then' => ['pause' => ['reason' => 'on-hold', 'until' => [ProvidedFactsReady::KEY => []]]]],
            ],
        ];
        $this->em()->flush();
        $this->service(ProvidedFactsProvider::class)->failure = new \RuntimeException('The source is down.');

        $progress = $this->builder()->build($card->snapshot())->progress ?? self::fail('The automation is on, so the panel shows the progress.');

        self::assertSame('Waiting: could not read Board.', $progress->waiting);
        self::assertSame('Pause the card', $progress->nextAction);
    }

    public function test_a_rule_whose_source_is_off_waits_for_the_source(): void
    {
        $card = $this->card('tech-design');
        $this->define([
            ['id' => 'provided', 'slot' => 'tech-design', 'when' => [ProvidedFactsReady::KEY => []], 'then' => ['move' => ['to' => 'implementation']]],
        ]);
        $this->service(ProvidedFactsProvider::class)->on = false;
        $logger = new RecordingLogger();

        $progress = $this->builder(logger: $logger)->build($card->snapshot())->progress ?? self::fail('The automation is on, so the panel shows the progress.');

        self::assertSame('Waiting: Board is off on this instance.', $progress->waiting);
        self::assertSame('Move the card to Implementation', $progress->nextAction);
        self::assertSame([], $logger->records);
    }

    public function test_a_rule_whose_condition_no_longer_exists_names_the_condition(): void
    {
        $card = $this->card('tech-design');
        $this->define([
            ['id' => 'missing', 'slot' => 'tech-design', 'when' => ['all' => [['card.gone' => []]]], 'then' => ['move' => ['to' => 'implementation']]],
        ]);

        $progress = $this->builder()->build($card->snapshot())->progress ?? self::fail('The automation is on, so the panel shows the progress.');

        self::assertSame('Waiting: the condition card.gone no longer exists.', $progress->waiting);
        self::assertSame('Move the card to Implementation', $progress->nextAction);
    }

    public function test_a_built_in_rule_beside_an_unreadable_rule_shows_its_own_waiting_sentence(): void
    {
        $card = $this->card('tech-design');
        $this->define([
            ['id' => 'provided', 'slot' => 'tech-design', 'when' => [ProvidedFactsReady::KEY => []], 'then' => ['request' => ['kind' => 'provided']]],
            ['id' => 'approved', 'slot' => 'tech-design', 'when' => ['card.document_approved' => ['tag' => 'design']], 'then' => ['move' => ['to' => 'implementation']]],
        ]);
        $this->service(ProvidedFactsProvider::class)->failure = new \RuntimeException('The source is down.');

        $progress = $this->builder()->build($card->snapshot())->progress ?? self::fail('The automation is on, so the panel shows the progress.');

        self::assertSame('Waiting: no design document is approved.', $progress->waiting);
        self::assertSame('Move the card to Implementation', $progress->nextAction);
    }

    public function test_a_pause_rule_whose_when_is_false_shows_the_when_leaf_even_when_its_until_cannot_be_read(): void
    {
        $card = $this->card('tech-design');
        $this->define([
            ['id' => 'hold', 'slot' => 'tech-design', 'when' => ['card.document_approved' => ['tag' => 'design']], 'then' => ['pause' => ['reason' => 'on-hold', 'until' => [ProvidedFactsReady::KEY => []]]]],
        ]);
        $this->service(ProvidedFactsProvider::class)->failure = new \RuntimeException('The source is down.');

        $progress = $this->builder()->build($card->snapshot())->progress ?? self::fail('The automation is on, so the panel shows the progress.');

        self::assertSame('Waiting: no design document is approved.', $progress->waiting);
        self::assertSame('Pause the card', $progress->nextAction);
    }

    public function test_a_rule_pause_shows_its_until_for_the_pull_request_it_paused(): void
    {
        $this->define([
            ['id' => 'hold', 'slot' => 'tech-design', 'when' => ['all' => [['pr.open' => []], ['pr.checks_failed' => []]]], 'then' => ['pause' => ['reason' => 'red', 'until' => ['pr.checks_passed' => []]]]],
        ]);
        $card = $this->card('tech-design');
        $base = $this->linkedPullRequest($card, 4, 'main', 'base-branch', PullRequestChecks::Passed, '2026-10-01 09:00');
        $this->linkedPullRequest($card, 5, 'base-branch', 'upper-branch', PullRequestChecks::Failed, '2026-10-01 10:00');
        $this->service(PauseCardHandler::class)(new PauseCardCommand($card, 'red', 'hold', PauseKind::Rule));
        $state = new WorkflowRuleState($card->id ?? throw new \LogicException('The card is persisted.'), $this->project, 'hold');
        $state->truth = true;
        $state->subjectPullRequestId = $base->id;
        $this->em()->persist($state);
        $this->em()->flush();

        $pause = $this->builder()->build($card->snapshot())->pause ?? self::fail('The card is paused.');

        self::assertSame($this->service(TranslatorInterface::class)->trans('workflow.panel.release.met'), $pause->release);
    }

    /** @param list<array<string, mixed>> $rules */
    private function define(array $rules): void
    {
        $binding = $this->service(WorkflowBindingRepository::class)->findOneByProjectId($this->project->id ?? throw new \LogicException('A flushed project has an id.'));
        self::assertNotNull($binding);
        $binding->definition = [
            'key' => 'test',
            'version' => 1,
            'defaultType' => 'feature',
            'types' => [
                ['key' => 'feature', 'label' => 'board.card.type.feature', 'tone' => 'lime'],
                ['key' => 'bug', 'label' => 'board.card.type.bug', 'tone' => 'amber'],
                ['key' => 'security', 'label' => 'board.card.type.security', 'tone' => 'red'],
                ['key' => 'tooling', 'label' => 'board.card.type.tooling', 'tone' => 'neutral'],
                ['key' => 'docs', 'label' => 'board.card.type.docs', 'tone' => 'green'],
                ['key' => 'idea', 'label' => 'board.card.type.idea', 'tone' => 'purple'],
                ['key' => 'epic', 'label' => 'board.card.type.epic', 'tone' => 'blue', 'capabilities' => ['children', 'lane']],
            ],
            'slots' => [['key' => 'tech-design', 'label' => 'workflow.slot.tech_design'], ['key' => 'implementation', 'label' => 'workflow.slot.implementation']],
            'manualMoves' => [],
            'backoffMinutes' => [10],
            'workTimeoutMinutes' => 120,
            'rules' => $rules,
        ];
        $this->em()->flush();
    }

    private function builder(?TemplateSource $templates = null, LoggerInterface $logger = new NullLogger()): CardWorkflowPanelBuilder
    {
        return new CardWorkflowPanelBuilder(
            $this->service(WorkflowAutomation::class),
            $this->service(WorkLedger::class),
            $this->service(CardPauses::class),
            $this->service(ProjectRepository::class),
            $templates ?? $this->service(TemplateSource::class),
            $this->service(FactsBuilder::class),
            $this->service(WorkflowRuleStateRepository::class),
            $this->service(TranslatorInterface::class),
            new MockClock('2026-10-02 12:00'),
            $logger,
            new RuleSubject(),
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
        $state = new WorkflowRuleState($card->id ?? throw new \LogicException('The card is persisted.'), $this->project, $ruleId);
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
            type: 'feature',
            column: $this->column($this->project, $column),
            reporter: Actor::Human,
        ));
    }

    private function linkedPullRequest(Card $card, int $number, string $base, string $head, PullRequestChecks $checks, string $openedAt): ForgePullRequest
    {
        $this->em()->persist(new CardPullRequest($card, 'https://github.com/acme/widgets/pull/'.$number, Forge::GitHub, 'acme/widgets', $number));
        $pullRequest = new ForgePullRequest($card->project, 'github', 'acme/widgets', $number);
        $pullRequest->state = PullRequestState::Open;
        $pullRequest->baseBranch = $base;
        $pullRequest->headBranch = $head;
        $pullRequest->defaultBranch = 'main';
        $pullRequest->checks = $checks;
        $pullRequest->openedAt = new \DateTimeImmutable($openedAt);
        $this->em()->persist($pullRequest);
        $this->em()->flush();

        return $pullRequest;
    }
}
