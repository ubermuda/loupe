<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
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
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = new User(fullName: 'Admin', email: 'widget-admin@example.com', password: 'x');
        $admin->roles = ['ROLE_ADMIN'];
        $admin->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/projects');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(self::WIDGET));
    }
}
