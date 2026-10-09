<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Condition;

use App\Module\Board\Workflow\Condition\CardHasOpenBlocker;
use App\Module\Board\Workflow\Condition\CardParentExists;
use App\Module\Workflow\Condition\Conditions;
use App\Module\Workflow\Condition\UnknownCondition;
use PHPUnit\Framework\TestCase;

final class ConditionsTest extends TestCase
{
    public function test_it_finds_a_condition_by_its_key(): void
    {
        $blocker = new CardHasOpenBlocker();
        $conditions = new Conditions([$blocker, new CardParentExists()]);

        self::assertSame($blocker, $conditions->get('card.blocker.open'));
        self::assertTrue($conditions->has('card.parent.exists'));
        self::assertSame(['card.blocker.open', 'card.parent.exists'], $conditions->keys());
    }

    public function test_it_refuses_an_unknown_key(): void
    {
        $conditions = new Conditions([new CardParentExists()]);

        self::assertFalse($conditions->has('card.is_orphan'));

        try {
            $conditions->get('card.is_orphan');
            self::fail('An unknown key must throw.');
        } catch (UnknownCondition $e) {
            self::assertSame('card.is_orphan', $e->key);
        }
    }

    public function test_two_conditions_with_one_key_fail_at_construction(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('card.parent.exists');

        new Conditions([new CardParentExists(), new CardParentExists()]);
    }
}
