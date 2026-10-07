<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Repository;

use App\Module\Bridge\Repository\BridgeHostSampleRepository;
use App\Module\Bridge\ValueObject\BridgeHostSampleReport;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BridgeHostSampleRepositoryTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_the_window_holds_the_samples_of_the_owners_bridge_alone_oldest_first(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'samples-window-owner@example.com');
        $shared = Uuid::v4();
        $bridge = $this->seedBridge($em, $owner, $shared);
        foreach (['11:59:59', '12:02:00', '12:00:00', '12:01:00', '12:02:01'] as $time) {
            $this->seedHostSample($bridge, '2026-10-07 '.$time);
        }
        $this->seedHostSample($this->seedBridge($em, $owner), '2026-10-07 12:01:00');
        $this->seedHostSample($this->seedBridge($em, $this->user($em, 'samples-window-other@example.com'), $shared), '2026-10-07 12:01:00');

        $samples = $this->repository()->findForBridgeBetween(
            $owner->id ?? throw new \LogicException('The owner has no id.'),
            $shared,
            new \DateTimeImmutable('2026-10-07T14:00:00+02:00'),
            new \DateTimeImmutable('2026-10-07 12:02:00', new \DateTimeZone('UTC')),
            10,
        );

        self::assertSame(
            ['2026-10-07 12:00:00', '2026-10-07 12:01:00', '2026-10-07 12:02:00'],
            array_map(static fn (BridgeHostSampleReport $sample): string => $sample->sampledAt->format('Y-m-d H:i:s'), $samples),
        );
        self::assertSame([10.0, 30.0], $samples[0]->cpuPct);
    }

    public function test_the_window_stops_at_the_limit(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'samples-window-limit@example.com');
        $bridge = $this->seedBridge($em, $owner);
        $this->seedHostSample($bridge, '2026-10-07 12:00:00');
        $this->seedHostSample($bridge, '2026-10-07 12:01:00');

        $samples = $this->repository()->findForBridgeBetween(
            $owner->id ?? throw new \LogicException('The owner has no id.'),
            $bridge->id,
            new \DateTimeImmutable('2026-10-07 00:00:00'),
            new \DateTimeImmutable('2026-10-08 00:00:00'),
            1,
        );

        self::assertCount(1, $samples);
        self::assertSame('2026-10-07 12:00:00', $samples[0]->sampledAt->format('Y-m-d H:i:s'));
    }

    private function repository(): BridgeHostSampleRepository
    {
        $repository = self::getContainer()->get(BridgeHostSampleRepository::class);
        self::assertInstanceOf(BridgeHostSampleRepository::class, $repository);

        return $repository;
    }
}
