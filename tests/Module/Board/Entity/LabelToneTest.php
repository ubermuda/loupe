<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Entity;

use App\Module\Board\Entity\CardType;
use App\Module\Board\Entity\LabelTone;
use PHPUnit\Framework\TestCase;

final class LabelToneTest extends TestCase
{
    public function test_every_card_type_has_its_own_tone(): void
    {
        $tones = array_map(static fn (CardType $type): LabelTone => $type->tone(), CardType::cases());

        self::assertCount(count(CardType::cases()), array_unique(array_map(static fn (LabelTone $tone): string => $tone->value, $tones)));
        self::assertSame(LabelTone::Amber, CardType::Bug->tone());
    }
}
