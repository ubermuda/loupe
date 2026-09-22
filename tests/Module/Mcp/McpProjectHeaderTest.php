<?php

declare(strict_types=1);

namespace App\Tests\Module\Mcp;

use App\Module\Project\Entity\Project;
use App\Module\Project\Security\AuthenticatedProjectResolver;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The project header over real HTTP, through the firewall and the MCP server.
 *
 * A refusal must stay inside a 200 as a tool error. Loupe answers a request on
 * an ended session with 404, so a refusal that shared that status could not be
 * told apart from a transport failure.
 */
final class McpProjectHeaderTest extends WebTestCase
{
    public function test_a_bound_token_answers_a_header_naming_its_own_project(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->boundToken($client, 'own');

        $answer = $this->callBoundTool($client, $raw, (string) $project->id);

        self::assertFalse($answer['result']['isError'] ?? true, (string) json_encode($answer));
        self::assertSame([], $answer['result']['structuredContent']['tags']);
    }

    public function test_a_bound_token_is_refused_a_header_naming_another_project(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->boundToken($client, 'other');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $elsewhere = new Project(AgentCredential::managed($em, $project->owner, $project->owner->id), 'Elsewhere');
        $em->persist($elsewhere);
        $em->flush();

        $answer = $this->callBoundTool($client, $raw, (string) $elsewhere->id);

        // 200 with isError, never the 404 an ended session answers.
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertTrue($answer['result']['isError']);
        self::assertStringContainsString('does not cover', $answer['result']['content'][0]['text']);
    }

    public function test_a_header_that_is_not_a_project_id_is_refused(): void
    {
        $client = static::createClient();
        [$raw] = $this->boundToken($client, 'malformed');

        $answer = $this->callBoundTool($client, $raw, 'the-board');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertTrue($answer['result']['isError']);
        self::assertStringContainsString('must be a project id', $answer['result']['content'][0]['text']);
    }

    public function test_no_header_leaves_the_bound_project_in_place(): void
    {
        $client = static::createClient();
        [$raw] = $this->boundToken($client, 'none');

        $answer = $this->callBoundTool($client, $raw, null);

        self::assertFalse($answer['result']['isError'] ?? true, (string) json_encode($answer));
        self::assertSame([], $answer['result']['structuredContent']['tags']);
    }

    /**
     * An access token bound to one project, with the project it names.
     *
     * @return array{string, Project}
     */
    private function boundToken(KernelBrowser $client, string $suffix): array
    {
        $scenario = new OAuthScenario(static::getContainer());
        $scenario->createClient();
        $user = $scenario->createUser('header-'.$suffix.'@example.com');
        $project = $scenario->createProject($user, 'Bound '.$suffix);

        return [$scenario->accessTokenFor($client, $user, 'mcp', $project), $project];
    }

    /**
     * Initialises a session, then calls a project-bound tool with the header.
     *
     * `tag_list` is the vehicle because it resolves its project through
     * ResolvesBoundProject and needs no state of its own.
     *
     * @return array<string, mixed>
     */
    private function callBoundTool(KernelBrowser $client, string $raw, ?string $header): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$raw];

        $client->request(Request::METHOD_POST, '/mcp', server: $server, content: '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}');
        $session = $client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertIsString($session);
        $server['HTTP_MCP_SESSION_ID'] = $session;

        $client->request(Request::METHOD_POST, '/mcp', server: $server, content: '{"jsonrpc":"2.0","method":"notifications/initialized"}');

        if (null !== $header) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', AuthenticatedProjectResolver::PROJECT_HEADER))] = $header;
        }

        $client->request(Request::METHOD_POST, '/mcp', server: $server, content: '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"tag_list","arguments":{}}}');

        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
