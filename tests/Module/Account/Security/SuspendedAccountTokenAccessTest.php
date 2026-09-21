<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Security;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Scope\ApiScope;
use App\Module\Project\Entity\Project;
use App\Tests\Support\AcceptedTerms;
use App\Tests\Support\AgentCredential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The active-account cases are positive controls: without them a 401 proves
 * nothing, since a wrong path or an unissued credential produces one too.
 */
final class SuspendedAccountTokenAccessTest extends WebTestCase
{
    private const string INIT = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}';

    public function test_a_suspended_account_cannot_use_its_access_token(): void
    {
        $client = static::createClient();
        $raw = $this->issueToken($client, ApiScope::Agent, suspended: true);

        $this->callAgentApi($client, $raw);

        self::assertResponseStatusCodeSame(401);
    }

    public function test_an_active_account_can_use_its_access_token(): void
    {
        $client = static::createClient();
        $raw = $this->issueToken($client, ApiScope::Agent, suspended: false);

        $this->callAgentApi($client, $raw);

        self::assertResponseIsSuccessful();
    }

    public function test_a_suspended_account_cannot_use_its_mcp_token(): void
    {
        $client = static::createClient();
        $raw = $this->issueToken($client, ApiScope::Mcp, suspended: true);

        $this->callMcp($client, $raw);

        self::assertResponseStatusCodeSame(401);
    }

    public function test_an_active_account_can_use_its_mcp_token(): void
    {
        $client = static::createClient();
        $raw = $this->issueToken($client, ApiScope::Mcp, suspended: false);

        $this->callMcp($client, $raw);

        self::assertResponseIsSuccessful();
    }

    private function callAgentApi(KernelBrowser $client, string $raw): void
    {
        $client->request(Request::METHOD_GET, '/api/projects', server: [
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

    /** The raw access token of an account suspended after it granted the scope. */
    private function issueToken(KernelBrowser $client, ApiScope $scope, bool $suspended): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $user = new User(fullName: 'Riley Chen', email: $scope->value.'-suspension@example.com', password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());

        $em->persist($user);
        $project = new Project($user, 'riley-site');
        $em->persist($project);
        $em->flush();

        // Consent runs on the main firewall, which diverts a suspended user, so
        // the grant has to come first.
        $raw = AgentCredential::tokenFor(static::getContainer(), $client, $user, $scope->value, ApiScope::Agent === $scope ? null : $project);

        if ($suspended) {
            $user = AgentCredential::managed($em, $user, $user->id);
            $user->suspendedAt = new \DateTimeImmutable();
            $user->suspendedReason = 'Spamming reviewers.';
            $em->flush();
        }

        $em->clear();

        return $raw;
    }
}
