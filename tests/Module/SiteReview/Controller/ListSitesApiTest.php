<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Entity\Site;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ListSitesApiTest extends WebTestCase
{
    public function test_lists_only_callers_sites(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = new User(username: 'list-sites@example.com', fullName: 'Owner', email: 'list-sites@example.com', password: 'x');
        $owner->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($owner);
        [$token, $raw] = ApiToken::issue($owner, 'tok', ApiTokenScope::SiteReview);
        $em->persist($token);
        $site1 = new Site($owner, 'my-site-one');
        $site2 = new Site($owner, 'my-site-two');
        $em->persist($site1);
        $em->persist($site2);

        $other = new User(username: 'list-sites-other@example.com', fullName: 'Other', email: 'list-sites-other@example.com', password: 'x');
        $other->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($other);
        $otherSite = new Site($other, 'other-site');
        $em->persist($otherSite);

        $em->flush();

        $client->request(Request::METHOD_GET, '/api/site-review/sites',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('sites', $data);

        $returnedIds = array_column($data['sites'], 'id');
        self::assertContains((string) $site1->id, $returnedIds);
        self::assertContains((string) $site2->id, $returnedIds);
        self::assertNotContains((string) $otherSite->id, $returnedIds);
        self::assertCount(2, $data['sites']);
    }

    public function test_no_token_is_unauthorized(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/api/site-review/sites');
        self::assertResponseStatusCodeSame(401);
    }

    public function test_mcp_scoped_token_is_forbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User(username: 'list-sites-mcp@example.com', fullName: 'MCP', email: 'list-sites-mcp@example.com', password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $raw] = ApiToken::issue($user, 'mcp-tok', ApiTokenScope::Mcp);
        $em->persist($token);
        $em->flush();

        $client->request(Request::METHOD_GET, '/api/site-review/sites',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);

        self::assertResponseStatusCodeSame(403);
    }
}
