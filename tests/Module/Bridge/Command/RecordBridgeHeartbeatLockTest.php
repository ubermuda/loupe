<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\RecordBridgeHeartbeatCommand;
use App\Module\Bridge\Command\RecordBridgeHeartbeatHandler;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A second database session holds the lock of one owner and bridge id, so the
 * handler must wait for that pair and for no other.
 */
final class RecordBridgeHeartbeatLockTest extends KernelTestCase
{
    use BridgeScenario;

    private ?Connection $other = null;

    #[\Override]
    protected function tearDown(): void
    {
        if (null !== $this->other) {
            if ($this->other->isTransactionActive()) {
                $this->other->rollBack();
            }
            $this->other->close();
        }

        parent::tearDown();
    }

    public function test_a_heartbeat_waits_for_the_lock_of_its_owner_and_bridge_id(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'heartbeat-lock-held@example.com');
        $stranger = $this->user($em, 'heartbeat-lock-free@example.com');
        $bridgeId = Uuid::v4();

        $this->other = DriverManager::getConnection($em->getConnection()->getParams());
        $this->other->beginTransaction();
        $this->other->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            ['bridge:'.$owner->id.':'.$bridgeId->toRfc4122()],
        );
        $em->getConnection()->executeStatement("SET LOCAL lock_timeout = '300ms'");
        $handler = self::getContainer()->get(RecordBridgeHeartbeatHandler::class);
        self::assertInstanceOf(RecordBridgeHeartbeatHandler::class, $handler);

        // Another account sends the same bridge id, and its key is free.
        $handler(new RecordBridgeHeartbeatCommand($stranger, $bridgeId, [], 'b4e39aa7'));

        try {
            $handler(new RecordBridgeHeartbeatCommand($owner, $bridgeId, [], 'b4e39aa7'));
            self::fail('The heartbeat did not wait for the lock another session holds.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('lock timeout', $e->getMessage());
        }
    }
}
