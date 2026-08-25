<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Module\Account\Entity\User;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowAboutControllerTest extends WebTestCase
{
    public function test_anonymous_visitor_reaches_the_source_offer(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/about');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="https://github.com/ubermuda/loupe"]');
        self::assertSelectorNotExists('[data-testid="app-version"]');
        self::assertSelectorExists('a[href="https://ubermuda.github.io/loupe/"]');
    }

    public function test_signed_in_visitor_also_sees_the_build_version(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('Aboutviewer', 'about-viewer@example.com');
        AcceptedTerms::stamp($user, static::getContainer());
        $user->password = 'hashed-password-placeholder';
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/about');

        self::assertResponseIsSuccessful();
        // No release build produced this checkout, so the card says so rather
        // than inventing a version — see BuildIdentity.
        self::assertSelectorTextSame('[data-testid="app-version"]', 'Built from source');
        self::assertSelectorNotExists('[data-testid="update-status"]');
    }
}
