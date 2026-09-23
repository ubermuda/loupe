<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\GitHub\Entity\GitHubHook;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Service\GitHubHookExporter;
use App\Module\GitHub\Service\GitHubInstallationExporter;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GitHubExportersTest extends KernelTestCase
{
    public function test_the_hook_export_covers_the_owner_hooks_and_omits_the_secret(): void
    {
        self::bootKernel();
        [$mine, $theirs] = [$this->project('mine'), $this->project('theirs')];
        $hook = new GitHubHook($mine, GitHubHook::newKey(), 'the-hook-secret', new \DateTimeImmutable('2026-09-23T10:00:00+00:00'));
        $hook->refused(new \DateTimeImmutable('2026-09-23T11:00:00+00:00'), 'bad_signature');
        $this->persist($hook, new GitHubHook($theirs, GitHubHook::newKey(), GitHubHook::newSecret()));
        $exporter = self::getContainer()->get(GitHubHookExporter::class);
        self::assertInstanceOf(GitHubHookExporter::class, $exporter);

        $rows = [...$exporter->export($mine->owner)];

        self::assertSame('github_hooks.json', $exporter->filename());
        self::assertSame([[
            'project' => $mine->name,
            'hookKey' => $hook->hookKey,
            'lastAcceptedAt' => null,
            'lastRefusedAt' => '2026-09-23T11:00:00+00:00',
            'lastRefusedReason' => 'bad_signature',
            'createdAt' => '2026-09-23T10:00:00+00:00',
        ]], $rows);
        self::assertStringNotContainsString('the-hook-secret', json_encode($rows, \JSON_THROW_ON_ERROR));
    }

    public function test_the_installation_export_covers_the_owner_installations(): void
    {
        self::bootKernel();
        [$mine, $theirs] = [$this->project('mine'), $this->project('theirs')];
        $installation = new GitHubInstallation($mine, 7_000_001, 'acme', GitHubRepositorySelection::Selected, new \DateTimeImmutable('2026-09-23T10:00:00+00:00'));
        $installation->removedAt = new \DateTimeImmutable('2026-09-23T12:00:00+00:00');
        $this->persist($installation, new GitHubInstallation($theirs, 7_000_002, 'other', GitHubRepositorySelection::All));
        $exporter = self::getContainer()->get(GitHubInstallationExporter::class);
        self::assertInstanceOf(GitHubInstallationExporter::class, $exporter);

        $rows = [...$exporter->export($mine->owner)];

        self::assertSame('github_installations.json', $exporter->filename());
        self::assertSame([[
            'project' => $mine->name,
            'installationId' => 7_000_001,
            'accountLogin' => 'acme',
            'repositorySelection' => 'selected',
            'suspendedAt' => null,
            'removedAt' => '2026-09-23T12:00:00+00:00',
            'createdAt' => '2026-09-23T10:00:00+00:00',
        ]], $rows);
    }

    private function project(string $label): Project
    {
        $user = new User(fullName: 'Riley', email: 'github-export-'.$label.'-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($user, $label.'-'.uniqid());
        $this->persist($user, $project);

        return $project;
    }

    private function persist(object ...$entities): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        foreach ($entities as $entity) {
            $em->persist($entity);
        }
        $em->flush();
    }
}
