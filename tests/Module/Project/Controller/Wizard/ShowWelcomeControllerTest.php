<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller\Wizard;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowWelcomeControllerTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function createUser(EntityManagerInterface $em, string $username, string $email): User
    {
        $user = new User($username, ucfirst($username), $email);
        $user->password = 'hashed-password-placeholder';
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);

        return $user;
    }

    public function test_fresh_user_sees_step_one(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'wizfresh', 'wiz-fresh@example.com');
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/welcome');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('ol[data-wizard-step="1"]');
        self::assertSelectorExists('form[action$="/welcome/skip"]');
    }

    public function test_user_with_project_is_forwarded_to_connect_step(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'wizhas', 'wiz-has@example.com');
        $em->persist(new Project($user, 'existing'));
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/welcome');

        self::assertResponseRedirects('/welcome/connect');
    }

    public function test_completed_user_is_sent_home(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'wizdone', 'wiz-done@example.com');
        $user->wizardCompletedAt = new \DateTimeImmutable();
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/welcome');

        self::assertResponseRedirects('/');
    }

    public function test_submit_creates_project_and_advances(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'wizpost', 'wiz-post@example.com');
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/welcome');
        $client->submitForm('Create project', [
            'create_project_form[name]' => 'My first project',
        ]);

        self::assertResponseRedirects('/welcome/connect');
        $projects = static::getContainer()->get(ProjectRepository::class)->findByOwner($user);
        self::assertCount(1, $projects);
        self::assertSame('My first project', $projects[0]->name);
    }

    public function test_invalid_submit_rerenders_with_422(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'wizbad', 'wiz-bad@example.com');
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/welcome');
        $client->submitForm('Create project', [
            'create_project_form[name]' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function test_post_is_guarded_for_completed_users(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'wizpostdone', 'wiz-post-done@example.com');
        $user->wizardCompletedAt = new \DateTimeImmutable();
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_POST, '/welcome/project');

        self::assertResponseRedirects('/');
        self::assertCount(0, static::getContainer()->get(ProjectRepository::class)->findByOwner($user));
    }

    public function test_post_is_guarded_for_project_owners(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'wizposthas', 'wiz-post-has@example.com');
        $em->persist(new Project($user, 'existing'));
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_POST, '/welcome/project');

        self::assertResponseRedirects('/welcome/connect');
        self::assertCount(1, static::getContainer()->get(ProjectRepository::class)->findByOwner($user));
    }
}
