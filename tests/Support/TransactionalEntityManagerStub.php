<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Stub;

/**
 * Builds an EntityManagerInterface stub whose wrapInTransaction() actually runs
 * the callback. The default stub returns null and silently skips it, which would
 * make every locked handler look like a no-op in unit tests.
 */
final class TransactionalEntityManagerStub
{
    /** @param EntityManagerInterface&Stub $em */
    public static function configure(EntityManagerInterface $em): EntityManagerInterface
    {
        $em->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $callback): mixed => $callback($em),
        );

        return $em;
    }
}
