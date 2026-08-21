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

final class SuspendUserControllerTest extends WebTestCase
{
    public function test_logged_in_non_admin_gets_403(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $plain = $this->seedUser($em, 'suspend-plain@admin-test.example.com');
        $target = $this->seedUser($em, 'suspend-plain-target@admin-test.example.com');

        $client->loginUser($plain);
        $this->post($client, $target, ['reason' => 'nope']);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $em->clear();
        self::assertNull($this->reload($em, $target)->suspendedAt);
    }

    public function test_it_suspends_with_a_reason_and_returns_to_the_detail_page(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'suspend-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'suspend-ok-target@admin-test.example.com');

        $client->loginUser($admin);
        $this->post($client, $target, ['reason' => 'Repeated spam']);

        $this->assertResponseRedirects('/admin/users/'.$target->id);
        $client->followRedirect();
        self::assertStringContainsString('Account suspended.', (string) $client->getResponse()->getContent());

        $em->clear();
        $reloaded = $this->reload($em, $target);
        self::assertNotNull($reloaded->suspendedAt);
        self::assertSame('Repeated spam', $reloaded->suspendedReason);
        self::assertNotNull($reloaded->suspendedBy);
        self::assertSame('suspend-admin@admin-test.example.com', $reloaded->suspendedBy->email);
    }

    public function test_it_honours_a_valid_return_to(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'suspend-return-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'suspend-return-target@admin-test.example.com');

        $client->loginUser($admin);
        $this->post($client, $target, ['returnTo' => '/admin/users?role=user']);

        $this->assertResponseRedirects('/admin/users?role=user');
    }

    public function test_a_guard_violation_flashes_and_mutates_nothing(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'suspend-self-admin@admin-test.example.com', ['ROLE_ADMIN']);

        $client->loginUser($admin);
        $this->post($client, $admin, ['reason' => 'Suspending myself']);

        $this->assertResponseRedirects('/admin/users/'.$admin->id);
        $client->followRedirect();
        self::assertStringContainsString('You cannot delete or suspend your own account.', (string) $client->getResponse()->getContent());

        $em->clear();
        self::assertNull($this->reload($em, $admin)->suspendedAt);
    }

    public function test_an_over_long_reason_flashes_and_mutates_nothing(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'suspend-long-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'suspend-long-target@admin-test.example.com');

        $client->loginUser($admin);
        $this->post($client, $target, ['reason' => str_repeat('a', User::MAX_SUSPENDED_REASON_LENGTH + 1)]);

        $this->assertResponseRedirects('/admin/users/'.$target->id);
        $client->followRedirect();
        self::assertStringContainsString('The suspension reason is too long.', (string) $client->getResponse()->getContent());

        $em->clear();
        self::assertNull($this->reload($em, $target)->suspendedAt);
    }

    /** @param array{reason?: string, returnTo?: string} $options */
    private function post(KernelBrowser $client, User $target, array $options = []): void
    {
        $parameters = [
            '_csrf_token' => 'csrf-token',
            'suspend_user_form' => ['reason' => $options['reason'] ?? ''],
        ];
        if (isset($options['returnTo'])) {
            $parameters['returnTo'] = $options['returnTo'];
        }

        // A preceding authenticated request establishes BrowserKit history, so
        // the stateless CSRF sentinel is accepted as same-origin.
        $client->request(Request::METHOD_GET, '/admin/users/'.$target->id);
        $client->request(Request::METHOD_POST, '/admin/users/'.$target->id.'/suspend', $parameters);
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
