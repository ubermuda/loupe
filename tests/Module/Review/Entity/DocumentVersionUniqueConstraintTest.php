<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Entity;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Asserts the (document_id, version_number) unique index exists in the DB
 * rather than trying to trigger a violation: a constraint failure inside
 * dama/doctrine-test-bundle's wrapping transaction poisons the rest of the
 * test.
 */
final class DocumentVersionUniqueConstraintTest extends KernelTestCase
{
    public function test_unique_index_on_document_id_and_version_number_exists(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);

        $indexName = $connection->fetchOne(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'document_versions' AND indexname = 'uniq_document_version_number'",
        );

        self::assertSame('uniq_document_version_number', $indexName);
    }
}
