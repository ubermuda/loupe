<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Controller\Admin;

use App\Module\Account\Entity\User;
use App\Module\Billing\Entity\BetaInvite;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BetaInviteAdminControllerTest extends WebTestCase
{
    public function test_a_non_admin_gets_403_on_every_action(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $plain = $this->seedUser($em, 'beta-plain@admin-test.example.com');
        $invite = $this->seedInvite($em, null, 'plain');

        $client->loginUser($plain);
        $client->request(Request::METHOD_GET, '/admin/beta-invites');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $client->request(Request::METHOD_POST, '/admin/beta-invites', ['create_beta_invite_form' => ['_token' => 'csrf-token']]);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $client->request(Request::METHOD_POST, '/admin/beta-invites/'.$invite->id.'/revoke', ['_csrf_token' => 'csrf-token']);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        self::assertSame(1, $this->countInvites());
        self::assertNull($this->row($invite)['revoked_at']);
    }

    public function test_an_admin_creates_a_link_and_the_list_keeps_it_copyable(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'beta-create-admin@admin-test.example.com', ['ROLE_ADMIN']);

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/beta-invites');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('a[href="/admin/beta-invites"]');
        $this->assertSelectorNotExists('[data-testid="beta-invite-link"]');

        $form = $crawler->filter('[data-testid="beta-invite-create"]')->form([
            'create_beta_invite_form[note]' => 'reddit u/foo',
        ]);
        $client->submit($form);

        $this->assertResponseRedirects('/admin/beta-invites');
        $crawler = $client->followRedirect();

        $link = $crawler->filter('[data-testid="beta-invite-link"]');
        self::assertCount(1, $link);
        self::assertSame(1, preg_match('#^https?://[^/]+/beta/([0-9a-f]{64})$#', trim($link->text()), $matches));
        $token = $matches[1] ?? throw new \LogicException('the link carries a token');

        $rows = $this->em()->getConnection()->fetchAllAssociative('SELECT token, note, created_by_id FROM beta_invites');
        self::assertCount(1, $rows);
        self::assertSame($token, $rows[0]['token']);
        self::assertSame('reddit u/foo', $rows[0]['note']);
        self::assertSame((string) $admin->id, $rows[0]['created_by_id']);
        $this->assertSelectorTextContains('[data-beta-invite-id] [data-testid="beta-invite-note"]', 'reddit u/foo');

        $client->request(Request::METHOD_GET, '/admin/beta-invites');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="beta-invite-link"]');
        $this->assertSelectorExists('[data-beta-invite-id] [data-clipboard-text-value$="/beta/'.$token.'"] [data-testid="beta-invite-copy"]');
    }

    public function test_a_note_is_optional(): void
    {
        $client = static::createClient();
        $admin = $this->seedUser($this->em(), 'beta-nonote-admin@admin-test.example.com', ['ROLE_ADMIN']);

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/beta-invites');
        $client->submit($crawler->filter('[data-testid="beta-invite-create"]')->form());

        $this->assertResponseRedirects('/admin/beta-invites');
        self::assertSame(1, $this->countInvites());
    }

    public function test_a_create_with_a_bad_csrf_token_is_refused(): void
    {
        $client = static::createClient();
        $admin = $this->seedUser($this->em(), 'beta-csrf-admin@admin-test.example.com', ['ROLE_ADMIN']);

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/beta-invites');
        $client->request(Request::METHOD_POST, '/admin/beta-invites', [
            'create_beta_invite_form' => ['note' => 'forged', '_token' => 'not-a-valid-token'],
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorNotExists('[data-testid="beta-invite-link"]');
        self::assertSame(0, $this->countInvites());
    }

    public function test_the_list_shows_each_state(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'beta-list-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $redeemer = $this->seedUser($em, 'beta-list-redeemer@admin-test.example.com');

        $unused = $this->seedInvite($em, $admin, 'unused one');
        $used = $this->seedInvite($em, $admin, 'used one');
        $used->redeem($redeemer);
        $revoked = $this->seedInvite($em, $admin, 'revoked one');
        $revoked->revoke();
        $em->flush();
        $em->clear();

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/beta-invites');
        $this->assertResponseIsSuccessful();

        $unusedRow = '[data-beta-invite-id="'.$unused->id.'"]';
        $usedRow = '[data-beta-invite-id="'.$used->id.'"]';
        $revokedRow = '[data-beta-invite-id="'.$revoked->id.'"]';

        $this->assertSelectorTextContains($unusedRow.' [data-testid="beta-invite-state"]', 'Unused');
        $this->assertSelectorExists($unusedRow.' [data-testid="beta-invite-revoke"]');
        $this->assertSelectorExists($unusedRow.' [data-testid="beta-invite-copy"]');

        $this->assertSelectorTextContains($usedRow.' [data-testid="beta-invite-state"]', 'Used by beta-list-redeemer@admin-test.example.com on');
        $this->assertSelectorNotExists($usedRow.' [data-testid="beta-invite-revoke"]');
        $this->assertSelectorNotExists($usedRow.' [data-testid="beta-invite-copy"]');

        $this->assertSelectorTextContains($revokedRow.' [data-testid="beta-invite-state"]', 'Revoked on');
        $this->assertSelectorNotExists($revokedRow.' [data-testid="beta-invite-revoke"]');
        $this->assertSelectorNotExists($revokedRow.' [data-testid="beta-invite-copy"]');
    }

    public function test_an_admin_revokes_an_unused_link(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'beta-revoke-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $invite = $this->seedInvite($em, $admin, 'to revoke');

        $client->loginUser($admin);
        $this->revoke($client, $invite, 'csrf-token');

        $this->assertResponseRedirects('/admin/beta-invites');
        $client->followRedirect();
        self::assertStringContainsString('The link is revoked.', (string) $client->getResponse()->getContent());
        self::assertNotNull($this->row($invite)['revoked_at']);
    }

    public function test_revoking_a_used_link_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'beta-used-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $redeemer = $this->seedUser($em, 'beta-used-redeemer@admin-test.example.com');
        $invite = $this->seedInvite($em, $admin, 'used');
        $invite->redeem($redeemer);
        $em->flush();

        $client->loginUser($admin);
        $this->revoke($client, $invite, 'csrf-token');

        $this->assertResponseRedirects('/admin/beta-invites');
        $client->followRedirect();
        self::assertStringContainsString('This link is already used', (string) $client->getResponse()->getContent());
        self::assertNull($this->row($invite)['revoked_at']);
    }

    public function test_a_revoke_with_a_bad_csrf_token_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'beta-revcsrf-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $invite = $this->seedInvite($em, $admin, 'kept');

        $client->loginUser($admin);
        $this->revoke($client, $invite, 'not-a-valid-token');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNull($this->row($invite)['revoked_at']);
    }

    public function test_the_admin_user_page_marks_a_beta_tester(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'beta-panel-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $tester = $this->seedUser($em, 'beta-panel-tester@admin-test.example.com');
        $other = $this->seedUser($em, 'beta-panel-other@admin-test.example.com');
        $invite = $this->seedInvite($em, $admin, 'reddit u/tester');
        $invite->redeem($tester);
        $em->flush();

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/users/'.$tester->id);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="beta-panel"]', 'Beta tester since');
        $this->assertSelectorTextContains('[data-testid="beta-panel"]', 'reddit u/tester');

        $client->request(Request::METHOD_GET, '/admin/users/'.$other->id);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="beta-panel"]');
    }

    private function revoke(KernelBrowser $client, BetaInvite $invite, string $token): void
    {
        // A preceding request establishes BrowserKit history, so the stateless
        // CSRF sentinel is accepted as same-origin.
        $client->request(Request::METHOD_GET, '/admin/beta-invites');
        $client->request(Request::METHOD_POST, '/admin/beta-invites/'.$invite->id.'/revoke', ['_csrf_token' => $token]);
    }

    /** @return array<string, mixed> */
    private function row(BetaInvite $invite): array
    {
        $row = $this->em()->getConnection()->fetchAssociative(
            'SELECT revoked_at, redeemed_at FROM beta_invites WHERE id = :id',
            ['id' => (string) $invite->id],
        );
        self::assertIsArray($row);

        return $row;
    }

    private function countInvites(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM beta_invites');
    }

    private function seedInvite(EntityManagerInterface $em, ?User $createdBy, string $note): BetaInvite
    {
        [$invite] = BetaInvite::issue($createdBy, $note);
        $em->persist($invite);
        $em->flush();

        return $invite;
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
