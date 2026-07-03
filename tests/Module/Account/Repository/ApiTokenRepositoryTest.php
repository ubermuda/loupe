<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Repository;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiTokenRepositoryTest extends WebTestCase
{
    public function test_find_one_by_raw_token_returns_token_for_valid_raw(): void
    {
        static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User(
            username: 'carol',
            fullName: 'Carol',
            email: 'carol@example.com',
            password: 'hashed-password-placeholder',
        );
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        [$token, $raw] = ApiToken::issue($user, 'test token', ApiTokenScope::Mcp);
        $em->persist($token);
        $em->flush();
        $em->clear();

        /** @var ApiTokenRepository $repo */
        $repo = static::getContainer()->get(ApiTokenRepository::class);

        $found = $repo->findOneByRawToken($raw);
        self::assertNotNull($found);
        self::assertSame('test token', $found->label);

        $notFound = $repo->findOneByRawToken('wrong-raw-value');
        self::assertNull($notFound);
    }
}
