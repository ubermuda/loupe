<?php

declare(strict_types=1);

namespace App\Tests\Mercure\Controller;

use App\Mercure\ProjectTopicBuilder;
use App\Tests\Module\Board\Controller\BoardScenario;
use App\Tests\Support\MercureCookies;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/** POST /mercure/authorize renews the subscriber cookie of an open page. */
final class AuthorizeMercureTopicsControllerTest extends WebTestCase
{
    use BoardScenario;
    use MercureCookies;

    private const string URL = '/mercure/authorize';

    public function test_the_renewed_cookie_holds_only_the_topics_the_user_may_read(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'mercure-renew-owner@example.com');
        $stranger = $this->user($em, 'mercure-renew-stranger@example.com');
        $own = $this->project($em, $owner, 'own-board');
        $theirs = $this->project($em, $stranger, 'their-board');
        self::assertNotNull($own->id);
        self::assertNotNull($theirs->id);
        $em->clear();

        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);
        $ownTopic = $topics->forBoard($own->id);

        $client->loginUser($owner);
        $this->renew($client, [
            $ownTopic,
            $topics->forBoard($theirs->id),
            $topics->forProject($own->id),
            'https://elsewhere.example.com/topic',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(['topics' => [$ownTopic]], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertSame([$ownTopic], self::subscribedTopics($client->getResponse()));
    }

    public function test_a_stranger_gets_no_cookie_for_someone_elses_board(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'mercure-stranger-owner@example.com');
        $stranger = $this->user($em, 'mercure-stranger@example.com');
        $project = $this->project($em, $owner);
        self::assertNotNull($project->id);
        $em->clear();

        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);

        $client->loginUser($stranger);
        $this->renew($client, [$topics->forBoard($project->id)]);

        self::assertResponseIsSuccessful();
        self::assertSame(['topics' => []], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertNull(self::findMercureCookie($client->getResponse()));
    }

    public function test_a_renewal_without_a_csrf_token_is_refused(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'mercure-csrf@example.com');
        $project = $this->project($em, $owner);
        self::assertNotNull($project->id);
        $em->clear();

        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);

        $client->loginUser($owner);
        $this->renew($client, [$topics->forBoard($project->id)], csrfToken: null);

        self::assertResponseStatusCodeSame(422);
        self::assertNull(self::findMercureCookie($client->getResponse()));
    }

    public function test_with_live_updates_off_a_renewal_allows_no_topic_even_with_agent_push_on(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $this->setHubFlags($em, liveUpdates: false, agentPush: true);

        $owner = $this->user($em, 'mercure-renew-off@example.com');
        $project = $this->project($em, $owner);
        self::assertNotNull($project->id);
        $em->clear();

        $topics = static::getContainer()->get(ProjectTopicBuilder::class);
        self::assertInstanceOf(ProjectTopicBuilder::class, $topics);

        $client->loginUser($owner);
        $this->renew($client, [$topics->forBoard($project->id)]);

        // An empty list, not an error: the page drops the topic and closes its connection.
        self::assertResponseIsSuccessful();
        self::assertSame(['topics' => []], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertNull(self::findMercureCookie($client->getResponse()));
    }

    public function test_renewals_are_rate_limited_per_user(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.mercure_authorize', new RateLimiterFactory(
            ['id' => 'mercure_authorize', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'mercure-limit@example.com');

        $client->loginUser($owner);
        $this->renew($client, []);
        $this->renew($client, []);
        self::assertResponseIsSuccessful();

        $this->renew($client, []);
        self::assertResponseStatusCodeSame(429);
    }

    /** @param list<string> $topics */
    private function renew(KernelBrowser $client, array $topics, ?string $csrfToken = 'csrf-token'): void
    {
        $form = ['topics' => $topics];
        if (null !== $csrfToken) {
            $form['_token'] = $csrfToken;
        }

        // The same-origin sentinel passes the CSRF check when the referer matches.
        $client->request(
            Request::METHOD_POST,
            self::URL,
            ['authorize_mercure_topics_form' => $form],
            [],
            ['HTTP_REFERER' => 'http://localhost/projects'],
        );
    }
}
