<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Command\CardLinkInput;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Board\Repository\CardLinkRepository;
use App\Module\Board\Service\BoardColumnSeeder;
use App\Module\Project\Entity\Project;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

/**
 * relatedCards over real HTTP, so the MCP server validates the arguments
 * against the published schema before the tool runs.
 */
final class CardLinkToolSchemaTest extends WebTestCase
{
    public function test_link_entries_shaped_like_card_get_output_round_trip_unchanged(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->boundToken($client);
        [$a, $b] = [$this->cardIn($project), $this->cardIn($project)];
        $this->handler(UpdateCardHandler::class)(new UpdateCardCommand($b, CardReporter::Agent, relatedCards: [new CardLinkInput((string) $a->id, CardLinkKind::BlockedBy)]));
        $before = $this->links()->findForCard($a)[0];
        $linkId = (string) $before->id;

        $answer = $this->callTool($client, $raw, 'card_update', [
            'cardId' => (string) $a->id,
            'relatedCards' => [[
                'cardId' => (string) $b->id,
                'number' => $b->number,
                'title' => $b->title,
                'status' => $b->column->slug,
                'kind' => 'blocks',
            ]],
        ]);

        self::assertArrayHasKey('result', $answer, (string) json_encode($answer));
        self::assertFalse($answer['result']['isError'] ?? true, (string) json_encode($answer));
        $this->em()->clear();
        $links = $this->links()->findForCard($this->reload($a));
        self::assertCount(1, $links);
        self::assertSame($linkId, (string) $links[0]->id);
        self::assertSame((string) $a->id, (string) $links[0]->source->id);
        self::assertSame(CardLinkKind::Blocks, $links[0]->kind);
    }

    /** Proves the schema is enforced on this path: the tool never sees the argument. */
    public function test_the_server_refuses_an_unknown_kind_against_the_schema(): void
    {
        $client = static::createClient();
        [$raw, $project] = $this->boundToken($client);
        [$a, $b] = [$this->cardIn($project), $this->cardIn($project)];

        $answer = $this->callTool($client, $raw, 'card_update', [
            'cardId' => (string) $a->id,
            'relatedCards' => [['cardId' => (string) $b->id, 'kind' => 'sideways']],
        ]);

        self::assertArrayHasKey('error', $answer, (string) json_encode($answer));
        self::assertStringContainsString('Invalid parameters', $answer['error']['message']);
    }

    /** @return array{string, Project} */
    private function boundToken(KernelBrowser $client): array
    {
        $scenario = new OAuthScenario(static::getContainer());
        $scenario->createClient();
        $user = $scenario->createUser('links-'.uniqid().'@example.com');
        $project = $scenario->createProject($user, 'Linked board');

        $seeder = static::getContainer()->get(BoardColumnSeeder::class);
        self::assertInstanceOf(BoardColumnSeeder::class, $seeder);
        $seeder->seed($project);

        $flags = static::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = true;
        $this->em()->flush();

        $raw = $scenario->accessTokenFor($client, $user, 'mcp', $project);

        // The grant ran requests, which rebooted the kernel and its entity manager.
        return [$raw, $this->em()->find(Project::class, $project->id) ?? throw new \LogicException('The project must exist.')];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function callTool(KernelBrowser $client, string $raw, string $name, array $arguments): array
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
            'params' => ['name' => $name, 'arguments' => $arguments],
        ]));

        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function cardIn(Project $project): Card
    {
        return $this->handler(CreateCardHandler::class)(new CreateCardCommand($project, 'Ship it', 'Body', CardType::Feature));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function handler(string $class): object
    {
        $handler = static::getContainer()->get($class);
        self::assertInstanceOf($class, $handler);

        return $handler;
    }

    private function links(): CardLinkRepository
    {
        return $this->handler(CardLinkRepository::class);
    }

    private function em(): EntityManagerInterface
    {
        return $this->handler(EntityManagerInterface::class);
    }

    private function reload(Card $card): Card
    {
        return $this->em()->find(Card::class, $card->id) ?? throw new \LogicException('The card must exist.');
    }
}
