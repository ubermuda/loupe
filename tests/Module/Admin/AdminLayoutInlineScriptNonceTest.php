<?php

declare(strict_types=1);

namespace App\Tests\Module\Admin;

use App\Module\Account\Entity\User;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The CSP only enforces under `when@prod`, so a missing nonce costs nothing in
 * dev, test or e2e and silently kills every script in the admin area in
 * production. This asserts the markup instead of the header, which is the part
 * that differs between the app layout and the admin bundle's.
 */
final class AdminLayoutInlineScriptNonceTest extends WebTestCase
{
    /** @return list<array{string}> */
    public static function adminPages(): array
    {
        return [['/admin'], ['/admin/users']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('adminPages')]
    public function test_every_inline_script_in_the_admin_layout_carries_a_nonce(string $path): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $client->loginUser($this->seedAdmin($em, 'nonce-'.md5($path).'@admin-test.example.com'));
        $crawler = $client->request(Request::METHOD_GET, $path);

        $this->assertResponseIsSuccessful();

        $inline = $crawler->filter('script:not([src])');
        // Without this the assertion below passes on a page that renders no
        // inline script at all, which would prove nothing about the nonce.
        self::assertGreaterThan(0, $inline->count(), 'Expected the importmap to emit inline scripts.');

        $unnonced = $inline->reduce(
            static fn ($node): bool => '' === (string) $node->attr('nonce'),
        );

        self::assertSame(
            0,
            $unnonced->count(),
            sprintf('%d inline <script> on %s has no nonce and would be blocked by the production CSP.', $unnonced->count(), $path),
        );
    }

    /** @param non-empty-string $email */
    private function seedAdmin(EntityManagerInterface $em, string $email): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User(fullName: 'Nonce Admin', email: $email);
        AcceptedTerms::stamp($user, static::getContainer());
        $user->password = $hasher->hashPassword($user, 'TestPass123!');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $user->roles = ['ROLE_ADMIN'];
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
