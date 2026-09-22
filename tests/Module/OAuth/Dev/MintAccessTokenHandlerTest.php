<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Dev;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\Dev\MintAccessTokenCommand;
use App\Module\OAuth\Command\Dev\MintAccessTokenHandler;
use App\Module\OAuth\Scope\ApiScope;
use App\Module\Project\Entity\Project;
use App\Tests\Support\OAuthScenario;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The handler is a shortcut for other tests, so what it issues has to be a token
 * the firewall accepts. Every assertion here goes over HTTP, through the real
 * authenticator.
 */
final class MintAccessTokenHandlerTest extends WebTestCase
{
    private const string INIT = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}';

    public function test_an_agent_token_reaches_the_agent_surface_and_not_the_mcp_one(): void
    {
        $client = static::createClient();
        $scenario = new OAuthScenario(static::getContainer());
        $user = $scenario->createUser('minter-agent@example.com');

        $token = $this->mint($user, ['agent']);

        $client->request(Request::METHOD_GET, '/api/projects', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();

        $this->callMcp($client, $token);
        self::assertResponseStatusCodeSame(403);
    }

    public function test_an_mcp_token_bound_to_a_project_reaches_the_mcp_endpoint(): void
    {
        $client = static::createClient();
        $scenario = new OAuthScenario(static::getContainer());
        $user = $scenario->createUser('minter-mcp@example.com');
        $project = $scenario->createProject($user, 'Minted');

        $this->callMcp($client, $this->mint($user, [ApiScope::Mcp->value], $project));

        self::assertResponseIsSuccessful();
    }

    /** The audience of an mcp token is the endpoint, so a wrong-scope token is refused there. */
    public function test_a_site_review_token_is_refused_by_the_mcp_endpoint(): void
    {
        $client = static::createClient();
        $scenario = new OAuthScenario(static::getContainer());
        $user = $scenario->createUser('minter-widget@example.com');
        $project = $scenario->createProject($user, 'Minted widget');

        $this->callMcp($client, $this->mint($user, [ApiScope::SiteReview->value], $project));

        self::assertResponseStatusCodeSame(403);
    }

    /** An mcp grant with no project named covers every project of its owner. */
    public function test_an_mcp_token_with_no_project_covers_every_project_of_its_owner(): void
    {
        $client = static::createClient();
        $scenario = new OAuthScenario(static::getContainer());
        $user = $scenario->createUser('minter-all@example.com');
        $scenario->createProject($user, 'The only one');

        $this->callMcp($client, $this->mint($user, [ApiScope::Mcp->value]));

        self::assertResponseIsSuccessful();
    }

    /** The agent scope takes no binding, so naming a project is a caller mistake. */
    public function test_a_project_on_an_agent_scope_is_refused(): void
    {
        static::createClient();
        $scenario = new OAuthScenario(static::getContainer());
        $user = $scenario->createUser('minter-agent-project@example.com');
        $project = $scenario->createProject($user, 'Not for agent');

        $this->expectException(\LogicException::class);
        $this->mint($user, ['agent'], $project);
    }

    /** @param non-empty-list<string> $scopes */
    private function mint(User $user, array $scopes, ?Project $project = null): string
    {
        $handler = static::getContainer()->get(MintAccessTokenHandler::class);
        self::assertInstanceOf(MintAccessTokenHandler::class, $handler);

        return $handler(new MintAccessTokenCommand($user, $scopes, $project));
    }

    private function callMcp(KernelBrowser $client, string $token): void
    {
        $client->request(Request::METHOD_POST, '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: self::INIT);
    }
}
