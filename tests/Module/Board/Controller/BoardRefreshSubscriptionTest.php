<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Mercure\ProjectTopicBuilder;
use App\Outbox\AgentPush;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/** The subscriber token an open board gets, and who gets none. */
final class BoardRefreshSubscriptionTest extends WebTestCase
{
    use BoardScenario;

    private const string COOKIE = 'mercureAuthorization';

    public function test_a_viewer_gets_a_token_for_the_board_topic_and_no_other(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-refresh-viewer@example.com');
        $project = $this->project($em, $owner);
        self::assertNotNull($project->id);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        $boardTopic = $topics->forBoard($project->id);

        $claims = $this->claims($this->cookie($client));
        self::assertSame([$boardTopic], $claims['mercure']['subscribe'] ?? null);
        // The agent outbox topic stays out of reach of a browser.
        self::assertNotContains($topics->forProject($project->id), $claims['mercure']['subscribe']);
        self::assertSame([], $claims['mercure']['publish'] ?? []);

        $source = $crawler->filter('[data-controller="board-refresh"]');
        self::assertCount(1, $source);
        self::assertSame(
            'https://mercure.loupe.dev.localhost/.well-known/mercure?topic='.rawurlencode($boardTopic),
            $source->attr('data-board-refresh-hub-value'),
        );
        self::assertCount(1, $source->filter('turbo-frame#board-frame[target="_top"] #board'));
    }

    public function test_a_refused_column_form_renders_the_board_with_its_token(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-refresh-refused@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');
        // The refusal forwards to the board, and only a main-request cookie is sent.
        $crawler = $client->submit($crawler->filter('form[action$="/board/columns"]')->form(['add_board_column_form[label]' => 'Done']));

        self::assertResponseStatusCodeSame(422);
        $this->cookie($client);
        self::assertCount(1, $crawler->filter('[data-controller="board-refresh"]'));
    }

    public function test_a_viewer_renews_the_token_before_a_reconnect(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-refresh-renew@example.com');
        $project = $this->project($em, $owner);
        self::assertNotNull($project->id);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/refresh-authorization');

        self::assertResponseStatusCodeSame(204);
        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        self::assertSame([$topics->forBoard($project->id)], $this->claims($this->cookie($client))['mercure']['subscribe'] ?? null);
    }

    /** @return iterable<string, array{string}> */
    public static function boardUrls(): iterable
    {
        yield 'the board' => [''];
        yield 'the renewal' => ['/refresh-authorization'];
    }

    #[DataProvider('boardUrls')]
    public function test_a_stranger_gets_no_token(string $suffix): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-refresh-owner@example.com');
        $stranger = $this->user($em, 'board-refresh-stranger@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board'.$suffix);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->findCookie($client));
    }

    public function test_with_push_off_the_board_renders_without_a_subscription(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        // The flag ships on through a migration, so the row exists to flip.
        $em->getConnection()->executeStatement("UPDATE feature_flag SET value = 'false' WHERE name = ?", [AgentPush::FLAG]);

        $owner = $this->user($em, 'board-refresh-off@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        self::assertNull($this->findCookie($client));
        self::assertCount(0, $crawler->filter('[data-controller="board-refresh"]'));
        self::assertCount(1, $crawler->filter('turbo-frame#board-frame #board'));

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/refresh-authorization');
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->findCookie($client));
    }

    private function findCookie(KernelBrowser $client): ?Cookie
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if (self::COOKIE === $cookie->getName()) {
                return $cookie;
            }
        }

        return null;
    }

    private function cookie(KernelBrowser $client): Cookie
    {
        $cookie = $this->findCookie($client);
        self::assertNotNull($cookie, 'the board response sets no Mercure cookie');
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('/.well-known/mercure', $cookie->getPath());

        return $cookie;
    }

    /** @return array<string, mixed> */
    private function claims(Cookie $cookie): array
    {
        $parts = explode('.', (string) $cookie->getValue());
        self::assertCount(3, $parts);
        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        self::assertIsString($payload);
        $claims = json_decode($payload, true);
        self::assertIsArray($claims);
        self::assertIsArray($claims['mercure'] ?? null);

        return $claims;
    }
}
