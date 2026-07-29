<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;
use App\Module\Account\Service\ExpiredExportPurger;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExpiredExportPurgerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FilesystemOperator $exportStorage;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $storage = self::getContainer()->get('test.export.storage');
        self::assertInstanceOf(FilesystemOperator::class, $storage);
        $this->exportStorage = $storage;
    }

    public function test_purges_expired_exports_and_their_archives(): void
    {
        $user = new User('alice', 'Alice A', 'alice@example.com', 'x');
        $this->em->persist($user);

        $expired = new DataExport($user);
        $expired->complete();
        $expired->expiresAt = new \DateTimeImmutable('-1 minute');
        $this->em->persist($expired);

        $stillValid = new DataExport($user);
        $stillValid->complete();
        $this->em->persist($stillValid);

        $pending = new DataExport($user);
        $this->em->persist($pending);

        $this->em->flush();
        $expiredId = $expired->id;
        $stillValidId = $stillValid->id;
        $pendingId = $pending->id;
        self::assertNotNull($expiredId);
        self::assertNotNull($stillValidId);
        self::assertNotNull($pendingId);

        $expiredKey = DataExport::computeArchiveKey($expiredId);
        $this->exportStorage->write($expiredKey, 'archive bytes');

        $purger = self::getContainer()->get(ExpiredExportPurger::class);
        $count = $purger->purge();

        self::assertSame(1, $count);
        self::assertFalse($this->exportStorage->fileExists($expiredKey));

        $this->em->clear();
        self::assertNull($this->em->find(DataExport::class, $expiredId));
        self::assertNotNull($this->em->find(DataExport::class, $stillValidId));
        self::assertNotNull($this->em->find(DataExport::class, $pendingId));
    }
}
