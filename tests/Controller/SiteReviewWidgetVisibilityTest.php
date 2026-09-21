<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Account\Entity\User;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The widget invites comments an agent may act on, so who is offered it is a
 * security question rather than a cosmetic one. The test env mirrors production:
 * a token is configured and SITE_REVIEW_WIDGET_PUBLIC is not.
 */
final class SiteReviewWidgetVisibilityTest extends WebTestCase
{
    private const string WIDGET = 'script[src="/site-review/widget.js"]';

    private const string PROJECT_ID = '0199c0de-0000-7000-8000-00000000beef';

    #[\Override]
    protected function tearDown(): void
    {
        $this->setWidgetProject('');

        parent::tearDown();
    }

    public function test_a_signed_out_visitor_is_not_offered_the_widget(): void
    {
        $client = static::createClient();
        $crawler = $client->request(Request::METHOD_GET, '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(self::WIDGET));
    }

    public function test_a_signed_in_non_admin_is_not_offered_the_widget(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User(fullName: 'Plain', email: 'widget-plain@example.com', password: 'x');
        AcceptedTerms::stamp($user, static::getContainer());
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, '/projects');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(self::WIDGET));
    }

    public function test_an_admin_is_offered_the_widget(): void
    {
        $client = static::createClient();
        $this->signInAdmin($client, 'widget-admin@example.com');

        $crawler = $client->request(Request::METHOD_GET, '/projects');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(self::WIDGET));
        self::assertCount(1, $crawler->filter(self::WIDGET.'[data-token]'));
    }

    public function test_the_admin_area_offers_the_widget_from_its_own_layout(): void
    {
        $client = static::createClient();
        $this->signInAdmin($client, 'widget-admin-area@example.com');

        // The admin area renders from UbermudaAdminBundle's own base template,
        // which carries its own copy of the widget gate.
        $crawler = $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(self::WIDGET));
        self::assertCount(1, $crawler->filter(self::WIDGET.'[data-token]'));
    }

    /**
     * A token in the page source is public to every reader, so an instance
     * that names a project signs the reviewer in instead. The token stays
     * configured here, because the project must win over it.
     */
    public function test_a_configured_project_replaces_the_token(): void
    {
        $this->setWidgetProject(self::PROJECT_ID);
        $client = static::createClient();
        $this->signInAdmin($client, 'widget-project@example.com');

        $crawler = $client->request(Request::METHOD_GET, '/projects');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(self::WIDGET.'[data-project="'.self::PROJECT_ID.'"]'));
        self::assertCount(0, $crawler->filter(self::WIDGET.'[data-token]'));
    }

    public function test_the_admin_area_also_prefers_the_project(): void
    {
        $this->setWidgetProject(self::PROJECT_ID);
        $client = static::createClient();
        $this->signInAdmin($client, 'widget-project-admin-area@example.com');

        $crawler = $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(self::WIDGET.'[data-project="'.self::PROJECT_ID.'"]'));
        self::assertCount(0, $crawler->filter(self::WIDGET.'[data-token]'));
    }

    /** @param non-empty-string $email */
    private function signInAdmin(KernelBrowser $client, string $email): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = new User(fullName: 'Admin', email: $email, password: 'x');
        AcceptedTerms::stamp($admin, static::getContainer());
        $admin->roles = ['ROLE_ADMIN'];
        $admin->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);
    }

    /** Read as a Twig global through %env(...)%, so the request reads it. */
    private function setWidgetProject(string $projectId): void
    {
        $_ENV['SITE_REVIEW_WIDGET_PROJECT'] = $_SERVER['SITE_REVIEW_WIDGET_PROJECT'] = $projectId;
    }
}
