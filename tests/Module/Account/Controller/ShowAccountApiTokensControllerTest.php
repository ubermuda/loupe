<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowAccountApiTokensControllerTest extends WebTestCase
{
    public function test_the_section_renders_alone_with_its_nav_item_current(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedUser('tokens-section@example.com'));
        $crawler = $client->request(Request::METHOD_GET, '/account/api-tokens');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="api-tokens-section"]');
        self::assertSelectorNotExists('[data-testid="profile-section"]');
        self::assertSelectorNotExists('[data-testid="export-section"]');
        self::assertSelectorNotExists('[data-testid="delete-account-section"]');
        self::assertCount(3, $crawler->filter('.lp-settings-nav a.lp-settings-nav__item'));
        self::assertSelectorExists('.lp-settings-nav__item--active[aria-current="page"][href="/account/api-tokens"]');
        self::assertSelectorCount(1, '.lp-settings-nav__item[aria-current="page"]');
    }

    public function test_a_user_with_no_tokens_sees_the_empty_state_and_the_mint_form(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createVerifiedUser('tokenless@example.com'));
        $crawler = $client->request(Request::METHOD_GET, '/account/api-tokens');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="api-tokens-empty"]');
        self::assertSelectorExists('[data-testid="mint-api-token-form"]');
        self::assertCount(0, $crawler->filter('[data-token-id]'));
    }

    public function test_the_revoke_form_returns_to_this_section(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser('tokens-revoke@example.com');
        [$token] = ApiToken::issue($user, 'cli', ApiTokenScope::Agent);
        $em->persist($token);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request(Request::METHOD_GET, '/account/api-tokens');

        self::assertResponseIsSuccessful();
        self::assertSame('/account/api-tokens', $crawler->filter('[data-token-id] input[name="returnTo"]')->attr('value'));
    }

    public function test_anonymous_user_is_redirected_to_login(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/account/api-tokens');

        self::assertResponseRedirects('/login');
    }

    /** @param non-empty-string $email */
    private function createVerifiedUser(string $email): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User(fullName: 'Token Person', email: $email, password: 'hashed-password-placeholder');
        AcceptedTerms::stamp($user, static::getContainer());
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
