<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Repository\InboxItemRepository;
use App\Module\Inbox\Service\SessionPromptLimit;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/**
 * inbox_ask over real HTTP, so the MCP server validates the arguments
 * against the published schema before the tool runs.
 */
final class InboxAskPromptSchemaTest extends WebTestCase
{
    public function test_a_prompt_above_the_default_passes_the_schema_when_the_flag_allows_it(): void
    {
        $client = static::createClient();
        $raw = $this->boundToken($client);
        $this->setFlags(SessionPromptLimit::DEFAULT + 1000);

        $answer = $this->askWithPrompt($client, $raw, str_repeat('a', SessionPromptLimit::DEFAULT + 500));

        self::assertArrayHasKey('result', $answer, (string) json_encode($answer));
        self::assertFalse($answer['result']['isError'] ?? true, (string) json_encode($answer));
        $items = static::getContainer()->get(InboxItemRepository::class);
        self::assertInstanceOf(InboxItemRepository::class, $items);
        $stored = $items->findOneBy(['title' => 'Rebuild it']) ?? throw new \LogicException('The ask stores the item.');
        self::assertSame(SessionPromptLimit::DEFAULT + 500, mb_strlen($stored->suggestedActions[0]['prompt'] ?? ''));
    }

    public function test_a_prompt_above_the_flag_is_refused_with_the_configured_limit(): void
    {
        $client = static::createClient();
        $raw = $this->boundToken($client);
        $this->setFlags(SessionPromptLimit::DEFAULT + 1000);

        $answer = $this->askWithPrompt($client, $raw, str_repeat('a', SessionPromptLimit::DEFAULT + 1001));

        self::assertTrue($answer['result']['isError'] ?? false, (string) json_encode($answer));
        self::assertStringContainsString('at most 3000 characters', (string) json_encode($answer['result']['content']));
    }

    private function boundToken(KernelBrowser $client): string
    {
        $scenario = new OAuthScenario(static::getContainer());
        $scenario->createClient();
        $user = $scenario->createUser('prompt-'.uniqid().'@example.com');
        $project = $scenario->createProject($user, 'Prompt schema');
        $this->em()->flush();

        return $scenario->accessTokenFor($client, $user, 'mcp', $project);
    }

    private function setFlags(int $promptLimit): void
    {
        $flags = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $all = $flags->findAllIndexed();
        $all[InboxInstallFlags::FLAG_INBOX_ENABLED]->value = true;
        $all[SessionPromptLimit::FLAG]->value = $promptLimit;
        $this->em()->flush();
    }

    /** @return array<string, mixed> */
    private function askWithPrompt(KernelBrowser $client, string $raw, string $prompt): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$raw];

        $client->request(Request::METHOD_POST, '/mcp', server: $server, content: '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}');
        $session = $client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertIsString($session);
        $server['HTTP_MCP_SESSION_ID'] = $session;

        $client->request(Request::METHOD_POST, '/mcp', server: $server, content: '{"jsonrpc":"2.0","method":"notifications/initialized"}');

        $client->request(Request::METHOD_POST, '/mcp', server: $server, content: (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'inbox_ask', 'arguments' => [
                'sessionId' => (string) Uuid::v4(),
                'items' => [[
                    'kind' => 'todo',
                    'title' => 'Rebuild it',
                    'suggestedActions' => [['action' => 'start-session', 'reason' => 'Run the rebuild', 'prompt' => $prompt]],
                ]],
            ]],
        ]));

        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
