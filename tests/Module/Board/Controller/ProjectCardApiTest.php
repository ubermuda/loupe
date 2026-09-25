<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Tests\Support\AgentCredential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class ProjectCardApiTest extends WebTestCase
{
    use BoardScenario;

    public function test_it_names_the_column_of_the_card(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'card-api-read@example.com');
        $project = $this->project($em, $owner, 'Card App');
        $this->card($em, $project, 'First');
        $card = $this->card($em, $project, 'Second', 'in-progress');
        $raw = AgentCredential::agentToken(static::getContainer(), $owner);
        $this->enableBoard();

        $this->get($client, '/api/projects/card-app/board/cards/'.$card->id, $raw);

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            json_encode(['cardId' => (string) $card->id, 'number' => 2, 'column' => 'in-progress'], \JSON_THROW_ON_ERROR),
            (string) $client->getResponse()->getContent(),
        );
    }

    /** The card of another project of the same owner is not a card of this project. */
    public function test_a_card_of_another_project_is_not_found(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'card-api-cross@example.com');
        $project = $this->project($em, $owner, 'Card Home');
        $elsewhere = $this->card($em, $this->project($em, $owner, 'Card Away'), 'Away');
        $raw = AgentCredential::agentToken(static::getContainer(), $owner);
        $this->enableBoard();

        $this->get($client, '/api/projects/'.$project->id.'/board/cards/'.$elsewhere->id, $raw);

        self::assertResponseStatusCodeSame(404);
        self::assertJsonStringEqualsJsonString('{"error":"card_not_found"}', (string) $client->getResponse()->getContent());
    }

    public function test_an_unknown_card_is_not_found(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'card-api-unknown@example.com');
        $project = $this->project($em, $owner, 'Card Unknown');
        $raw = AgentCredential::agentToken(static::getContainer(), $owner);
        $this->enableBoard();

        foreach ([(string) Uuid::v7(), 'not-a-uuid'] as $cardId) {
            $this->get($client, '/api/projects/'.$project->id.'/board/cards/'.$cardId, $raw);

            self::assertResponseStatusCodeSame(404);
            self::assertJsonStringEqualsJsonString('{"error":"card_not_found"}', (string) $client->getResponse()->getContent(), $cardId);
        }
    }

    public function test_a_card_of_another_users_project_reads_as_an_unknown_project(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $caller = $this->user($em, 'card-api-caller@example.com');
        $other = $this->project($em, $this->user($em, 'card-api-other@example.com'), 'Card Private');
        $card = $this->card($em, $other, 'Private');
        $raw = AgentCredential::agentToken(static::getContainer(), $caller);
        $this->enableBoard();

        $this->get($client, '/api/projects/'.$other->id.'/board/cards/'.$card->id, $raw);

        self::assertResponseStatusCodeSame(404);
        self::assertJsonStringEqualsJsonString('{"error":"project_not_found"}', (string) $client->getResponse()->getContent());
    }

    public function test_it_is_absent_while_the_board_is_switched_off(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'card-api-flag@example.com');
        $project = $this->project($em, $owner, 'Card Flag');
        $card = $this->card($em, $project, 'Flagged');
        $raw = AgentCredential::agentToken(static::getContainer(), $owner);
        $this->disableBoard();

        $this->get($client, '/api/projects/'.$project->id.'/board/cards/'.$card->id, $raw);

        self::assertResponseStatusCodeSame(404);
        self::assertJsonStringEqualsJsonString('{"error":"board_disabled"}', (string) $client->getResponse()->getContent());
    }

    public function test_a_widget_token_is_refused_by_the_firewall(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'card-api-widget@example.com');
        $project = $this->project($em, $owner, 'Card Widget');
        $card = $this->card($em, $project, 'Widget');
        $raw = AgentCredential::tokenFor(static::getContainer(), $owner, 'site-review', $project);
        $this->enableBoard();

        $this->get($client, '/api/projects/'.$project->id.'/board/cards/'.$card->id, $raw);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_request_without_a_token_is_refused(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'card-api-anonymous@example.com'), 'Card Anonymous');
        $card = $this->card($em, $project, 'Anonymous');
        $this->enableBoard();

        $client->request(Request::METHOD_GET, '/api/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseStatusCodeSame(401);
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function get(KernelBrowser $client, string $path, string $raw): void
    {
        $client->request(Request::METHOD_GET, $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$raw]);
    }
}
