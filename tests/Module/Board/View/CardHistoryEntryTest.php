<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\View;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\View\CardHistoryEntry;
use App\Module\Board\View\CardHistoryRun;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\TranslatableMessage;

final class CardHistoryEntryTest extends TestCase
{
    private const array BACKLOG = ['id' => 'b', 'label' => 'board.column.backlog', 'slug' => 'backlog'];
    private const array REVIEW = ['id' => 'r', 'label' => 'Review', 'slug' => 'review'];
    private const string RUN_ID = '0199a0c4-8d2e-7c6b-9f1e-3a5b7c9d1e2f';

    private User $user;

    #[\Override]
    protected function setUp(): void
    {
        $this->user = new User(fullName: 'Riley Chen', email: 'riley@example.com');
    }

    public function test_a_creation_names_the_actor_and_the_column(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::Created, CardReporter::Human, $this->user, ['column' => self::BACKLOG]));

        self::assertSame('lucide:plus', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.created', [
            '%actor%' => 'Riley Chen',
            '%column%' => new TranslatableMessage('board.column.backlog'),
        ]), $entry->sentence);
        self::assertNull($entry->cause);
        self::assertNull($entry->run);
        self::assertEquals(new \DateTimeImmutable('2026-09-30 10:00'), $entry->occurredAt);
    }

    public function test_a_move_names_both_columns_and_has_no_cause_line_without_a_cause(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::Moved, CardReporter::Human, $this->user, ['from' => self::BACKLOG, 'to' => self::REVIEW, 'cause' => null]));

        self::assertSame('lucide:arrow-right', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.moved', [
            '%actor%' => 'Riley Chen',
            '%from%' => new TranslatableMessage('board.column.backlog'),
            '%to%' => new TranslatableMessage('Review'),
        ]), $entry->sentence);
        self::assertNull($entry->cause);
    }

    /** @param array<string, mixed> $cause */
    #[DataProvider('causes')]
    public function test_a_move_by_the_app_says_why(array $cause, ?TranslatableMessage $expected): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::Moved, CardReporter::System, null, ['from' => self::BACKLOG, 'to' => self::REVIEW, 'cause' => $cause]));

        self::assertSame('board.card.history.moved', $entry->sentence->getMessage());
        self::assertEquals($expected, $entry->cause);
    }

    /** @return iterable<string, array{array<string, mixed>, ?TranslatableMessage}> */
    public static function causes(): iterable
    {
        yield 'merged' => [['type' => 'merged', 'pullRequest' => 12], new TranslatableMessage('board.card.history.cause.merged', ['%pr%' => 12])];
        yield 'checks passed' => [['type' => 'checks-passed', 'pullRequest' => 12], new TranslatableMessage('board.card.history.cause.checks_passed', ['%pr%' => 12])];
        yield 'document approved' => [['type' => 'document-approved', 'document' => 'Tech design'], new TranslatableMessage('board.card.history.cause.document_approved', ['%document%' => 'Tech design'])];
        yield 'epic reconciled' => [['type' => 'epic-reconciled', 'child' => 7], new TranslatableMessage('board.card.history.cause.epic_reconciled', ['%child%' => 7])];
        yield 'unblocked' => [['type' => 'unblocked', 'blocker' => 3], new TranslatableMessage('board.card.history.cause.unblocked', ['%blocker%' => 3])];
        yield 'unblocked with no blocker' => [['type' => 'unblocked'], new TranslatableMessage('board.card.history.cause.unblocked_any')];
        yield 'abandoned' => [['type' => 'abandoned'], new TranslatableMessage('board.card.history.cause.abandoned')];
        yield 'run' => [['type' => 'run', 'run' => '01a0f000-0000-7000-8000-000000000001', 'kind' => 'implement'], new TranslatableMessage('board.card.history.cause.run', ['%kind%' => 'implement'])];
        yield 'run written with a rule name' => [['type' => 'run', 'run' => '01a0f000-0000-7000-8000-000000000001', 'rule' => 'implement'], new TranslatableMessage('board.card.history.cause.run', ['%kind%' => 'implement'])];
        yield 'run with no work kind' => [['type' => 'run', 'run' => '01a0f000-0000-7000-8000-000000000001'], new TranslatableMessage('board.card.history.cause.run_no_kind')];
        yield 'workflow rule' => [['type' => 'workflow-rule', 'rule' => 'merge-on-green'], new TranslatableMessage('board.card.history.cause.workflow_rule', ['%rule%' => 'merge-on-green'])];
        yield 'column deleted' => [['type' => 'column-deleted', 'column' => 'board.column.next'], new TranslatableMessage('board.card.history.cause.column_deleted', ['%column%' => new TranslatableMessage('board.column.next')])];
        yield 'an unknown type' => [['type' => 'moon-phase'], null];
        yield 'a cause with its field missing' => [['type' => 'merged'], null];
        yield 'a cause with no type' => [['pullRequest' => 12], null];
    }

    public function test_the_app_asking_for_a_fix_gives_the_pull_request_and_the_reason(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::FixRequested, CardReporter::System, null, ['reason' => 'checks-failed', 'pullRequest' => 42]));

        self::assertSame('lucide:wrench', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.fix_requested', [
            '%actor%' => new TranslatableMessage('board.card.history.actor.system'),
            '%pr%' => 42,
        ]), $entry->sentence);
        self::assertEquals(new TranslatableMessage('board.card.history.reason', [
            '%reason%' => new TranslatableMessage('board.card.history.reason.checks_failed'),
        ]), $entry->cause);
    }

    public function test_a_stop_gives_the_pull_request_and_the_reason(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::Stopped, CardReporter::System, null, ['reason' => 'conflict', 'pullRequest' => 42]));

        self::assertSame('lucide:ban', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.stopped', [
            '%actor%' => new TranslatableMessage('board.card.history.actor.system'),
            '%pr%' => 42,
        ]), $entry->sentence);
        self::assertEquals(new TranslatableMessage('board.card.history.reason', [
            '%reason%' => new TranslatableMessage('board.card.history.reason.conflict'),
        ]), $entry->cause);
    }

    public function test_a_changes_requested_reason_translates(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::Stopped, CardReporter::System, null, ['reason' => 'changes-requested', 'pullRequest' => 42]));

        self::assertEquals(new TranslatableMessage('board.card.history.reason', [
            '%reason%' => new TranslatableMessage('board.card.history.reason.changes_requested'),
        ]), $entry->cause);
    }

    public function test_an_unknown_reason_leaves_no_reason_line(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::FixRequested, CardReporter::System, null, ['reason' => 'solar-flare', 'pullRequest' => 42]));

        self::assertSame('board.card.history.fix_requested', $entry->sentence->getMessage());
        self::assertNull($entry->cause);
    }

    public function test_a_ready_pull_request_names_its_number(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::ReadyToMerge, CardReporter::System, null, ['pullRequest' => 42]));

        self::assertSame('lucide:git-merge', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.ready_to_merge', ['%pr%' => 42]), $entry->sentence);
    }

    public function test_a_synced_pull_request_names_its_number(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::Synced, CardReporter::System, null, ['pullRequest' => 42]));

        self::assertSame('lucide:git-compare', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.synced', [
            '%actor%' => new TranslatableMessage('board.card.history.actor.system'),
            '%pr%' => 42,
        ]), $entry->sentence);
    }

    public function test_a_finished_run_carries_its_work_kind_duration_and_result(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::RunFinished, CardReporter::Agent, $this->user, $this->runDetail('succeeded', 192)), true);

        self::assertSame('lucide:bot', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.run_finished', [
            '%actor%' => new TranslatableMessage('board.card.history.actor.agent', ['%name%' => 'Riley Chen']),
            '%kind%' => 'implement',
        ]), $entry->sentence);
        self::assertEquals(new CardHistoryRun(
            runId: self::RUN_ID,
            duration: '3m 12s',
            stateKey: 'bridge.worker_runs.state.succeeded',
            chipModifier: 'ok',
            interactive: false,
            command: false,
        ), $entry->run);
    }

    public function test_a_finished_command_run_says_it_is_a_command(): void
    {
        $detail = [...$this->runDetail('failed', 4), 'command' => true];

        $entry = CardHistoryEntry::of($this->event(CardEventKind::RunFinished, CardReporter::Agent, $this->user, $detail), true);

        self::assertTrue($entry->run?->command);
        self::assertFalse($entry->run->interactive);
    }

    public function test_a_run_that_never_started_does_not_say_the_agent_ran_it(): void
    {
        foreach (['not-started', 'skipped', 'replaced', 'dropped'] as $state) {
            $entry = CardHistoryEntry::of($this->event(CardEventKind::RunFinished, CardReporter::Agent, $this->user, $this->runDetail($state, null)), true);

            self::assertEquals(new TranslatableMessage('board.card.history.run_not_started', [
                '%actor%' => new TranslatableMessage('board.card.history.actor.agent', ['%name%' => 'Riley Chen']),
                '%kind%' => 'implement',
            ]), $entry->sentence, $state);
        }
    }

    public function test_a_run_written_with_a_rule_name_reads_it_as_the_work_kind(): void
    {
        $detail = $this->runDetail('succeeded', 1);
        unset($detail['workKind']);
        $detail['ruleName'] = 'plan';

        $entry = CardHistoryEntry::of($this->event(CardEventKind::RunFinished, CardReporter::Agent, $this->user, $detail), true);

        self::assertSame('plan', $entry->sentence->getParameters()['%kind%']);
        self::assertSame('board.card.history.run_finished', $entry->sentence->getMessage());
    }

    public function test_a_run_with_no_work_kind_names_none(): void
    {
        $finished = CardHistoryEntry::of($this->event(CardEventKind::RunFinished, CardReporter::Agent, $this->user, ['state' => 'succeeded']));
        $notStarted = CardHistoryEntry::of($this->event(CardEventKind::RunFinished, CardReporter::Agent, $this->user, ['state' => 'not-started']));

        self::assertSame('board.card.history.run_finished_no_kind', $finished->sentence->getMessage());
        self::assertSame('board.card.history.run_not_started_no_kind', $notStarted->sentence->getMessage());
        self::assertNotNull($finished->run);
    }

    public function test_a_purged_run_keeps_its_row_with_no_link(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::RunFinished, CardReporter::Agent, $this->user, $this->runDetail('failed', null)));

        self::assertNotNull($entry->run);
        self::assertNull($entry->run->runId);
        self::assertNull($entry->run->duration);
        self::assertSame('failed', $entry->run->chipModifier);
    }

    public function test_an_unknown_run_state_shows_a_neutral_chip(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::RunFinished, CardReporter::Agent, $this->user, $this->runDetail('evaporated', 5)), true);

        self::assertNotNull($entry->run);
        self::assertSame('board.card.history.run_state_unknown', $entry->run->stateKey);
        self::assertSame('resolved', $entry->run->chipModifier);
    }

    public function test_the_run_id_is_read_only_when_it_is_a_uuid(): void
    {
        self::assertSame(self::RUN_ID, CardHistoryEntry::runIdOf($this->event(CardEventKind::RunFinished, CardReporter::Agent, null, $this->runDetail('succeeded', 1))));
        self::assertNull(CardHistoryEntry::runIdOf($this->event(CardEventKind::RunFinished, CardReporter::Agent, null, ['runId' => 'not-a-uuid'])));
        self::assertNull(CardHistoryEntry::runIdOf($this->event(CardEventKind::Created, CardReporter::Human, null, ['column' => self::BACKLOG])));
    }

    /** @return iterable<string, array{string, string}> */
    public static function pauseKinds(): iterable
    {
        yield 'a rule' => ['rule', 'board.card.history.pause_kind.rule'];
        yield 'retries' => ['retries', 'board.card.history.pause_kind.retries'];
        yield 'a work limit' => ['work-limit', 'board.card.history.pause_kind.work_limit'];
        yield 'a work timeout' => ['work-timeout', 'board.card.history.pause_kind.work_timeout'];
    }

    #[DataProvider('pauseKinds')]
    public function test_a_pause_names_its_kind_and_its_rule(string $kind, string $kindKey): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::Paused, CardReporter::System, null, ['kind' => $kind, 'reason' => 'review-failed', 'ruleId' => 'fix-on-review']));

        self::assertSame('lucide:circle-pause', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.paused', [
            '%actor%' => new TranslatableMessage('board.card.history.actor.system'),
            '%kind%' => new TranslatableMessage($kindKey),
        ]), $entry->sentence);
        self::assertEquals(new TranslatableMessage('board.card.history.cause.workflow_rule', ['%rule%' => 'fix-on-review']), $entry->cause);
        self::assertNull($entry->run);
    }

    public function test_a_release_names_the_person_and_the_kind_of_the_pause(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::PauseReleased, CardReporter::Human, $this->user, ['kind' => 'retries', 'reason' => 'review-failed', 'ruleId' => 'fix-on-review']));

        self::assertSame('lucide:circle-play', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.pause_released', [
            '%actor%' => 'Riley Chen',
            '%kind%' => new TranslatableMessage('board.card.history.pause_kind.retries'),
        ]), $entry->sentence);
        self::assertEquals(new TranslatableMessage('board.card.history.cause.workflow_rule', ['%rule%' => 'fix-on-review']), $entry->cause);
    }

    public function test_a_pause_with_no_rule_has_no_cause_line(): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::Paused, CardReporter::System, null, ['kind' => 'rule']));

        self::assertSame('board.card.history.paused', $entry->sentence->getMessage());
        self::assertNull($entry->cause);
    }

    /** @param array<string, mixed> $detail */
    #[DataProvider('malformedDetails')]
    public function test_a_row_with_unreadable_detail_falls_back_to_a_plain_sentence(CardEventKind $kind, array $detail): void
    {
        $entry = CardHistoryEntry::of($this->event($kind, CardReporter::Human, $this->user, $detail));

        self::assertSame('lucide:history', $entry->icon);
        self::assertEquals(new TranslatableMessage('board.card.history.changed', ['%actor%' => 'Riley Chen']), $entry->sentence);
        self::assertNull($entry->cause);
        self::assertNull($entry->run);
    }

    /** @return iterable<string, array{CardEventKind, array<string, mixed>}> */
    public static function malformedDetails(): iterable
    {
        yield 'a creation with no column' => [CardEventKind::Created, []];
        yield 'a move with no target' => [CardEventKind::Moved, ['from' => self::BACKLOG]];
        yield 'a move whose column has no label' => [CardEventKind::Moved, ['from' => self::BACKLOG, 'to' => ['id' => 'x']]];
        yield 'a fix request with no pull request' => [CardEventKind::FixRequested, ['reason' => 'conflict']];
        yield 'a stop with no pull request' => [CardEventKind::Stopped, []];
        yield 'a ready pull request with a text number' => [CardEventKind::ReadyToMerge, ['pullRequest' => 'twelve']];
        yield 'a pause with no kind' => [CardEventKind::Paused, ['ruleId' => 'fix-on-review']];
        yield 'a release with an unknown kind' => [CardEventKind::PauseReleased, ['kind' => 'gremlins', 'ruleId' => 'fix-on-review']];
    }

    /** @return iterable<string, array{CardReporter, bool, string|TranslatableMessage}> */
    public static function actors(): iterable
    {
        yield 'a person' => [CardReporter::Human, true, 'Riley Chen'];
        yield 'an agent' => [CardReporter::Agent, true, new TranslatableMessage('board.card.history.actor.agent', ['%name%' => 'Riley Chen'])];
        yield 'the app' => [CardReporter::System, false, new TranslatableMessage('board.card.history.actor.system')];
        yield 'a reviewer' => [CardReporter::Reviewer, false, new TranslatableMessage('board.card.history.actor.reviewer')];
        yield 'a deleted person' => [CardReporter::Human, false, new TranslatableMessage('board.card.history.actor.deleted')];
        yield 'an agent of a deleted person' => [CardReporter::Agent, false, new TranslatableMessage('board.card.history.actor.deleted')];
    }

    #[DataProvider('actors')]
    public function test_the_actor_label_follows_the_actor_kind(CardReporter $kind, bool $withUser, string|TranslatableMessage $expected): void
    {
        $entry = CardHistoryEntry::of($this->event(CardEventKind::Created, $kind, $withUser ? $this->user : null, ['column' => self::BACKLOG]));

        self::assertEquals($expected, $entry->actor);
        self::assertEquals($expected, $entry->sentence->getParameters()['%actor%']);
    }

    /** @return array<string, mixed> */
    private function runDetail(string $state, ?int $durationSeconds): array
    {
        return [
            'runId' => self::RUN_ID,
            'workKind' => 'implement',
            'state' => $state,
            'interactive' => false,
            'command' => false,
            'startedAt' => '2026-09-30T10:00:00+00:00',
            'endedAt' => '2026-09-30T10:03:12+00:00',
            'durationSeconds' => $durationSeconds,
        ];
    }

    /** @param array<string, mixed> $detail */
    private function event(CardEventKind $kind, CardReporter $actorKind, ?User $actor, array $detail): CardEvent
    {
        $project = new Project($this->user, 'history');
        $column = new BoardColumn($project, 'board.column.backlog', 'backlog', 0);
        $card = new Card(project: $project, column: $column, title: 'A card', body: '', number: 1);

        return new CardEvent($card, $project, $kind, $actorKind, $actor, $detail, new \DateTimeImmutable('2026-09-30 10:00'));
    }
}
