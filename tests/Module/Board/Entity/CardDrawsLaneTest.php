<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Entity;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Tests\Support\ShippedCardTypes;
use PHPUnit\Framework\TestCase;

final class CardDrawsLaneTest extends TestCase
{
    public function test_an_epic_with_its_lane_on_in_an_open_column_draws_a_lane(): void
    {
        self::assertTrue($this->card('epic', laneEnabled: true, terminal: false)->drawsLane(ShippedCardTypes::types()));
    }

    public function test_a_card_of_a_type_without_the_lane_capability_draws_no_lane(): void
    {
        self::assertFalse($this->card('feature', laneEnabled: true, terminal: false)->drawsLane(ShippedCardTypes::types()));
    }

    public function test_an_epic_with_its_lane_off_draws_no_lane(): void
    {
        self::assertFalse($this->card('epic', laneEnabled: false, terminal: false)->drawsLane(ShippedCardTypes::types()));
    }

    public function test_an_epic_in_a_terminal_column_draws_no_lane(): void
    {
        self::assertFalse($this->card('epic', laneEnabled: true, terminal: true)->drawsLane(ShippedCardTypes::types()));
    }

    private function card(string $type, bool $laneEnabled, bool $terminal): Card
    {
        $project = new Project(new User(fullName: 'Owner', email: 'owner@example.com', password: 'hashed'), 'p');
        $column = new BoardColumn(project: $project, label: 'Column', slug: 'column', position: 0, terminal: $terminal);
        $card = new Card(project: $project, column: $column, title: 'Epic', body: '', number: 1, type: $type);
        $card->laneEnabled = $laneEnabled;

        return $card;
    }
}
