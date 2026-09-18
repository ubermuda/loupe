<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Service\BoardColumns;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

final class BoardColumnsTest extends TestCase
{
    private BoardColumns $rules;
    private Project $project;

    /** @var array<string, BoardColumn> */
    private array $board;

    protected function setUp(): void
    {
        $this->rules = new BoardColumns(new IdentityTranslator());
        $this->project = new Project(new User(fullName: 'Riley', email: 'riley@example.com', password: 'hashed'), 'p');
        $this->board = [
            'backlog' => $this->column('backlog', 0, isDefault: true),
            'next' => $this->column('next', 1),
            'done' => $this->column('done', 2, terminal: true),
        ];
    }

    public function test_the_slug_follows_the_label(): void
    {
        self::assertSame('won-t-do', $this->rules->slugFor("  Won't do "));
        self::assertSame('', $this->rules->slugFor('🚀'));
    }

    public function test_a_new_column_with_a_fresh_slug_is_allowed(): void
    {
        self::assertNull($this->rules->refuseAdd($this->columns(), 'parked'));
    }

    public function test_a_new_column_with_an_empty_slug_is_refused(): void
    {
        self::assertSame(BoardColumns::SLUG_EMPTY, $this->rules->refuseAdd($this->columns(), ''));
    }

    public function test_a_slug_outside_the_pattern_is_refused(): void
    {
        self::assertSame(BoardColumns::SLUG_INVALID, $this->rules->refuseAdd($this->columns(), 'Parked'));
        self::assertSame(BoardColumns::SLUG_INVALID, $this->rules->refuseAdd($this->columns(), 'double--hyphen'));
    }

    public function test_a_new_column_with_a_slug_the_board_has_is_refused(): void
    {
        self::assertSame(BoardColumns::SLUG_TAKEN, $this->rules->refuseAdd($this->columns(), 'next'));
    }

    public function test_a_rename_may_keep_its_own_slug(): void
    {
        self::assertNull($this->rules->refuseRename($this->columns(), $this->board['next'], 'next'));
    }

    public function test_a_rename_onto_another_columns_slug_is_refused(): void
    {
        self::assertSame(BoardColumns::SLUG_TAKEN, $this->rules->refuseRename($this->columns(), $this->board['next'], 'done'));
    }

    public function test_a_rename_to_an_empty_slug_is_refused(): void
    {
        self::assertSame(BoardColumns::SLUG_EMPTY, $this->rules->refuseRename($this->columns(), $this->board['next'], ''));
    }

    public function test_a_configure_judges_the_three_settings_together(): void
    {
        $this->board['next']->terminal = true;

        // Leaving the terminal flag and taking the default is one valid change.
        self::assertNull($this->rules->refuseConfigure($this->columns(), $this->board['done'], 'shipped', false, true));
        self::assertSame(BoardColumns::DEFAULT_TERMINAL, $this->rules->refuseConfigure($this->columns(), $this->board['done'], 'done', true, true));
        self::assertSame(BoardColumns::NO_SINGLE_DEFAULT, $this->rules->refuseConfigure($this->columns(), $this->board['backlog'], 'backlog', false, false));
        self::assertSame(BoardColumns::SLUG_TAKEN, $this->rules->refuseConfigure($this->columns(), $this->board['backlog'], 'next', false, true));
    }

    public function test_a_configure_cannot_clear_the_last_terminal_column(): void
    {
        self::assertSame(BoardColumns::NO_TERMINAL, $this->rules->refuseConfigure($this->columns(), $this->board['done'], 'done', false, false));
    }

    public function test_the_last_terminal_column_cannot_lose_its_flag(): void
    {
        self::assertSame(BoardColumns::NO_TERMINAL, $this->rules->refuseTerminal($this->columns(), $this->board['done'], false));
    }

    public function test_a_terminal_column_can_lose_its_flag_while_another_keeps_one(): void
    {
        $this->board['next']->terminal = true;

        self::assertNull($this->rules->refuseTerminal($this->columns(), $this->board['done'], false));
    }

    public function test_the_default_column_cannot_become_terminal(): void
    {
        self::assertSame(BoardColumns::DEFAULT_TERMINAL, $this->rules->refuseTerminal($this->columns(), $this->board['backlog'], true));
    }

    public function test_a_terminal_column_cannot_become_the_default(): void
    {
        self::assertSame(BoardColumns::DEFAULT_TERMINAL, $this->rules->refuseDefault($this->columns(), $this->board['done']));
    }

    public function test_any_other_column_can_become_the_default(): void
    {
        self::assertNull($this->rules->refuseDefault($this->columns(), $this->board['next']));
    }

    public function test_the_default_column_cannot_be_deleted(): void
    {
        self::assertSame(BoardColumns::NO_SINGLE_DEFAULT, $this->rules->refuseDelete($this->columns(), $this->board['backlog']));
    }

    public function test_the_last_terminal_column_cannot_be_deleted(): void
    {
        self::assertSame(BoardColumns::NO_TERMINAL, $this->rules->refuseDelete($this->columns(), $this->board['done']));
    }

    public function test_a_plain_column_can_be_deleted(): void
    {
        self::assertNull($this->rules->refuseDelete($this->columns(), $this->board['next']));
    }

    public function test_a_board_with_two_defaults_is_refused_whatever_the_change(): void
    {
        $this->board['next']->isDefault = true;

        self::assertSame(BoardColumns::NO_SINGLE_DEFAULT, $this->rules->refuseAdd($this->columns(), 'parked'));
    }

    public function test_no_change_touches_the_columns_it_checks(): void
    {
        $this->rules->refuseRename($this->columns(), $this->board['next'], 'renamed');
        $this->rules->refuseTerminal($this->columns(), $this->board['next'], true);
        $this->rules->refuseDefault($this->columns(), $this->board['next']);

        self::assertSame('next', $this->board['next']->slug);
        self::assertFalse($this->board['next']->terminal);
        self::assertFalse($this->board['next']->isDefault);
    }

    /** @return list<BoardColumn> */
    private function columns(): array
    {
        return array_values($this->board);
    }

    private function column(string $slug, int $position, bool $terminal = false, bool $isDefault = false): BoardColumn
    {
        return new BoardColumn(project: $this->project, label: $slug, slug: $slug, position: $position, terminal: $terminal, isDefault: $isDefault);
    }
}
