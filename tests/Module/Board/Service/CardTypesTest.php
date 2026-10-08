<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Entity\LabelTone;
use App\Module\Board\Service\CardTypeDefinition;
use App\Module\Board\Service\CardTypes;
use PHPUnit\Framework\TestCase;

final class CardTypesTest extends TestCase
{
    private CardTypes $types;

    #[\Override]
    protected function setUp(): void
    {
        $this->types = new CardTypes([
            new CardTypeDefinition('feature', 'board.card.type.feature', LabelTone::Lime, false, false),
            new CardTypeDefinition('epic', 'board.card.type.epic', LabelTone::Blue, true, true),
        ], 'feature');
    }

    public function test_it_returns_a_declared_type(): void
    {
        self::assertTrue($this->types->has('epic'));
        self::assertSame($this->types->all[1], $this->types->get('epic'));
    }

    public function test_an_undeclared_type_falls_back_to_a_neutral_type_named_by_its_key(): void
    {
        self::assertFalse($this->types->has('site-review'));
        self::assertEquals(new CardTypeDefinition('site-review', 'site-review', LabelTone::Neutral, false, false), $this->types->get('site-review'));
    }

    public function test_it_returns_the_default_type(): void
    {
        self::assertSame($this->types->all[0], $this->types->default());
    }

    public function test_it_lists_the_keys_and_the_capability_keys(): void
    {
        self::assertSame(['feature', 'epic'], $this->types->keys());
        self::assertSame(['epic'], $this->types->withChildren());
        self::assertSame(['epic'], $this->types->withLane());
    }
}
