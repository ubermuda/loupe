<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Engine\EngineSwitch;
use App\Module\Workflow\Service\EvaluationTrigger;
use App\Module\Workflow\Template\ActionType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The real container builds the engine and every action, and ships with the engine off. */
final class EngineStackTest extends KernelTestCase
{
    public function test_the_container_compiles_the_engine_stack_with_the_engine_off(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(Engine::class, $container->get(Engine::class));
        self::assertInstanceOf(EvaluationTrigger::class, $container->get(EvaluationTrigger::class));
        $actions = $container->get(Actions::class);
        self::assertInstanceOf(Actions::class, $actions);
        foreach (ActionType::cases() as $type) {
            self::assertSame($type, $actions->get($type)::type());
        }
        $switch = $container->get(EngineSwitch::class);
        self::assertInstanceOf(EngineSwitch::class, $switch);
        self::assertFalse($switch->isOn());
    }
}
