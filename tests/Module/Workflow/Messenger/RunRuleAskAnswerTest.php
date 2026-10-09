<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Messenger;

use App\Module\Workflow\Messenger\RunRuleAskAnswer;
use App\Module\Workflow\Messenger\RunRuleAskAnswerHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class RunRuleAskAnswerTest extends KernelTestCase
{
    public function test_the_answer_goes_to_the_async_transport(): void
    {
        self::bootKernel();
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $message = new RunRuleAskAnswer(Uuid::v7()->toRfc4122(), 1);

        $bus->dispatch($message);

        $sent = array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent());
        self::assertContains($message, $sent);
    }

    public function test_the_handler_ignores_an_item_that_no_rule_state_holds(): void
    {
        self::bootKernel();
        $handler = self::getContainer()->get(RunRuleAskAnswerHandler::class);
        self::assertInstanceOf(RunRuleAskAnswerHandler::class, $handler);

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $handler(new RunRuleAskAnswer(Uuid::v7()->toRfc4122(), 0));

        self::assertSame([], $transport->getSent());
    }
}
