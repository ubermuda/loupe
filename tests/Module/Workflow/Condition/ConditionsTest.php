<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Condition;

use App\Module\Workflow\Condition\CardHasOpenBlocker;
use App\Module\Workflow\Condition\CardIsChild;
use App\Module\Workflow\Condition\Conditions;
use App\Module\Workflow\Condition\UnknownCondition;
use PHPUnit\Framework\TestCase;

final class ConditionsTest extends TestCase
{
    public function test_it_finds_a_condition_by_its_key(): void
    {
        $blocker = new CardHasOpenBlocker();
        $conditions = new Conditions([$blocker, new CardIsChild()]);

        self::assertSame($blocker, $conditions->get('card.has_open_blocker'));
        self::assertTrue($conditions->has('card.is_child'));
        self::assertSame(['card.has_open_blocker', 'card.is_child'], $conditions->keys());
    }

    public function test_it_refuses_an_unknown_key(): void
    {
        $conditions = new Conditions([new CardIsChild()]);

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
        $this->expectExceptionMessage('card.is_child');

        new Conditions([new CardIsChild(), new CardIsChild()]);
    }
}
