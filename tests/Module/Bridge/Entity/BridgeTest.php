<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Entity;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\Bridge;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class BridgeTest extends TestCase
{
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
