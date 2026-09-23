<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Service\ForgeRepositoryExporter;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ForgeRepositoryExporterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ForgeRepositoryExporter $exporter;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $exporter = self::getContainer()->get(ForgeRepositoryExporter::class);
        self::assertInstanceOf(ForgeRepositoryExporter::class, $exporter);
        $this->exporter = $exporter;
    }

    public function test_it_writes_one_file(): void
    {
        self::assertSame('forge_repositories.json', $this->exporter->filename());
    }

    public function test_it_exports_every_field_of_the_owner_repositories_only(): void
    {
        $owner = $this->user('mine');
        $stranger = $this->user('theirs');
        $mine = new Project($owner, 'mine-'.uniqid());
        $theirs = new Project($stranger, 'theirs-'.uniqid());
        $this->em->persist($mine);
        $this->em->persist($theirs);

        $createdAt = new \DateTimeImmutable('2026-09-01 09:00:00');
        $acceptedAt = new \DateTimeImmutable('2026-09-20 10:11:12');
        $repository = new ForgeRepository($mine, 'github', 'export-1', 'Acme/Widgets', ForgeRepositorySource::Installation, '77', $createdAt);
        $repository->lastAcceptedAt = $acceptedAt;
        $this->em->persist($repository);
        $this->em->persist(new ForgeRepository($theirs, 'github', 'export-2', 'acme/gadgets', ForgeRepositorySource::Hook));
        $this->em->flush();
        $this->em->clear();

        $rows = iterator_to_array($this->exporter->export($owner), false);

        self::assertSame([[
            'project' => $mine->name,
            'forge' => 'github',
            'externalId' => 'export-1',
            'path' => 'Acme/Widgets',
            'source' => 'installation',
            'sourceRef' => '77',
            'lastAcceptedAt' => $acceptedAt->format(\DateTimeInterface::ATOM),
            'createdAt' => $createdAt->format(\DateTimeInterface::ATOM),
        ]], $rows);
    }

    private function user(string $label): User
    {
        $user = new User(fullName: 'Riley', email: 'forge-export-'.$label.'-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($user);

        return $user;
    }
}
