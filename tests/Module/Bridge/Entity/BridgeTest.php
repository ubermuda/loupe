<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Entity;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class BridgeTest extends TestCase
{
    /** @return iterable<string, array{list<string>|null, ?string, bool}> */
    public static function capabilities(): iterable
    {
        yield 'no report' => [null, null, false];
        yield 'commands only' => [[Bridge::CAPABILITY_COMMANDS], null, false];
        yield 'work requests, no need' => [[Bridge::CAPABILITY_WORK_REQUESTS], null, true];
        yield 'work requests, a missing need' => [[Bridge::CAPABILITY_WORK_REQUESTS], Bridge::CAPABILITY_INTERACTIVE, false];
        yield 'work requests and the need' => [[Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_INTERACTIVE], Bridge::CAPABILITY_INTERACTIVE, true];
        yield 'the need alone' => [[Bridge::CAPABILITY_INTERACTIVE], Bridge::CAPABILITY_INTERACTIVE, false];
    }

    /** @param list<string>|null $capabilities */
    #[DataProvider('capabilities')]
    public function test_a_bridge_runs_a_request_when_it_takes_work_requests_and_reports_the_need(?array $capabilities, ?string $need, bool $runs): void
    {
        $bridge = new Bridge(new User('Riley Chen', 'riley@example.com', 'x'), Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        $bridge->capabilities = $capabilities;

        self::assertSame($runs, $bridge->canRun($need));
        self::assertSame(null !== $capabilities && \in_array(Bridge::CAPABILITY_WORK_REQUESTS, $capabilities, true), $bridge->takesWorkRequests());
    }

    public function test_a_named_bridge_takes_its_name_as_its_label(): void
    {
        $bridge = $this->bridge('0199a3c4-0000-7000-8000-0123456789ab');
        $bridge->name = 'laptop';

        self::assertSame('laptop', $bridge->label);
    }

    public function test_a_bridge_with_no_name_takes_the_tail_of_its_id(): void
    {
        $bridge = $this->bridge('0199a3c4-0000-7000-8000-0123456789ab');
        $bridge->requestedName = 'laptop';

        self::assertSame('0123456789ab', $bridge->label);
    }

    public function test_a_string_id_gives_the_same_label_as_a_uuid(): void
    {
        self::assertSame('0123456789ab', Bridge::labelFor('0199A3C4-0000-7000-8000-0123456789AB', null));
    }

    private function bridge(string $id): Bridge
    {
        return new Bridge(new User('Riley Chen', 'riley@example.com', 'x'), Uuid::fromString($id), [], 'b4e39aa7', new \DateTimeImmutable());
    }
}
