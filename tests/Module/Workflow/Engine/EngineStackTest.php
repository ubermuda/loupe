<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Engine;

use App\Module\Workflow\Action\Actions;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Service\EvaluationTrigger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The real container builds the engine and every action. */
final class EngineStackTest extends KernelTestCase
{
    public function test_the_container_compiles_the_engine_stack(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        self::assertInstanceOf(Engine::class, $container->get(Engine::class));
        self::assertInstanceOf(EvaluationTrigger::class, $container->get(EvaluationTrigger::class));
        $actions = $container->get(Actions::class);
        self::assertInstanceOf(Actions::class, $actions);
        self::assertEqualsCanonicalizing(['move', 'request', 'forge-write', 'pause', 'release', 'evaluate', 'ask', 'link-document', 'detach'], $actions->keys());
        foreach ($actions->keys() as $key) {
            self::assertSame($key, $actions->get($key)::key());
        }
    }
}
