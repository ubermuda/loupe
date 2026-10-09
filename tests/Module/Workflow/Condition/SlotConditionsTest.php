<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Condition;

use App\Module\Workflow\Condition\CardInSlot;
use App\Module\Workflow\Condition\ParentInSlot;
use App\Module\Workflow\Contract\Condition;
use App\Module\Workflow\Contract\EngineFact;
use App\Module\Workflow\Contract\Facts;
use App\Tests\Module\Workflow\Fact\FactsMother;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlotConditionsTest extends TestCase
{
    /** @param array<string, mixed> $params */
    #[DataProvider('cases')]
    public function test_it_evaluates_the_facts(Condition $condition, array $params, Facts $facts, bool $expected): void
    {
        self::assertSame($expected, $condition->evaluate($facts, $params));
    }

    /** @return iterable<string, array{Condition, array<string, mixed>, Facts, bool}> */
    public static function cases(): iterable
    {
        yield 'in slot, same slot' => [new CardInSlot(), ['slot' => 'tech-design'], self::card(slot: 'tech-design'), true];
        yield 'in slot, other slot' => [new CardInSlot(), ['slot' => 'tech-design'], self::card(slot: 'implementation'), false];
        yield 'in slot, no slot' => [new CardInSlot(), ['slot' => 'tech-design'], self::card(slot: null), false];

        yield 'parent in slot, same slot' => [new ParentInSlot(), ['slot' => 'implementation'], self::card(parentSlot: 'implementation'), true];
        yield 'parent in slot, other slot' => [new ParentInSlot(), ['slot' => 'implementation'], self::card(parentSlot: 'next'), false];
        yield 'parent in slot, no slot' => [new ParentInSlot(), ['slot' => 'implementation'], self::card(parentSlot: null), false];
        yield 'parent in slot, only the card is in the slot' => [new ParentInSlot(), ['slot' => 'implementation'], self::card(slot: 'implementation'), false];
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $expectedParameters
     */
    #[DataProvider('waiting')]
    public function test_it_says_what_it_waits_for(Condition $condition, array $params, string $expectedKey, array $expectedParameters): void
    {
        $message = $condition->waitingFor($params);

        self::assertSame($expectedKey, $message->getMessage());
        self::assertSame($expectedParameters, $message->getParameters());

        $negated = $condition->waitingFor($params, negated: true);

        self::assertSame(str_replace('workflow.waiting.', 'workflow.waiting.not.', $expectedKey), $negated->getMessage());
        self::assertSame($expectedParameters, $negated->getParameters());
    }

    /** @return iterable<string, array{Condition, array<string, mixed>, string, array<string, string>}> */
    public static function waiting(): iterable
    {
        yield 'card.in_slot' => [new CardInSlot(), ['slot' => 'tech-design'], 'workflow.waiting.card_in_slot', ['%slot%' => 'tech-design']];
        yield 'card.parent.in_slot' => [new ParentInSlot(), ['slot' => 'implementation'], 'workflow.waiting.parent_in_slot', ['%slot%' => 'implementation']];
    }

    /**
     * @param array<string, mixed> $params
     * @param list<EngineFact>     $expected
     */
    #[DataProvider('reads')]
    public function test_it_names_the_facts_it_reads(Condition $condition, array $params, array $expected): void
    {
        self::assertSame($expected, $condition->reads($params));
    }

    /** @return iterable<string, array{Condition, array<string, mixed>, list<EngineFact>}> */
    public static function reads(): iterable
    {
        yield 'card.in_slot' => [new CardInSlot(), ['slot' => 'next'], [EngineFact::Slot]];
        yield 'card.parent.in_slot' => [new ParentInSlot(), ['slot' => 'next'], [EngineFact::ParentSlot]];
    }

    private static function card(?string $slot = null, ?string $parentSlot = null): Facts
    {
        return FactsMother::facts(card: FactsMother::card(slot: $slot, parentSlot: $parentSlot));
    }
}
