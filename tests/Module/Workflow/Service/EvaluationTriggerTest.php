<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Service;

use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Service\EvaluationTrigger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

final class EvaluationTriggerTest extends TestCase
{
    /** @var list<object> */
    private array $dispatched = [];

    public function test_it_queues_one_evaluation_per_unique_card(): void
    {
        $one = Uuid::v7();
        $two = Uuid::v7();

        $this->trigger()->forCards([$one, $two->toRfc4122(), $one->toRfc4122(), $two, Uuid::fromString($one->toBase58())]);

        self::assertEquals([new EvaluateCard($one->toRfc4122()), new EvaluateCard($two->toRfc4122())], $this->dispatched);
    }

    public function test_it_is_always_on(): void
    {
        self::assertTrue($this->trigger()->isOn());
    }

    private function trigger(): EvaluationTrigger
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        return new EvaluationTrigger($bus);
    }
}
