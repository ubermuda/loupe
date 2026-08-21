<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowAccountSettingsControllerTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function createVerifiedUser(EntityManagerInterface $em, string $username, string $email): User
    {
        $user = new User(
            fullName: ucfirst($username),
            email: $email,
            password: 'hashed-password-placeholder',
        );
        AcceptedTerms::stamp($user, static::getContainer());
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function test_logged_in_user_sees_the_export_section(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser($em, 'alice', 'alice@example.com');

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/account');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="export-section"]');
    }

    public function test_anonymous_user_is_redirected_to_login(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/account');

        self::assertResponseRedirects('/login');
    }
}
