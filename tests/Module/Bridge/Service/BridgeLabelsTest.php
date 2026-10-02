<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Service\BridgeLabels;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BridgeLabelsTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_labels_each_id_by_the_name_of_the_owner_bridge_or_the_tail_of_the_id(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'bridge-labels-owner@example.com');
        $stranger = $this->user($em, 'bridge-labels-stranger@example.com');
        $named = $this->seedBridge($em, $owner);
        $named->name = 'laptop';
        $unnamed = $this->seedBridge($em, $owner);
        $clashing = $this->seedBridge($em, $owner);
        $clashing->requestedName = 'laptop';
        $elsewhere = $this->seedBridge($em, $stranger);
        $elsewhere->name = 'desktop';
        $em->flush();

        $names = $this->labels()->forOwner($owner, [
            $named->id,
            (string) $unnamed->id,
            $clashing->id,
            $elsewhere->id,
        ]);

        self::assertSame([
            $named->id->toRfc4122() => 'laptop',
            $unnamed->id->toRfc4122() => substr((string) $unnamed->id, -12),
            $clashing->id->toRfc4122() => substr((string) $clashing->id, -12),
            $elsewhere->id->toRfc4122() => substr((string) $elsewhere->id, -12),
        ], $names);
    }

    public function test_a_string_id_and_a_uuid_find_the_same_bridge(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'bridge-labels-string@example.com');
        $bridge = $this->seedBridge($em, $owner, Uuid::v7());
        $bridge->name = 'laptop';
        $em->flush();

        self::assertSame([$bridge->id->toRfc4122() => 'laptop'], $this->labels()->forOwner($owner, [strtoupper((string) $bridge->id)]));
    }

    public function test_no_ids_give_no_labels(): void
    {
        self::bootKernel();
        $owner = $this->user($this->em(), 'bridge-labels-none@example.com');

        self::assertSame([], $this->labels()->forOwner($owner, []));
    }

    private function labels(): BridgeLabels
    {
        $labels = self::getContainer()->get(BridgeLabels::class);
        self::assertInstanceOf(BridgeLabels::class, $labels);

        return $labels;
    }
}
