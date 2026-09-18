<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Mercure\ProjectTopicBuilder;
use App\Tests\Support\MercureCookies;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The subscriber token an open board gets, and who gets none. */
final class BoardRefreshSubscriptionTest extends WebTestCase
{
    use BoardScenario;
    use MercureCookies;

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

        // The agent outbox topic stays out of reach of a browser.
        self::assertSame([$boardTopic], self::subscribedTopics($client->getResponse()));

        $page = $crawler->filter('form#mercure-subscriptions');
        self::assertCount(1, $page);
        self::assertSame('https://mercure.loupe.dev.localhost/.well-known/mercure', $page->attr('data-hub'));
        self::assertSame('/mercure/authorize', $page->attr('action'));
        self::assertSame([$boardTopic], $page->filter('input[data-mercure-topic]')->each(static fn ($input): ?string => $input->attr('value')));

        self::assertCount(1, $crawler->filter('[data-controller="board-refresh"] turbo-frame#board-frame[target="_top"] #board'));
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
        $form = $crawler->filter('form[name^="rename_board_column_"]')->first();
        $name = $form->attr('name');
        $crawler = $client->submit($form->form(), [$name.'[label]' => '🚀']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, self::subscribedTopics($client->getResponse()) ?? []);
        self::assertCount(1, $crawler->filter('form#mercure-subscriptions input[data-mercure-topic]'));
    }

    public function test_a_stranger_gets_no_token(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-refresh-owner@example.com');
        $stranger = $this->user($em, 'board-refresh-stranger@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseStatusCodeSame(403);
        self::assertNull(self::findMercureCookie($client->getResponse()));
    }

    public function test_the_board_specific_renewal_route_is_gone(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-refresh-old-route@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/refresh-authorization');

        self::assertResponseStatusCodeSame(404);
        self::assertNull(self::findMercureCookie($client->getResponse()));
    }

    public function test_with_agent_push_off_the_board_still_subscribes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $this->setHubFlags($em, liveUpdates: true, agentPush: false);

        $owner = $this->user($em, 'board-refresh-push-off@example.com');
        $project = $this->project($em, $owner);
        self::assertNotNull($project->id);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        self::assertSame([$topics->forBoard($project->id)], self::subscribedTopics($client->getResponse()));
        self::assertCount(1, $crawler->filter('form#mercure-subscriptions'));
    }

    public function test_with_live_updates_off_the_board_renders_without_a_subscription_even_with_agent_push_on(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $this->setHubFlags($em, liveUpdates: false, agentPush: true);

        $owner = $this->user($em, 'board-refresh-off@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        self::assertNull(self::findMercureCookie($client->getResponse()));
        self::assertCount(0, $crawler->filter('form#mercure-subscriptions'));
        self::assertCount(1, $crawler->filter('turbo-frame#board-frame #board'));
    }
}
