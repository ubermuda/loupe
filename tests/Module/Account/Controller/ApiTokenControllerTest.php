<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiTokenControllerTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function createVerifiedUser(EntityManagerInterface $em, string $username, string $email): User
    {
        $user = new User(
            username: $username,
            fullName: ucfirst($username),
            email: $email,
            password: 'hashed-password-placeholder',
        );
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function test_user_can_list_tokens_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser($em, 'bob', 'bob@example.com');

        $client->loginUser($user);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/account/api-tokens');
        self::assertResponseIsSuccessful();
    }

    public function test_user_can_create_a_token(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser($em, 'bob', 'bob@example.com');

        $client->loginUser($user);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/account/api-tokens');
        self::assertResponseIsSuccessful();

        $client->submitForm('Create token', ['api_token_form[label]' => 'Laptop agent']);
        self::assertResponseRedirects('/account/api-tokens');
        $client->followRedirect();
        self::assertSelectorTextContains('.bp-flash', 'Laptop agent');

        // Assert the token exists in the DB
        $em->clear();
        /** @var ApiTokenRepository $repo */
        $repo = static::getContainer()->get(ApiTokenRepository::class);
        $tokens = $repo->findBy(['owner' => $user]);
        self::assertCount(1, $tokens);
        self::assertSame('Laptop agent', $tokens[0]->label);
    }

    public function test_repository_find_one_by_raw_token_returns_token_for_valid_raw(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser($em, 'carol', 'carol@example.com');

        [$token, $raw] = ApiToken::issue($user, 'test token');
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

    public function test_owner_can_revoke_their_own_token(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createVerifiedUser($em, 'alice', 'alice@example.com');

        [$token] = ApiToken::issue($owner, 'my token');
        $em->persist($token);
        $em->flush();
        $tokenId = $token->id;
        $em->clear();

        // Load the list page to obtain a valid CSRF token
        $client->loginUser($owner);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/account/api-tokens');
        self::assertResponseIsSuccessful();

        $csrfTokenValue = $client->getCrawler()->filter('input[name="_csrf_token"]')->first()->attr('value');
        self::assertNotEmpty($csrfTokenValue, 'CSRF token must be rendered on the list page');

        // POST to revoke
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/account/api-tokens/'.(string) $tokenId.'/revoke', [
            '_csrf_token' => $csrfTokenValue,
        ]);
        self::assertResponseRedirects('/account/api-tokens');

        // Assert the token is gone from the DB
        $em->clear();
        /** @var ApiTokenRepository $repo */
        $repo = static::getContainer()->get(ApiTokenRepository::class);
        $tokens = $repo->findBy(['owner' => $owner]);
        self::assertCount(0, $tokens);
    }

    public function test_other_user_gets_404_on_revoke(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createVerifiedUser($em, 'alice', 'alice@example.com');
        $other = $this->createVerifiedUser($em, 'eve', 'eve@example.com');

        // Give Alice a token that Eve will attempt to revoke
        [$aliceToken] = ApiToken::issue($owner, 'alice token');
        $em->persist($aliceToken);
        // Give Eve a token so her page renders revoke forms (giving us a valid CSRF token)
        [$eveToken] = ApiToken::issue($other, 'eve token');
        $em->persist($eveToken);
        $em->flush();
        $aliceTokenId = $aliceToken->id;
        $em->clear();

        // Eve loads her list page (which renders revoke forms with valid CSRF tokens)
        $client->loginUser($other);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/account/api-tokens');
        self::assertResponseIsSuccessful();

        // Extract the CSRF token from Eve's revoke form
        $csrfTokenValue = $client->getCrawler()->filter('input[name="_csrf_token"]')->first()->attr('value');
        self::assertNotEmpty($csrfTokenValue, 'CSRF token must be rendered on the list page');

        // Eve tries to revoke Alice's token — must get 404 (ownership check), not 403
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/account/api-tokens/'.(string) $aliceTokenId.'/revoke', [
            '_csrf_token' => $csrfTokenValue,
        ]);
        self::assertResponseStatusCodeSame(404);
    }
}
