<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Controller\Admin;

use App\Module\Account\Entity\User;
use App\Module\Billing\Command\Admin\GrantCompCommand;
use App\Module\Billing\Command\Admin\GrantCompHandler;
use App\Module\Billing\Entity\SubscriptionKind;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\BillingScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CompControllerTest extends WebTestCase
{
    /**
     * The comp action carries its own voter attribute, so a signed-in account
     * without it is refused even though the route sits in the admin area.
     */
    public function test_an_account_without_the_comp_attribute_gets_403(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $plain = $this->seedUser($em, 'comp-plain@admin-test.example.com');
        $target = $this->seedUser($em, 'comp-plain-target@admin-test.example.com');

        $client->loginUser($plain);
        $this->post($client, $target, 'comp');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNull($this->currentComp($target));
    }

    public function test_an_admin_grants_a_comp_and_returns_to_the_detail_page(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'comp-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'comp-grant-target@admin-test.example.com');

        $client->loginUser($admin);
        $this->post($client, $target, 'comp');

        $this->assertResponseRedirects('/admin/users/'.$target->id);
        $client->followRedirect();
        self::assertStringContainsString('This account is now comped.', (string) $client->getResponse()->getContent());

        $comp = $this->currentComp($target);
        self::assertNotNull($comp);
        self::assertNull($comp['ends_at']);
        self::assertSame((string) $admin->id, $comp['granted_by_id']);
    }

    public function test_a_second_grant_flashes_the_domain_error(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'comp-twice-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'comp-twice-target@admin-test.example.com');

        $client->loginUser($admin);
        $this->post($client, $target, 'comp');
        $this->post($client, $target, 'comp');

        $client->followRedirect();
        self::assertStringContainsString('already holds a comp', (string) $client->getResponse()->getContent());
        self::assertSame(1, $this->countComps($target));
    }

    public function test_revoking_ends_the_comp_rather_than_deleting_it(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'comp-revoke-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'comp-revoke-target@admin-test.example.com');

        $client->loginUser($admin);
        $this->post($client, $target, 'comp');
        $this->post($client, $target, 'comp/revoke');

        $this->assertResponseRedirects('/admin/users/'.$target->id);
        $client->followRedirect();
        self::assertStringContainsString('The comp is revoked.', (string) $client->getResponse()->getContent());

        self::assertNull($this->currentComp($target));
        self::assertSame(1, $this->countComps($target));
    }

    public function test_the_panel_offers_the_grant_action_while_the_paywall_is_on(): void
    {
        $client = static::createClient();
        $em = $this->em();
        new BillingScenario(static::getContainer())->enableBilling();
        $admin = $this->seedUser($em, 'comp-panel-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'comp-panel-target@admin-test.example.com');

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/users/'.$target->id);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="comp-panel"]');
        $this->assertSelectorExists('[data-testid="comp-grant"]');
        $this->assertSelectorTextContains('[data-testid="comp-status"]', 'Not comped');
    }

    public function test_the_panel_abstains_while_the_paywall_is_off(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'comp-nopanel-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'comp-nopanel-target@admin-test.example.com');

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/users/'.$target->id);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="comp-panel"]');
    }

    public function test_a_comped_account_keeps_the_panel_while_the_paywall_is_off(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $admin = $this->seedUser($em, 'comp-offpanel-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'comp-offpanel-target@admin-test.example.com');
        static::getContainer()->get(GrantCompHandler::class)(new GrantCompCommand($target, $admin));

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/users/'.$target->id);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="comp-panel"]');
        $this->assertSelectorExists('[data-testid="comp-revoke"]');
    }

    private function post(KernelBrowser $client, User $target, string $action): void
    {
        // A preceding authenticated request establishes BrowserKit history, so
        // the stateless CSRF sentinel is accepted as same-origin.
        $client->request(Request::METHOD_GET, '/admin/users/'.$target->id);
        $client->request(Request::METHOD_POST, '/admin/users/'.$target->id.'/'.$action, ['_csrf_token' => 'csrf-token']);
    }

    /** @return array{ends_at: ?string, granted_by_id: ?string}|null */
    private function currentComp(User $target): ?array
    {
        $row = $this->comps($target, currentOnly: true);

        return [] === $row ? null : ['ends_at' => $row[0]['ends_at'], 'granted_by_id' => $row[0]['granted_by_id']];
    }

    private function countComps(User $target): int
    {
        return count($this->comps($target, currentOnly: false));
    }

    /** @return list<array<string, mixed>> */
    private function comps(User $target, bool $currentOnly): array
    {
        $em = $this->em();
        $em->clear();

        $sql = <<<'SQL'
            SELECT s.ends_at, s.granted_by_id
            FROM subscriptions s
            JOIN billing_profiles p ON p.id = s.billing_profile_id
            WHERE p.user_id = :id AND s.kind = :kind
            SQL;
        $parameters = ['id' => (string) $target->id, 'kind' => SubscriptionKind::Comp->value];

        if ($currentOnly) {
            // A PHP timestamp rather than NOW(), which Postgres resolves to the
            // transaction start: the test transaction opens before the revoke.
            $sql .= ' AND (s.ends_at IS NULL OR s.ends_at > :now)';
            $parameters['now'] = new \DateTimeImmutable()->format('Y-m-d H:i:s.u');
        }

        return $em->getConnection()->fetchAllAssociative($sql, $parameters);
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
