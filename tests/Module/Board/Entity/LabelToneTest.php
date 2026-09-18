<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Entity;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Entity\LabelTone;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class LabelToneTest extends TestCase
{
    public function test_every_card_type_has_its_own_tone(): void
    {
        $tones = array_map(static fn (CardType $type): LabelTone => $type->tone(), CardType::cases());

        self::assertCount(count(CardType::cases()), array_unique(array_map(static fn (LabelTone $tone): string => $tone->value, $tones)));
        self::assertSame(LabelTone::Amber, CardType::Bug->tone());
    }

    /** The prototype's five default columns keep the colours it gives them. */
    #[TestWith([0, true, false, LabelTone::Neutral])]
    #[TestWith([1, false, false, LabelTone::Lime])]
    #[TestWith([2, false, false, LabelTone::Purple])]
    #[TestWith([3, false, false, LabelTone::Amber])]
    #[TestWith([4, false, true, LabelTone::Green])]
    #[TestWith([4, false, false, LabelTone::Lime])]
    #[TestWith([0, false, false, LabelTone::Amber])]
    public function test_a_column_takes_its_tone_from_its_role_and_position(int $position, bool $isDefault, bool $terminal, LabelTone $expected): void
    {
        $project = new Project(new User(fullName: 'Owner', email: 'tone@example.com', password: 'x'), 'Tones');
        $column = new BoardColumn($project, 'Column', 'column', $position, terminal: $terminal, isDefault: $isDefault);

        self::assertSame($expected, $column->tone());
    }
}
