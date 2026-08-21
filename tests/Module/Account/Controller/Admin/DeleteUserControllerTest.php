<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller\Admin;

use App\Module\Account\Entity\User;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class DeleteUserControllerTest extends WebTestCase
{
    public function test_logged_in_non_admin_gets_403(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $plain = $this->seedUser($em, 'delete-plain@admin-test.example.com');
        $target = $this->seedUser($em, 'delete-plain-target@admin-test.example.com');
        $targetId = $target->id ?? throw new \LogicException('seeded user has no id');

        $client->loginUser($plain);
        $this->post($client, $target);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $em->clear();
        self::assertNotNull($em->find(User::class, $targetId));
    }

    public function test_it_deletes_the_account_and_lands_on_the_list(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'delete-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'delete-ok-target@admin-test.example.com');
        $targetId = $target->id ?? throw new \LogicException('seeded user has no id');

        $client->loginUser($admin);
        $this->post($client, $target);

        $this->assertResponseRedirects('/admin/users');
        $client->followRedirect();
        self::assertStringContainsString('Account permanently deleted.', (string) $client->getResponse()->getContent());

        $em->clear();
        self::assertNull($em->find(User::class, $targetId));
    }

    public function test_it_honours_a_valid_return_to(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'delete-return-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'delete-return-target@admin-test.example.com');

        $client->loginUser($admin);
        $this->post($client, $target, '/admin/users?role=user');

        $this->assertResponseRedirects('/admin/users?role=user');
    }

    public function test_a_guard_violation_flashes_and_deletes_nothing(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'delete-guard-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $agent = $em->find(User::class, Uuid::fromString(User::AGENT_ID));
        self::assertInstanceOf(User::class, $agent);

        $client->loginUser($admin);
        $this->post($client, $agent);

        $this->assertResponseRedirects('/admin/users/'.User::AGENT_ID);
        $client->followRedirect();
        self::assertStringContainsString('it cannot be changed or removed.', (string) $client->getResponse()->getContent());

        $em->clear();
        self::assertNotNull($em->find(User::class, Uuid::fromString(User::AGENT_ID)));
    }

    public function test_it_refuses_to_delete_the_acting_admin(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'delete-self-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $adminId = $admin->id ?? throw new \LogicException('seeded user has no id');

        $client->loginUser($admin);
        $this->post($client, $admin);

        $this->assertResponseRedirects('/admin/users/'.$adminId);

        $em->clear();
        self::assertNotNull($em->find(User::class, $adminId));
    }

    private function post(KernelBrowser $client, User $target, ?string $returnTo = null): void
    {
        $parameters = ['_csrf_token' => 'csrf-token'];
        if (null !== $returnTo) {
            $parameters['returnTo'] = $returnTo;
        }

        // A preceding authenticated request establishes BrowserKit history, so
        // the stateless CSRF sentinel is accepted as same-origin.
        $client->request(Request::METHOD_GET, '/admin/users/'.$target->id);
        $client->request(Request::METHOD_POST, '/admin/users/'.$target->id.'/delete', $parameters);
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /**
     * @param non-empty-string $email
     * @param list<string>     $roles
     */
    private function seedUser(EntityManagerInterface $em, string $email, array $roles = []): User
    {
        $user = new User(fullName: 'Test User', email: $email, password: 'irrelevant-hash');
        AcceptedTerms::stamp($user, static::getContainer());
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $user->roles = $roles;
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
