<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class HomeControllerTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function createUser(EntityManagerInterface $em, string $username, string $email): User
    {
        $user = new User(
            username: $username,
            fullName: ucfirst($username),
            email: $email,
            password: 'hashed-password-placeholder',
        );
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);

        return $user;
    }

    public function test_fresh_user_is_sent_to_the_wizard(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'home-fresh', 'home-fresh@example.com');
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/welcome');
    }

    public function test_wizard_completed_user_with_no_projects_lands_on_the_projects_list(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'home-none', 'home-none@example.com');
        $user->wizardCompletedAt = new \DateTimeImmutable();
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/projects');
    }

    public function test_mid_wizard_user_with_project_goes_to_its_documents(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'home-midwizard', 'home-midwizard@example.com');
        $project = new Project($user, 'mid-wizard-project');
        $em->persist($project);
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/projects/'.$projectId.'/documents');
    }

    public function test_user_with_one_project_lands_on_its_documents(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'home-one', 'home-one@example.com');
        $project = new Project($user, 'only-project');
        $em->persist($project);
        $em->flush();
        $projectId = (string) $project->id;
        $em->clear();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/projects/'.$projectId.'/documents');
    }

    public function test_user_with_multiple_projects_lands_on_the_projects_list(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'home-many', 'home-many@example.com');
        $em->persist(new Project($user, 'first'));
        $em->persist(new Project($user, 'second'));
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/projects');
    }
}
