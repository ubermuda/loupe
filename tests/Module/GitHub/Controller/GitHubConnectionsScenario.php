<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Controller;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\GitHub\Entity\GitHubHook;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

/** Fixtures the tests of the Repositories section share. */
trait GitHubConnectionsScenario
{
    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function signedUpUser(string $label): User
    {
        $user = new User(fullName: 'Riley', email: 'repositories-'.$label.'-'.uniqid().'@example.com', password: 'hashed');
        AcceptedTerms::stamp($user, self::getContainer());
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function projectOf(User $owner): Project
    {
        $project = new Project($owner, 'repositories-'.uniqid());
        $this->em()->persist($project);
        $this->em()->flush();

        return $project;
    }

    private function hookOf(Project $project): GitHubHook
    {
        $hook = new GitHubHook($project, GitHubHook::newKey(), GitHubHook::newSecret());
        $this->em()->persist($hook);
        $this->em()->flush();

        return $hook;
    }

    private function repositoryOf(Project $project, string $path, ?\DateTimeImmutable $lastAcceptedAt = null): ForgeRepository
    {
        $repository = new ForgeRepository($project, 'github', (string) random_int(1, 1_000_000_000), $path);
        $repository->lastAcceptedAt = $lastAcceptedAt;
        $this->em()->persist($repository);
        $this->em()->flush();

        return $repository;
    }

    private function connectPage(KernelBrowser $client, Project $project): Crawler
    {
        return $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/connect');
    }

    /** Posts a valid same-origin CSRF token, so only the voter can refuse. */
    private function postAction(KernelBrowser $client, string $path): void
    {
        $client->request(Request::METHOD_POST, $path, ['_csrf_token' => 'csrf-token'], server: ['HTTP_ORIGIN' => 'http://localhost']);
    }
}
