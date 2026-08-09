<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Controller\Wizard;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class WizardFlowTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function createUser(EntityManagerInterface $em, string $username, string $email): User
    {
        $user = new User(ucfirst($username), $email);
        $user->password = 'hashed-password-placeholder';
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);

        return $user;
    }

    public function test_connect_without_project_bounces_to_step_one(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowconnectnop', 'flow-connect-nop@example.com');
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/welcome/connect');

        self::assertResponseRedirects('/welcome');
    }

    public function test_connect_for_completed_user_bounces_home(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowconnectdone', 'flow-connect-done@example.com');
        $user->wizardCompletedAt = new \DateTimeImmutable();
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/welcome/connect');

        self::assertResponseRedirects('/');
    }

    public function test_connect_renders_shared_mcp_instructions(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowconnectok', 'flow-connect-ok@example.com');
        $em->persist(new Project($user, 'flow-project'));
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, '/welcome/connect');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('claude mcp add', $crawler->text());
        self::assertSelectorExists('form[action$="/welcome/skip"]');
    }

    public function test_mint_flashes_raw_token_once(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowmint', 'flow-mint@example.com');
        $em->persist(new Project($user, 'flow-mint-project'));
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/welcome/connect');
        $client->submitForm('Generate token');

        self::assertResponseRedirects('/welcome/connect');
        $crawler = $client->followRedirect();
        self::assertSelectorExists('[data-testid="minted-mcp-token"]');

        $crawler = $client->request(Request::METHOD_GET, '/welcome/connect');
        self::assertSelectorNotExists('[data-testid="minted-mcp-token"]');
        self::assertStringContainsString('This project already has an MCP token.', $crawler->text());
    }

    public function test_widget_step_without_project_bounces_to_step_one(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowwidgetnop', 'flow-widget-nop@example.com');
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/welcome/widget');

        self::assertResponseRedirects('/welcome');
    }

    public function test_widget_step_mints_its_own_token_once(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowwidget', 'flow-widget@example.com');
        $em->persist(new Project($user, 'flow-widget-project'));
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, '/welcome/widget');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('ol[data-wizard-step="3"]');
        // The placeholder stands in until a token exists, so the snippet is
        // copyable-shaped from the first render.
        self::assertStringContainsString('site-review/widget.js', $crawler->text());

        $client->submitForm('Generate token');
        self::assertResponseRedirects('/welcome/widget');
        $client->followRedirect();
        self::assertSelectorExists('[data-testid="minted-widget-token"]');

        $crawler = $client->request(Request::METHOD_GET, '/welcome/widget');
        self::assertSelectorNotExists('[data-testid="minted-widget-token"]');
        self::assertStringContainsString('This project already has a site-review token.', $crawler->text());
    }

    public function test_done_renders_the_final_step_with_skip_and_finish(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowdone', 'flow-done@example.com');
        $em->persist(new Project($user, 'flow-done-project'));
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/welcome/done');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('ol[data-wizard-step="4"]');
        self::assertSelectorExists('form[action$="/welcome/skip"]');
        self::assertSelectorExists('form[action$="/welcome/done/finish"]');
    }

    public function test_skip_from_connect_completes_and_lands_on_projects(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowskip', 'flow-skip@example.com');
        $em->persist(new Project($user, 'flow-skip-project'));
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/welcome/connect');
        $client->submitForm('Skip setup');

        self::assertResponseRedirects('/projects');

        $em->clear();
        $fresh = $em->find(User::class, $user->id);
        self::assertInstanceOf(User::class, $fresh);
        self::assertNotNull($fresh->wizardCompletedAt);
    }

    public function test_finish_completes_and_lands_home(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowfinish', 'flow-finish@example.com');
        $em->persist(new Project($user, 'flow-finish-project'));
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/welcome/done');
        $client->submitForm('Go to dashboard');

        self::assertResponseRedirects('/');

        $em->clear();
        $fresh = $em->find(User::class, $user->id);
        self::assertInstanceOf(User::class, $fresh);
        self::assertNotNull($fresh->wizardCompletedAt);
    }

    public function test_skip_for_completed_user_redirects_home_without_error(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em, 'flowskipdone', 'flow-skip-done@example.com');
        // Truncated to whole seconds up front: the DB column has no fractional
        // seconds, so comparing against a microsecond-precision value created
        // in PHP would fail on the round trip regardless of handler behaviour.
        $completedAt = new \DateTimeImmutable(new \DateTimeImmutable('-1 hour')->format('Y-m-d H:i:s'));
        $user->wizardCompletedAt = $completedAt;
        $em->flush();

        $client->loginUser($user);
        // A preceding GET establishes BrowserKit history so the Referer header
        // passes SameOriginCsrfTokenManager's same-origin check for the sentinel.
        $client->request(Request::METHOD_GET, '/projects');
        $client->request(Request::METHOD_POST, '/welcome/skip', ['_csrf_token' => 'csrf-token']);

        self::assertResponseRedirects('/');

        $em->clear();
        $fresh = $em->find(User::class, $user->id);
        self::assertInstanceOf(User::class, $fresh);
        self::assertEquals($completedAt, $fresh->wizardCompletedAt);
    }
}
