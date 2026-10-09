<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Action\PauseCard;
use App\Module\Workflow\Contract\ActionTraits;
use PHPUnit\Framework\TestCase;

final class ActionsTest extends TestCase
{
    public function test_it_finds_an_action_by_its_key(): void
    {
        $pause = new PauseCard();
        $actions = new Actions([$pause]);

        self::assertSame($pause, $actions->get('pause'));
        self::assertTrue($actions->has('pause'));
        self::assertFalse($actions->has('move'));
        self::assertSame(['pause'], $actions->keys());
    }

    public function test_a_key_with_no_action_is_a_logic_error(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No workflow action has the key "move".');

        new Actions([new PauseCard()])->get('move');
    }

    public function test_two_actions_of_one_key_are_refused(): void
    {
        $actions = new Actions([new PauseCard(), new PauseCard()]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Two workflow actions have the key "pause"');

        $actions->keys();
    }

    public function test_a_call_carries_the_traits_and_the_from_slot_of_its_action(): void
    {
        $actions = new Actions([...array_map(static fn (string $class) => new \ReflectionClass($class)->newInstanceWithoutConstructor(), [
            \App\Module\Board\Workflow\MoveCard::class,
            \App\Module\Board\Workflow\Detach::class,
        ])]);

        $move = $actions->call('move', ['to' => 'build', 'from' => 'plan']);
        self::assertEquals(new ActionTraits(endsPass: true, option: true, childChoice: true), $move->traits);
        self::assertSame('plan', $move->from);
        self::assertNull($actions->call('move', ['to' => 'build'])->from);
        self::assertNull($actions->call('detach', [])->from);
    }
}
