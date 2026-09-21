<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\LabelTone;
use App\Module\Board\Service\BoardColumnTonePicker;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class BoardColumnTonePickerTest extends TestCase
{
    public function test_it_picks_a_colour_no_column_uses(): void
    {
        $taken = [LabelTone::Neutral, LabelTone::Lime, LabelTone::Purple, LabelTone::Green];
        $columns = $this->columns($taken);

        for ($seed = 1; $seed <= 20; ++$seed) {
            $tone = new BoardColumnTonePicker(new Randomizer(new Mt19937($seed)))->pick($columns);
            self::assertNotContains($tone, $taken);
        }
    }

    public function test_the_same_seed_picks_the_same_colour(): void
    {
        $columns = $this->columns([LabelTone::Neutral]);

        self::assertSame(
            new BoardColumnTonePicker(new Randomizer(new Mt19937(7)))->pick($columns),
            new BoardColumnTonePicker(new Randomizer(new Mt19937(7)))->pick($columns),
        );
    }

    public function test_the_last_free_colour_is_the_only_choice(): void
    {
        $taken = array_values(array_filter(LabelTone::cases(), static fn (LabelTone $tone): bool => LabelTone::Orange !== $tone));

        self::assertSame(LabelTone::Orange, new BoardColumnTonePicker(new Randomizer(new Mt19937(3)))->pick($this->columns($taken)));
    }

    public function test_a_board_that_uses_every_colour_picks_from_the_whole_palette(): void
    {
        $tone = new BoardColumnTonePicker(new Randomizer(new Mt19937(5)))->pick($this->columns(LabelTone::cases()));

        self::assertContains($tone, LabelTone::cases());
    }

    /**
     * @param list<LabelTone> $tones
     *
     * @return list<BoardColumn>
     */
    private function columns(array $tones): array
    {
        $project = new Project(new User(fullName: 'Owner', email: 'picker@example.com', password: 'x'), 'Picker');

        return array_map(
            static fn (LabelTone $tone, int $position): BoardColumn => new BoardColumn($project, 'Column '.$position, 'column-'.$position, $position, tone: $tone),
            $tones,
            array_keys($tones),
        );
    }
}
