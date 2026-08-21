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

final class UnsuspendUserControllerTest extends WebTestCase
{
    public function test_logged_in_non_admin_gets_403(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $plain = $this->seedUser($em, 'unsuspend-plain@admin-test.example.com');
        $target = $this->seedUser($em, 'unsuspend-plain-target@admin-test.example.com');
        $this->suspend($em, $target, $plain);

        $client->loginUser($plain);
        $this->post($client, $target);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $em->clear();
        self::assertNotNull($this->reload($em, $target)->suspendedAt);
    }

    public function test_it_clears_the_suspension_and_returns_to_the_detail_page(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'unsuspend-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'unsuspend-ok-target@admin-test.example.com');
        $this->suspend($em, $target, $admin);

        $client->loginUser($admin);
        $this->post($client, $target);

        $this->assertResponseRedirects('/admin/users/'.$target->id);
        $client->followRedirect();
        self::assertStringContainsString('Suspension lifted.', (string) $client->getResponse()->getContent());

        $em->clear();
        $reloaded = $this->reload($em, $target);
        self::assertNull($reloaded->suspendedAt);
        self::assertNull($reloaded->suspendedReason);
        self::assertNull($reloaded->suspendedBy);
    }

    public function test_a_guard_violation_flashes_and_mutates_nothing(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'unsuspend-agent-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $agent = $em->find(User::class, Uuid::fromString(User::AGENT_ID));
        self::assertInstanceOf(User::class, $agent);
        $this->suspend($em, $agent, $admin);

        $client->loginUser($admin);
        $this->post($client, $agent);

        $this->assertResponseRedirects('/admin/users/'.User::AGENT_ID);
        $client->followRedirect();
        self::assertStringContainsString('it cannot be changed or removed.', (string) $client->getResponse()->getContent());

        $em->clear();
        self::assertNotNull($this->reload($em, $agent)->suspendedAt);
    }

    private function post(KernelBrowser $client, User $target): void
    {
        // A preceding authenticated request establishes BrowserKit history, so
        // the stateless CSRF sentinel is accepted as same-origin.
        $client->request(Request::METHOD_GET, '/admin/users/'.$target->id);
        $client->request(Request::METHOD_POST, '/admin/users/'.$target->id.'/unsuspend', ['_csrf_token' => 'csrf-token']);
    }

    private function suspend(EntityManagerInterface $em, User $target, User $actor): void
    {
        $target->suspendedAt = new \DateTimeImmutable();
        $target->suspendedReason = 'Repeated spam';
        $target->suspendedBy = $actor;
        $em->flush();
        $em->clear();
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

    private function reload(EntityManagerInterface $em, User $user): User
    {
        $reloaded = $em->find(User::class, $user->id ?? throw new \LogicException('seeded user has no id'));
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }
}
