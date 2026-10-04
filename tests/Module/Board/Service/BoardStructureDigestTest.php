<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\BoardColumnView;
use App\Module\Board\Command\BoardLaneView;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Entity\LabelTone;
use App\Module\Board\Service\BoardStructureDigest;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\TestCase;

final class BoardStructureDigestTest extends TestCase
{
    private Project $project;
    private BoardColumn $backlog;
    private BoardColumn $done;
    private Card $epic;

    #[\Override]
    protected function setUp(): void
    {
        $this->project = new Project(new User(fullName: 'Owner', email: 'owner@example.com', password: 'hashed'), 'p');
        $this->backlog = new BoardColumn(project: $this->project, label: 'Backlog', slug: 'backlog', position: 0);
        $this->done = new BoardColumn(project: $this->project, label: 'Done', slug: 'done', position: 1);
        $this->epic = new Card(project: $this->project, column: $this->backlog, title: 'Epic', body: '', number: 1);
        $this->epic->type = CardType::Epic;
    }

    public function test_the_digest_is_a_twelve_character_hash_that_repeats_for_the_same_board(): void
    {
        $digest = $this->digest();

        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $digest);
        self::assertSame($digest, $this->digest());
    }

    public function test_the_digest_changes_with_the_column_order(): void
    {
        $before = $this->digest();

        self::assertNotSame($before, $this->digest([$this->done, $this->backlog]));
    }

    public function test_the_digest_changes_with_the_column_label(): void
    {
        $before = $this->digest();
        $this->backlog->label = 'Ideas';

        self::assertNotSame($before, $this->digest());
    }

    public function test_the_digest_changes_with_the_column_tone(): void
    {
        $before = $this->digest();
        $this->backlog->tone = LabelTone::Pink;

        self::assertNotSame($before, $this->digest());
    }

    public function test_the_digest_changes_with_the_terminal_flag(): void
    {
        $before = $this->digest();
        $this->done->terminal = true;

        self::assertNotSame($before, $this->digest());
    }

    public function test_the_digest_changes_with_the_terminal_window(): void
    {
        self::assertNotSame($this->digest(), $this->digest(terminalWindowDays: 10));
    }

    public function test_the_digest_changes_when_a_lane_turns_on_or_off(): void
    {
        $withLane = $this->digest();

        self::assertNotSame($withLane, $this->digest(lanes: []));
    }

    public function test_the_digest_ignores_the_face_of_a_lane_epic(): void
    {
        $before = $this->digest();
        $this->epic->title = 'Renamed epic';
        $this->epic->body = 'A new body';

        self::assertSame($before, $this->digest());
    }

    /**
     * @param list<BoardColumn>|null   $columns
     * @param list<BoardLaneView>|null $lanes
     */
    private function digest(?array $columns = null, ?array $lanes = null, int $terminalWindowDays = 3): string
    {
        $views = array_map(
            static fn (BoardColumn $column): BoardColumnView => new BoardColumnView($column, [], 0),
            $columns ?? [$this->backlog, $this->done],
        );

        return new BoardStructureDigest()->forBoard(
            $views,
            $lanes ?? [new BoardLaneView($this->epic, [])],
            $terminalWindowDays,
        );
    }
}
