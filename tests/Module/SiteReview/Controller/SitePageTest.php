<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class SitePageTest extends WebTestCase
{
    public function test_owner_sees_site_page_with_mint_button(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'site-page-a@example.com');
        $site = new Site($owner, 'my-app');
        $em->persist($site);
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/site-review/sites/'.$site->id);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('my-app', (string) $client->getResponse()->getContent());
        self::assertCount(1, $crawler->selectButton('Mint widget token'));
    }

    public function test_non_owner_is_forbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'site-page-b@example.com');
        $other = $this->user($em, 'site-page-c@example.com');
        $site = new Site($owner, 'not-yours');
        $em->persist($site);
        $em->flush();

        $client->loginUser($other);
        $client->request(Request::METHOD_GET, '/site-review/sites/'.$site->id);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_mint_binds_a_site_review_token(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'site-page-d@example.com');
        $site = new Site($owner, 'mint-me');
        $em->persist($site);
        $em->flush();
        $siteId = $site->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/site-review/sites/'.$siteId);
        $client->submitForm('Mint widget token');

        self::assertResponseRedirects('/site-review/sites/'.$siteId);
        $em->clear();
        $fresh = $em->find(Site::class, $siteId);
        self::assertInstanceOf(Site::class, $fresh);
        self::assertNotNull($fresh->token);
        self::assertSame(ApiTokenScope::SiteReview, $fresh->token->scope);
    }

    public function test_second_mint_is_rejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'site-page-e@example.com');
        $site = new Site($owner, 'once-only');
        $em->persist($site);
        $em->flush();
        $siteId = $site->id;

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/site-review/sites/'.$siteId);
        $client->submitForm('Mint widget token');
        $em->clear();
        $freshAfterFirstMint = $em->find(Site::class, $siteId);
        self::assertInstanceOf(Site::class, $freshAfterFirstMint);
        $tokenId = $freshAfterFirstMint->token?->id;
        self::assertNotNull($tokenId);

        // The page now shows Revoke, not Mint — POST the mint route directly to simulate a race.
        // 'csrf-token' is the SameOriginCsrfTokenManager sentinel: the preceding GET establishes
        // BrowserKit history so the Referer header passes the same-origin check automatically.
        $client->request(Request::METHOD_POST, '/site-review/sites/'.$siteId.'/token', ['_csrf_token' => 'csrf-token']);

        self::assertResponseRedirects('/site-review/sites/'.$siteId);
        $em->clear();
        $freshAfterSecondMint = $em->find(Site::class, $siteId);
        self::assertInstanceOf(Site::class, $freshAfterSecondMint);
        self::assertSame((string) $tokenId, (string) $freshAfterSecondMint->token?->id, 'token must be unchanged');
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(username: $email, fullName: 'U', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);

        return $user;
    }
}
