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

final class ResendVerificationControllerTest extends WebTestCase
{
    public function test_logged_in_non_admin_gets_403(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $plain = $this->seedUser($em, 'resend-plain@admin-test.example.com');
        $target = $this->seedUser($em, 'resend-plain-target@admin-test.example.com', verified: false);

        $client->loginUser($plain);
        $this->post($client, $target);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertQueuedEmailCount(0);
    }

    public function test_it_queues_a_verification_email_for_an_unverified_account(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'resend-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'resend-target@admin-test.example.com', verified: false);

        $client->loginUser($admin);
        $this->post($client, $target);

        $this->assertResponseRedirects('/admin/users/'.$target->id);
        self::assertQueuedEmailCount(1);

        $client->followRedirect();
        self::assertStringContainsString('Verification email sent.', (string) $client->getResponse()->getContent());
    }

    public function test_a_guard_violation_flashes_and_sends_nothing(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'resend-agent-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $agent = $em->find(User::class, Uuid::fromString(User::AGENT_ID));
        self::assertInstanceOf(User::class, $agent);

        $client->loginUser($admin);
        $this->post($client, $agent);

        $this->assertResponseRedirects('/admin/users/'.User::AGENT_ID);
        self::assertQueuedEmailCount(0);

        $client->followRedirect();
        self::assertStringContainsString('it cannot be changed or removed.', (string) $client->getResponse()->getContent());
    }

    private function post(KernelBrowser $client, User $target): void
    {
        // A preceding authenticated request establishes BrowserKit history, so
        // the stateless CSRF sentinel is accepted as same-origin.
        $client->request(Request::METHOD_GET, '/admin/users/'.$target->id);
        $client->request(Request::METHOD_POST, '/admin/users/'.$target->id.'/resend-verification', ['_csrf_token' => 'csrf-token']);
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
    private function seedUser(EntityManagerInterface $em, string $email, array $roles = [], bool $verified = true): User
    {
        $user = new User(fullName: 'Test User', email: $email, password: 'irrelevant-hash');
        AcceptedTerms::stamp($user, static::getContainer());
        if ($verified) {
            $user->emailVerifiedAt = new \DateTimeImmutable();
        }
        $user->roles = $roles;
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
