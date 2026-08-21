<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Security;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The active-account cases are positive controls: without them a 401 proves
 * nothing, since a wrong path or an unissued token produces one too.
 */
final class SuspendedAccountTokenAccessTest extends WebTestCase
{
    private const string INIT = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}';

    public function test_a_suspended_account_cannot_use_its_api_token(): void
    {
        $client = static::createClient();
        $raw = $this->issueToken($client, ApiTokenScope::SiteReview, suspended: true);

        $this->callSiteReviewApi($client, $raw);

        self::assertResponseStatusCodeSame(401);
    }

    public function test_an_active_account_can_use_its_api_token(): void
    {
        $client = static::createClient();
        $raw = $this->issueToken($client, ApiTokenScope::SiteReview, suspended: false);

        $this->callSiteReviewApi($client, $raw);

        self::assertResponseIsSuccessful();
    }

    public function test_a_suspended_account_cannot_use_its_mcp_token(): void
    {
        $client = static::createClient();
        $raw = $this->issueToken($client, ApiTokenScope::Mcp, suspended: true);

        $this->callMcp($client, $raw);

        self::assertResponseStatusCodeSame(401);
    }

    public function test_an_active_account_can_use_its_mcp_token(): void
    {
        $client = static::createClient();
        $raw = $this->issueToken($client, ApiTokenScope::Mcp, suspended: false);

        $this->callMcp($client, $raw);

        self::assertResponseIsSuccessful();
    }

    private function callSiteReviewApi(KernelBrowser $client, string $raw): void
    {
        $client->request(Request::METHOD_GET, '/api/site-review/sites', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
        ]);
    }

    private function callMcp(KernelBrowser $client, string $raw): void
    {
        $client->request(Request::METHOD_POST, '/mcp', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
            'CONTENT_TYPE' => 'application/json',
        ], content: self::INIT);
    }

    private function issueToken(KernelBrowser $client, ApiTokenScope $scope, bool $suspended): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User(fullName: 'Riley Chen', email: $scope->value.'-suspension@example.com', password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        if ($suspended) {
            $user->suspendedAt = new \DateTimeImmutable();
            $user->suspendedReason = 'Spamming reviewers.';
        }

        $em->persist($user);
        $em->persist(new Project($user, 'riley-site'));
        [$token, $raw] = ApiToken::issue($user, 'tok', $scope);
        $em->persist($token);
        $em->flush();
        $em->clear();

        return $raw;
    }
}
