<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Action;

use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Action\PauseCard;
use App\Module\Workflow\Template\ActionType;
use PHPUnit\Framework\TestCase;

final class ActionsTest extends TestCase
{
    public function test_it_finds_an_action_by_its_type(): void
    {
        $pause = new PauseCard();

        self::assertSame($pause, new Actions([$pause])->get(ActionType::Pause));
    }

    public function test_a_type_with_no_action_is_a_logic_error(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('No workflow action has the type "move".');

        new Actions([new PauseCard()])->get(ActionType::Move);
    }

    public function test_two_actions_of_one_type_are_refused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Two workflow actions have the type "pause"');

        new Actions([new PauseCard(), new PauseCard()]);
    }
}
