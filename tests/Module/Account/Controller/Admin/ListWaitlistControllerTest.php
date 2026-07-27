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
        $this->assertQueuedEmailCount(1);

        // The row's Invite button/form no longer renders once invited — the
        // already-invited/converted skip behaviour itself is covered by
        // InviteWaitlistEntriesHandlerTest.
        $client->request(Request::METHOD_GET, '/admin/waitlist');
        $this->assertSelectorNotExists('form[action="/admin/waitlist/invite"] input[value="'.$entryId.'"]');
    }

    public function test_admin_can_invite_multiple_selected_entries(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'waitlist-bulk-admin@admin-test.example.com', ['ROLE_ADMIN']);

        $first = new WaitlistEntry('bulk-first@example.com');
        $second = new WaitlistEntry('bulk-second@example.com');
        $em->persist($first);
        $em->persist($second);
        $em->flush();
        $firstId = (string) $first->id;
        $secondId = (string) $second->id;

        $client->loginUser($admin);
        // A preceding authenticated GET establishes BrowserKit history and the
        // origin cookie so the stateless CSRF sentinel passes.
        $client->request(Request::METHOD_GET, '/admin/waitlist');

        $client->request(Request::METHOD_POST, '/admin/waitlist/invite', [
            '_csrf_token' => 'csrf-token',
            'ids' => [$firstId, $secondId],
        ]);

        $this->assertResponseRedirects('/admin/waitlist');

        $entries = static::getContainer()->get(WaitlistEntryRepository::class);
        $reloadedFirst = $entries->find($firstId);
        $reloadedSecond = $entries->find($secondId);
        $this->assertNotNull($reloadedFirst?->invitedAt);
        $this->assertNotNull($reloadedSecond?->invitedAt);
        $this->assertQueuedEmailCount(2);
    }

    public function test_admin_can_invite_the_oldest_n_entries(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'waitlist-oldest-admin@admin-test.example.com', ['ROLE_ADMIN']);

        // Explicit, well-separated createdAt values — the timestamp column has
        // only second precision, so two entries created in the same request
        // would otherwise tie and make the "oldest" ordering non-deterministic.
        $oldest = new WaitlistEntry('oldest-invite@example.com', new \DateTimeImmutable('-2 days'));
        $newest = new WaitlistEntry('newest-invite@example.com', new \DateTimeImmutable('-1 day'));
        $em->persist($oldest);
        $em->persist($newest);
        $em->flush();
        $oldestId = (string) $oldest->id;
        $newestId = (string) $newest->id;

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/waitlist');

        $client->request(Request::METHOD_POST, '/admin/waitlist/invite-oldest', [
            '_csrf_token' => 'csrf-token',
            'invite_oldest_waitlist_form' => ['count' => '1'],
        ]);

        $this->assertResponseRedirects('/admin/waitlist');

        $entries = static::getContainer()->get(WaitlistEntryRepository::class);
        $reloadedOldest = $entries->find($oldestId);
        $reloadedNewest = $entries->find($newestId);
        $this->assertNotNull($reloadedOldest?->invitedAt);
        $this->assertNull($reloadedNewest?->invitedAt);
        $this->assertQueuedEmailCount(1);
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
