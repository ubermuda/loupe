<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;
use App\Module\Account\Service\ExpiredExportPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExpiredExportPurgerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $projectDir;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $this->projectDir = $projectDir;
    }

    public function test_purges_expired_exports_and_their_files(): void
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

        $expiredPath = DataExport::computeArchivePath($this->projectDir, $expiredId);
        if (!is_dir(\dirname($expiredPath))) {
            mkdir(\dirname($expiredPath), 0770, true);
        }
        file_put_contents($expiredPath, 'archive bytes');

        $purger = self::getContainer()->get(ExpiredExportPurger::class);
        $count = $purger->purge();

        self::assertSame(1, $count);
        self::assertFileDoesNotExist($expiredPath);

        $this->em->clear();
        self::assertNull($this->em->find(DataExport::class, $expiredId));
        self::assertNotNull($this->em->find(DataExport::class, $stillValidId));
        self::assertNotNull($this->em->find(DataExport::class, $pendingId));
    }
}
