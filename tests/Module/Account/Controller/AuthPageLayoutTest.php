<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\InstalledInstance;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Registration signs a user in before their address is verified, so the pages
 * that tell them to go and check their inbox are rendered to somebody the
 * application already considers logged in. They must still be bare auth pages:
 * the sidebar offers projects, billing and settings that an unverified account
 * cannot reach.
 */
final class AuthPageLayoutTest extends WebTestCase
{
    public function test_check_email_renders_without_the_application_shell(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        InstalledInstance::ensure(static::getContainer());

        $user = new User(fullName: 'Riley Chen', email: 'unverified@example.test');
        AcceptedTerms::stamp($user, static::getContainer());
        $user->password = 'not-a-real-hash';
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, '/register/check-email');

        // Guarded on purpose: "no sidebar" is also true of a redirect and of an
        // error page, so the absence below proves nothing until the auth page is
        // known to have rendered.
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.auth-page'));

        self::assertCount(0, $crawler->filter('.lp-sidebar'));
    }

    /**
     * Paired with the test above so that its negative assertion keeps meaning
     * something: were `.lp-sidebar` renamed or dropped, "the auth page has no
     * sidebar" would start passing everywhere and silently stop testing anything.
     */
    public function test_an_application_page_still_renders_the_shell(): void
    {
        $client = static::createClient();
        $admin = InstalledInstance::ensure(static::getContainer());

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/account');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-sidebar'));
    }
}
