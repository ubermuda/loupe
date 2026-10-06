<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Mercure\ProjectTopicBuilder;
use App\Module\Review\Entity\Document;
use App\Tests\Support\MercureCookies;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** The subscriber token an open board gets, and who gets none. */
final class BoardLiveSubscriptionTest extends WebTestCase
{
    use BoardScenario;
    use MercureCookies;

    public function test_a_viewer_gets_a_token_for_the_board_topic_and_no_other(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-live-viewer@example.com');
        $project = $this->project($em, $owner);
        self::assertNotNull($project->id);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        $boardTopic = $topics->forBoard($project->id);
        // A card drawer opened on the board lists the card's runs, which reload on this topic.
        $runTopic = $topics->forWorkerRuns($project->id);

        // The agent outbox topic stays out of reach of a browser.
        self::assertSame([$boardTopic, $runTopic], self::subscribedTopics($client->getResponse()));

        $page = $crawler->filter('form#mercure-subscriptions');
        self::assertCount(1, $page);
        self::assertSame('https://mercure.loupe.dev.localhost/.well-known/mercure', $page->attr('data-hub'));
        self::assertSame('/mercure/authorize', $page->attr('action'));
        self::assertSame([$boardTopic, $runTopic], $page->filter('input[data-mercure-topic]')->each(static fn ($input): ?string => $input->attr('value')));

        self::assertCount(1, $crawler->filter('[data-controller~="board-live"] #board'));
        self::assertCount(0, $crawler->filter('turbo-frame#board-frame'));
        $live = $crawler->filter('[data-controller~="board-live"]');
        $placeholder = (string) $live->attr('data-board-live-placeholder-value');
        self::assertSame('/projects/'.$project->id.'/board/cards/'.$placeholder.'/placement', $live->attr('data-board-live-placement-value'));
        self::assertSame('This card may be out of date', $live->attr('data-board-live-stale-value'));
        self::assertNull($live->attr('data-action'));
        $paused = $crawler->filter('#board-toolbar-'.$project->id.'[data-turbo-permanent] [data-board-live-target="paused"][role="status"][data-message]');
        self::assertCount(1, $paused);
        self::assertSame('', $paused->text());
        self::assertSame('Live updates stopped. Reload the page to catch up.', $paused->attr('data-failed-message'));
    }

    /** The workshop hosts the card drawer too, and the drawer's run list reloads on this topic. */
    public function test_the_workshop_subscribes_its_card_drawer_to_the_run_topic(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-live-workshop@example.com');
        $project = $this->project($em, $owner);
        self::assertNotNull($project->id);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id);

        self::assertResponseIsSuccessful();
        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        $subscribed = self::subscribedTopics($client->getResponse());
        self::assertNotNull($subscribed);
        self::assertContains($topics->forWorkerRuns($project->id), $subscribed);
    }

    /** The document page hosts the card drawer, which updates on the board topic. */
    public function test_the_document_page_subscribes_its_card_drawer_to_the_board_topic(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-live-document@example.com');
        $project = $this->project($em, $owner);
        $document = new Document(owner: $owner, project: $project, title: 'Hosts a drawer');
        $document->addVersion('# Drawer', '<h1>Drawer</h1>');
        $em->persist($document);
        $em->flush();
        self::assertNotNull($project->id);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents/'.$document->id.'/review');

        self::assertResponseIsSuccessful();
        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        $subscribed = self::subscribedTopics($client->getResponse());
        self::assertNotNull($subscribed);
        self::assertContains($topics->forWorkerRuns($project->id), $subscribed);
        self::assertContains($topics->forBoard($project->id), $subscribed);
    }

    public function test_a_stranger_gets_no_token(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-live-owner@example.com');
        $stranger = $this->user($em, 'board-live-stranger@example.com');
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

        $owner = $this->user($em, 'board-live-old-route@example.com');
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
        $this->setHubFlags($em, liveUpdates: true, agentPush: false);

        $owner = $this->user($em, 'board-live-push-off@example.com');
        $project = $this->project($em, $owner);
        self::assertNotNull($project->id);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        self::assertSame([$topics->forBoard($project->id), $topics->forWorkerRuns($project->id)], self::subscribedTopics($client->getResponse()));
        self::assertCount(1, $crawler->filter('form#mercure-subscriptions'));
    }

    public function test_with_live_updates_off_the_board_renders_without_a_subscription_even_with_agent_push_on(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->setHubFlags($em, liveUpdates: false, agentPush: true);

        $owner = $this->user($em, 'board-live-off@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        self::assertNull(self::findMercureCookie($client->getResponse()));
        self::assertCount(0, $crawler->filter('form#mercure-subscriptions'));
        self::assertCount(1, $crawler->filter('[data-controller~="board-live"] #board'));
        self::assertCount(0, $crawler->filter('turbo-frame#board-frame'));
    }
}
