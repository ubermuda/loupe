<?php

declare(strict_types=1);

namespace App\Tests\Security\EventListener;

use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Every endpoint guarded by #[CsrfToken] must reject an invalid or missing
 * token with a 403.
 *
 * The listener runs on kernel.controller — before entity argument resolution —
 * so these requests need no fixture entities behind the URLs. The valid-token
 * (pass-through) half is covered by the endpoints' own controller and e2e
 * tests, which submit a real signed token.
 */
final class ValidateCsrfTokenListenerTest extends WebTestCase
{
    private function createVerifiedUser(): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User('Csrf Guard', 'csrfguard@example.com');
        $user->password = $hasher->hashPassword($user, 'password');
        $user->emailVerifiedAt = new \DateTimeImmutable();

        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** @return iterable<string, array{string}> */
    public static function guardedEndpoints(): iterable
    {
        yield 'resend-verification' => ['/register/resend'];
        yield 'wizard-skip' => ['/welcome/skip'];
        yield 'wizard-finish' => ['/welcome/done/finish'];
        yield 'wizard-mint-mcp' => ['/welcome/connect/mcp-token'];
    }

    #[DataProvider('guardedEndpoints')]
    public function test_invalid_csrf_token_is_rejected_with_403(string $url): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedUser());

        $client->request(Request::METHOD_POST, $url, ['_csrf_token' => 'invalid-token']);

        $this->assertResponseStatusCodeSame(403);
    }

    #[DataProvider('guardedEndpoints')]
    public function test_missing_csrf_token_is_rejected_with_403(string $url): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedUser());

        $client->request(Request::METHOD_POST, $url);

        $this->assertResponseStatusCodeSame(403);
    }
}
