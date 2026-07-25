<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller\Admin;

use App\Module\Account\Entity\User;
use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\WaitlistEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ListWaitlistControllerTest extends WebTestCase
{
    public function test_admin_gets_200(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'waitlist-admin@admin-test.example.com', ['ROLE_ADMIN']);

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/waitlist');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Waitlist');
    }

    public function test_logged_in_non_admin_gets_403(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->seedUser($em, 'waitlist-plain-user@admin-test.example.com');

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/admin/waitlist');

        $this->assertResponseStatusCodeSame(403);
    }

    public function test_anonymous_is_redirected_to_login(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/admin/waitlist');

        $this->assertResponseRedirects();
        $this->assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function test_unknown_sort_falls_back_to_the_default(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'waitlist-sort-admin@admin-test.example.com', ['ROLE_ADMIN']);

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/waitlist?sort=not-a-real-column');

        $this->assertResponseIsSuccessful();
    }

    public function test_admin_can_invite_a_single_entry_from_its_row_button(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'waitlist-invite-admin@admin-test.example.com', ['ROLE_ADMIN']);

        $entry = new WaitlistEntry('invite-target@example.com');
        $em->persist($entry);
        $em->flush();
        $entryId = (string) $entry->id;

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/waitlist');
        $client->submitForm('Invite', [], 'POST');

        $this->assertResponseRedirects('/admin/waitlist');

        $entries = static::getContainer()->get(WaitlistEntryRepository::class);
        $reloaded = $entries->find($entryId);
        $this->assertNotNull($reloaded?->invitedAt);
        $this->assertEmailCount(1);

        // The row's Invite button/form no longer renders once invited — the
        // already-invited/converted skip behaviour itself is covered by
        // InviteWaitlistEntriesHandlerTest.
        $client->request(Request::METHOD_GET, '/admin/waitlist');
        $this->assertSelectorNotExists('form[action="/admin/waitlist/invite"] input[value="'.$entryId.'"]');
    }

    /**
     * @param non-empty-string $email
     * @param list<string>     $roles
     */
    private function seedUser(EntityManagerInterface $em, string $email, array $roles = []): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User(username: 'u'.bin2hex(random_bytes(4)), fullName: 'Test User', email: $email);
        $user->password = $hasher->hashPassword($user, 'TestPass123!');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $user->roles = $roles;
        $em->persist($user);
        $em->flush();
        $em->clear();

        return $em->find(User::class, $user->id) ?? throw new \LogicException('User not found after clear');
    }
}
