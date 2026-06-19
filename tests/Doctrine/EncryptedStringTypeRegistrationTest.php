<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\DoctrineExtra\Type\EncryptedStringType;

/**
 * Proves the ubermuda/doctrine-extra bundle is wired into this app: opening a
 * connection must trigger the package's EncryptionMiddleware, which registers
 * the `encrypted_string` Doctrine type. The package ships dormant, so this
 * consumer-side test is the end-to-end check that the bundle registered.
 */
final class EncryptedStringTypeRegistrationTest extends KernelTestCase
{
    public function test_encrypted_string_type_is_registered_on_connection(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        // Force the lazy connection so the DBAL middleware's wrap() runs.
        $connection->executeQuery('SELECT 1');

        self::assertTrue(Type::hasType(EncryptedStringType::NAME));
    }
}
