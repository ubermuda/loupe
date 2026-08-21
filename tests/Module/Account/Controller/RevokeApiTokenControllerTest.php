<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ApiTokenRepository;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class RevokeApiTokenControllerTest extends WebTestCase
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

    public function test_owner_can_revoke_their_own_token(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createVerifiedUser($em, 'alice', 'alice@example.com');

        [$token] = ApiToken::issue($owner, 'my token', ApiTokenScope::Mcp);
        $em->persist($token);
        $em->flush();
        $tokenId = $token->id;
        $em->clear();

        // A preceding authenticated GET establishes BrowserKit history and the
        // origin cookie so the 'csrf-token' sentinel passes the same-origin check.
        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects');

        $client->request(Request::METHOD_POST, '/account/api-tokens/'.(string) $tokenId.'/revoke', [
            '_csrf_token' => 'csrf-token',
        ]);
        self::assertResponseRedirects('/projects');

        // The token row survives (revoke, not delete) but is marked revoked and can
        // no longer authenticate.
        $em->clear();
        /** @var ApiTokenRepository $repo */
        $repo = static::getContainer()->get(ApiTokenRepository::class);
        $tokens = $repo->findBy(['owner' => $owner]);
        self::assertCount(1, $tokens);
        self::assertNotNull($tokens[0]->revokedAt);
    }

    public function test_revoke_with_return_to_redirects_there(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createVerifiedUser($em, 'returnto-owner', 'returnto@example.com');
        [$token] = ApiToken::issue($owner, 'project-tok', ApiTokenScope::SiteReview);
        $em->persist($token);
        $em->flush();
        $tokenId = $token->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects');

        // Only the /projects/ path prefix matters for the redirect — no project fixture needed.
        $returnTo = '/projects/some-project/connect';
        $client->request(Request::METHOD_POST, '/account/api-tokens/'.(string) $tokenId.'/revoke', [
            '_csrf_token' => 'csrf-token',
            'returnTo' => $returnTo,
        ]);

        self::assertResponseRedirects($returnTo);
    }

    public function test_revoke_rejects_off_site_return_to_and_falls_back(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createVerifiedUser($em, 'reject-owner', 'reject@example.com');
        [$token] = ApiToken::issue($owner, 'project-tok', ApiTokenScope::SiteReview);
        $em->persist($token);
        $em->flush();
        $tokenId = $token->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects');

        // A returnTo outside the /projects/ allow-list must be rejected (open-redirect
        // guard) and fall back to the projects index rather than honoured.
        $client->request(Request::METHOD_POST, '/account/api-tokens/'.(string) $tokenId.'/revoke', [
            '_csrf_token' => 'csrf-token',
            'returnTo' => '/account/settings',
        ]);

        self::assertResponseRedirects('/projects');
    }

    public function test_other_user_gets_404_on_revoke(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->createVerifiedUser($em, 'alice', 'alice@example.com');
        $other = $this->createVerifiedUser($em, 'eve', 'eve@example.com');

        // Give Alice a token that Eve will attempt to revoke.
        [$aliceToken] = ApiToken::issue($owner, 'alice token', ApiTokenScope::Mcp);
        $em->persist($aliceToken);
        $em->flush();
        $aliceTokenId = $aliceToken->id;
        $em->clear();

        // Eve authenticates and makes a GET so the CSRF sentinel passes; the
        // ownership check then runs and must yield 404 (not 403).
        $client->loginUser($other);
        $client->request(Request::METHOD_GET, '/projects');

        $client->request(Request::METHOD_POST, '/account/api-tokens/'.(string) $aliceTokenId.'/revoke', [
            '_csrf_token' => 'csrf-token',
        ]);
        self::assertResponseStatusCodeSame(404);
    }
}
