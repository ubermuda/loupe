<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller\Admin;

use App\Module\Account\Entity\User;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\AdminUserPanelFixture;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ShowUserControllerTest extends WebTestCase
{
    private const string KNOWN_HASH = '$2y$13$xNOTAREALHASHxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    /** Edit form, actions and danger zone. A contributed panel adds one each. */
    private const int MAIN_COLUMN_CARDS = 3;

    public function test_admin_gets_200(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'detail-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'detail-target@admin-test.example.com', fullName: 'Trillian Astra');

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/users/'.$target->id);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Trillian Astra');
        $this->assertSelectorExists('[data-testid="danger-zone"]');
        $this->assertSelectorTextNotContains('body', 'account.admin.users.');
    }

    public function test_an_unverified_account_gets_a_resend_verification_form(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'detail-resend-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'detail-resend@admin-test.example.com');
        $target->emailVerifiedAt = null;
        $em->flush();
        $em->clear();

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/users/'.$target->id);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[action$="/resend-verification"]');
        $this->assertSelectorTextNotContains('body', 'account.admin.users.');
    }

    public function test_logged_in_non_admin_gets_403(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->seedUser($em, 'detail-plain@admin-test.example.com');

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/admin/users/'.$user->id);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_the_page_leaks_no_password_hash_or_token_hash(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'detail-secrets-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'detail-secrets@admin-test.example.com');

        $target->password = self::KNOWN_HASH;
        $verificationTokenHash = hash('sha256', $target->generateEmailVerificationToken());
        $em->flush();
        $em->clear();

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/users/'.$target->id);

        $this->assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('detail-secrets@admin-test.example.com', $body);
        self::assertStringNotContainsString(self::KNOWN_HASH, $body);
        self::assertStringNotContainsString($verificationTokenHash, $body);
    }

    public function test_it_names_the_admin_who_applied_a_suspension(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'detail-susp-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $byAdmin = $this->seedUser($em, 'detail-susp-known@admin-test.example.com');
        $byNobody = $this->seedUser($em, 'detail-susp-orphan@admin-test.example.com');

        foreach ([$byAdmin, $byNobody] as $suspended) {
            $suspended->suspendedAt = new \DateTimeImmutable();
            $suspended->suspendedReason = 'Repeated spam';
        }
        $byAdmin->suspendedBy = $admin;
        $em->flush();
        $em->clear();

        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/users/'.$byAdmin->id);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="suspension-details"]', 'detail-susp-admin@admin-test.example.com');
        $this->assertSelectorTextContains('[data-testid="suspension-details"]', 'Repeated spam');

        // suspendedBy is ON DELETE SET NULL, so the suspending admin may be gone.
        $client->request(Request::METHOD_GET, '/admin/users/'.$byNobody->id);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains(
            '[data-testid="suspension-details"]',
            'The administrator who suspended this account no longer exists.',
        );
    }

    public function test_the_back_link_honours_a_valid_return_to(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'detail-return-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'detail-return@admin-test.example.com');

        $client->loginUser($admin);
        $crawler = $client->request(
            Request::METHOD_GET,
            '/admin/users/'.$target->id.'?returnTo='.urlencode('/admin/users?role=user'),
        );

        $this->assertResponseIsSuccessful();
        self::assertSame('/admin/users?role=user', $crawler->filter('a[href^="/admin/users?"]')->attr('href'));
    }

    public function test_a_valid_submit_persists_and_redirects(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'detail-save-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'detail-save@admin-test.example.com');

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/users/'.$target->id);

        $form = $crawler->selectButton('Save changes')->form();
        $form['admin_user_form[fullName]'] = 'Ford Prefect';
        $this->checkbox($form, 'admin_user_form[isAdmin]')->tick();
        $client->submit($form);

        $this->assertResponseRedirects('/admin/users/'.$target->id);

        $em->clear();
        $reloaded = $em->find(User::class, $target->id);
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame('Ford Prefect', $reloaded->fullName);
        self::assertContains('ROLE_ADMIN', $reloaded->roles);
    }

    public function test_an_email_change_queues_a_verification_email_and_clears_verification(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'detail-mail-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'detail-mail@admin-test.example.com');

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/users/'.$target->id);

        $form = $crawler->selectButton('Save changes')->form();
        $form['admin_user_form[email]'] = 'detail-mail-new@admin-test.example.com';
        $client->submit($form);

        $this->assertResponseRedirects();
        self::assertQueuedEmailCount(1);

        $em->clear();
        $reloaded = $em->find(User::class, $target->id);
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame('detail-mail-new@admin-test.example.com', $reloaded->email);
        self::assertNull($reloaded->emailVerifiedAt);
    }

    public function test_a_blank_name_returns_422_with_a_field_error(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'detail-blank-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'detail-blank@admin-test.example.com');

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/users/'.$target->id);

        $form = $crawler->selectButton('Save changes')->form();
        $form['admin_user_form[fullName]'] = '';
        $client->submit($form);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function test_a_duplicate_email_returns_422_with_a_field_error(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'detail-dup-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'detail-dup@admin-test.example.com');

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/users/'.$target->id);

        $form = $crawler->selectButton('Save changes')->form();
        $form['admin_user_form[email]'] = 'detail-dup-admin@admin-test.example.com';
        $crawler = $client->submit($form);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString(
            'Another account already uses that email address.',
            (string) $client->getResponse()->getContent(),
        );

        $em->clear();
        $reloaded = $em->find(User::class, $target->id);
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame('detail-dup@admin-test.example.com', $reloaded->email);
    }

    /**
     * AdminUserGuard keys this failure 'roles', which is not a field on the form
     * — the page must report it rather than blow up looking the field up.
     */
    public function test_a_guard_violation_returns_422_with_a_flash(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'detail-guard-admin@admin-test.example.com', ['ROLE_ADMIN']);

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/users/'.$admin->id);

        $form = $crawler->selectButton('Save changes')->form();
        $this->checkbox($form, 'admin_user_form[isAdmin]')->untick();
        $client->submit($form);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString(
            'You cannot take away your own administrator role.',
            (string) $client->getResponse()->getContent(),
        );

        $em->clear();
        $reloaded = $em->find(User::class, $admin->id);
        self::assertInstanceOf(User::class, $reloaded);
        self::assertContains('ROLE_ADMIN', $reloaded->roles);
    }

    public function test_contributed_panels_render_in_their_tagged_priority_order(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'detail-panel-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'detail-'.AdminUserPanelFixture::MARKER.'@admin-test.example.com');

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/users/'.$target->id);

        $this->assertResponseIsSuccessful();
        self::assertSame(
            ['first', 'second'],
            $crawler->filter('[data-testid="admin-user-panel"]')->each(
                static fn ($node): string => (string) $node->attr('data-panel-label'),
            ),
        );
        self::assertCount(self::MAIN_COLUMN_CARDS + 2, $crawler->filter('.admin-edit-form > div'));
    }

    public function test_a_page_with_no_contributed_panels_renders_unchanged(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'detail-no-panel-admin@admin-test.example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser($em, 'detail-no-panel@admin-test.example.com');

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/users/'.$target->id);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="admin-user-panel"]');
        self::assertCount(self::MAIN_COLUMN_CARDS, $crawler->filter('.admin-edit-form > div'));
    }

    private function checkbox(Form $form, string $name): ChoiceFormField
    {
        $field = $form[$name];
        self::assertInstanceOf(ChoiceFormField::class, $field);

        return $field;
    }

    /**
     * @param non-empty-string $email
     * @param list<string>     $roles
     */
    private function seedUser(EntityManagerInterface $em, string $email, array $roles = [], string $fullName = 'Test User'): User
    {
        $user = new User(fullName: $fullName, email: $email, password: self::KNOWN_HASH);
        AcceptedTerms::stamp($user, static::getContainer());
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $user->roles = $roles;
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
