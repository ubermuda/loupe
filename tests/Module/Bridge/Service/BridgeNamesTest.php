<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Service\BridgeNames;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BridgeNamesTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_maps_each_named_bridge_of_the_owner_to_its_name(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'bridge-names-owner@example.com');
        $stranger = $this->user($em, 'bridge-names-stranger@example.com');
        $named = $this->seedBridge($em, $owner);
        $named->name = 'laptop';
        $unnamed = $this->seedBridge($em, $owner);
        $clashing = $this->seedBridge($em, $owner);
        $clashing->requestedName = 'laptop';
        $elsewhere = $this->seedBridge($em, $stranger);
        $elsewhere->name = 'desktop';
        $em->flush();

        $names = $this->names()->forOwner($owner, [
            $named->id,
            (string) $unnamed->id,
            $clashing->id,
            $elsewhere->id,
        ]);

        self::assertSame([$named->id->toRfc4122() => 'laptop'], $names);
    }

    public function test_a_string_id_and_a_uuid_find_the_same_bridge(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'bridge-names-string@example.com');
        $bridge = $this->seedBridge($em, $owner, Uuid::v7());
        $bridge->name = 'laptop';
        $em->flush();

        self::assertSame([$bridge->id->toRfc4122() => 'laptop'], $this->names()->forOwner($owner, [strtoupper((string) $bridge->id)]));
    }

    public function test_no_ids_give_no_names(): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'bridge-names-none@example.com');

        self::assertSame([], $this->names()->forOwner($owner, []));
    }

    private function names(): BridgeNames
    {
        $names = self::getContainer()->get(BridgeNames::class);
        self::assertInstanceOf(BridgeNames::class, $names);

        return $names;
    }
}
