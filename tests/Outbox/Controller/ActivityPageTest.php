<?php

declare(strict_types=1);

namespace App\Tests\Outbox\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Outbox\Entity\OutboxEvent;
use App\Session\ReadOnlyAwareSessionHandler;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class ActivityPageTest extends WebTestCase
{
    public function test_recent_activity_is_bounded_and_permission_scoped(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'recent-activity@example.com');
        $outsider = $this->user($em, 'recent-outsider@example.com');
        $project = new Project($owner, 'Recent activity');
        $empty = new Project($owner, 'Empty activity');
        $foreign = new Project($outsider, 'Foreign activity');
        foreach ([$project, $empty, $foreign] as $entity) {
            $em->persist($entity);
        }
        for ($index = 0; $index < 14; ++$index) {
            $em->persist(new OutboxEvent($project, 'event.'.$index, 'topic', '{}', new \DateTimeImmutable('-'.$index.' minutes')));
        }
        $em->persist(new OutboxEvent($foreign, 'foreign.secret', 'topic', '{}'));
        $em->flush();
        $em->clear();
        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity/recent');
        self::assertResponseIsSuccessful();
        self::assertCount(12, $crawler->filter('#recent-activity-frame [data-activity-event-id]'));
        self::assertStringContainsString('event.0', $crawler->text());
        self::assertStringNotContainsString('event.13', $crawler->text());
        self::assertStringNotContainsString('foreign.secret', $crawler->text());
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$empty->id.'/activity/recent');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-activity-event-id]'));
        self::assertSelectorExists('#recent-activity-frame');
        $client->loginUser($outsider);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity/recent');
        self::assertResponseStatusCodeSame(403);
    }

    public function test_card_links_resolve_only_existing_cards_in_the_event_project(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-links@example.com');
        $project = new Project($owner, 'Activity links');
        $other = new Project($owner, 'Other project');
        $column = new BoardColumn($project, 'Work', 'work', 0);
        $otherColumn = new BoardColumn($other, 'Work', 'work', 0);
        $card = new Card($project, $column, 'Related work', '', 7);
        $foreign = new Card($other, $otherColumn, 'Foreign secret title', '', 8);
        foreach ([$project, $other, $column, $otherColumn, $card, $foreign] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        foreach ([(string) $card->id, (string) $foreign->id, (string) Uuid::v7(), 'not-a-uuid'] as $id) {
            $em->persist(new OutboxEvent($project, 'board.card_moved', 'topic', json_encode([
                'subject' => ['type' => 'card', 'id' => $id],
            ], \JSON_THROW_ON_ERROR)));
        }
        foreach (['{broken', 'null', '{"subject":"card"}', '{"subject":{"type":"document","id":"'.$card->id.'"}}'] as $payload) {
            $em->persist(new OutboxEvent($project, 'other.event', 'topic', $payload));
        }
        $em->flush();
        $em->clear();
        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity');

        self::assertResponseIsSuccessful();
        self::assertCount(8, $crawler->filter('[data-activity-event-id]'));
        // A row with no linked work has no target, so it opens nothing and shows no hover fill.
        self::assertCount(1, $crawler->filter('.lp-data-table__target'));
        $link = $crawler->filter('a.lp-data-table__target[data-activity-work-link]');
        self::assertCount(1, $link);
        self::assertSame('Card moved', trim($link->filter('.lp-data-table__title')->text()));
        self::assertSame('#7 Related work', trim($link->filter('.lp-data-table__subject')->text()));
        self::assertSame('/projects/'.$project->id.'/board/cards/'.$card->id, $link->attr('href'));
        self::assertStringNotContainsString('Foreign secret title', $crawler->text());
        self::assertStringNotContainsString((string) $foreign->id, $crawler->html());
        $client->click($link->link());
        self::assertResponseIsSuccessful();
    }

    public function test_a_card_move_names_its_columns_in_the_subject(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-move@example.com');
        $project = new Project($owner, 'Moves');
        $from = new BoardColumn($project, 'Tech design', 'tech-design', 0);
        $to = new BoardColumn($project, 'Implementation', 'implementation', 1);
        $card = new Card($project, $to, 'Paged events', '', 292);
        foreach ([$project, $from, $to, $card] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $em->persist(new OutboxEvent($project, 'board.card_moved', 'topic', json_encode([
            'subject' => ['type' => 'card', 'id' => (string) $card->id],
            'fromStatus' => 'tech-design',
            'toStatus' => 'implementation',
        ], \JSON_THROW_ON_ERROR)));
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity');

        self::assertResponseIsSuccessful();
        $link = $crawler->filter('[data-activity-work-link]');
        self::assertSame('Card moved', trim($link->filter('.lp-data-table__title')->text()));
        self::assertSame('#292 Tech design → Implementation', trim($link->filter('.lp-data-table__subject')->text()));
    }

    public function test_it_shows_the_delivery_and_family_of_project_events_only(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-owner@example.com');
        $project = new Project($owner, 'Activity');
        $other = new Project($owner, 'Other activity');
        $delivered = new OutboxEvent($project, 'board.card_moved', 'topic', '{}', new \DateTimeImmutable('-4 minutes'));
        $delivered->markPublished();
        $pending = new OutboxEvent($project, 'review.document_revised', 'topic', '{}', new \DateTimeImmutable('-3 minutes'));
        $merged = new OutboxEvent($project, 'pull_request.merged', 'topic', '{}', new \DateTimeImmutable('-2 minutes'));
        $failed = new OutboxEvent($project, 'mystery.event', 'topic', '{}', new \DateTimeImmutable('-1 minute'));
        $failed->recordPublishFailure('hub down', new \DateTimeImmutable());
        $failed->recordPublishFailure('hub down', new \DateTimeImmutable());
        $foreign = new OutboxEvent($other, 'other.secret', 'topic', '{}');
        foreach ([$project, $other, $delivered, $pending, $merged, $failed, $foreign] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity');

        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('.lp-data-table--events [data-activity-event-id]'));
        self::assertStringNotContainsString('other.secret', $crawler->text());
        self::assertSame('Delivered', $this->chip($crawler, $delivered));
        self::assertSame('Pending delivery', $this->chip($crawler, $pending));
        self::assertSame('Delivery failed', $this->chip($crawler, $failed));
        self::assertSame(
            '2 failed attempts. hub down',
            trim($crawler->filter('[data-activity-event-id="'.$failed->id.'"] #activity-reason-'.$failed->id)->text()),
        );
        self::assertCount(0, $crawler->filter('[data-activity-event-id="'.$pending->id.'"] .lp-tooltip'));
        self::assertSame('Documents', trim($crawler->filter('[data-activity-event-id="'.$pending->id.'"] .lp-tag')->text()));
        self::assertSame('Pull requests', trim($crawler->filter('[data-activity-event-id="'.$merged->id.'"] .lp-tag')->text()));
        self::assertSame('mystery', trim($crawler->filter('[data-activity-event-id="'.$failed->id.'"] .lp-tag')->text()));
        self::assertSame('mystery.event', trim($crawler->filter('[data-activity-event-id="'.$failed->id.'"] .lp-data-table__title')->text()));
        self::assertCount(1, $crawler->filter('#activity-family option[value="pull-request"]'));
        self::assertSame('4 events', trim($crawler->filter('turbo-frame#activity-count')->text()));
        self::assertCount(1, $crawler->filter('.lp-list-filters form[data-controller="autosearch"]'));
        self::assertCount(0, $crawler->filter('[data-activity-empty]'));
    }

    public function test_page_two_shows_the_older_rows_and_the_pagination(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-pages@example.com');
        $project = new Project($owner, 'Pages');
        $em->persist($project);
        $events = [];
        for ($index = 0; $index < 25; ++$index) {
            $events[] = $event = new OutboxEvent($project, 'project.renamed', 'topic', '{}', new \DateTimeImmutable('-'.$index.' minutes'));
            $em->persist($event);
        }
        $em->flush();
        $ids = array_map(static fn (OutboxEvent $event): string => (string) $event->id, $events);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity');
        self::assertResponseIsSuccessful();
        self::assertSame(array_slice($ids, 0, 20), $this->rowIds($crawler));
        self::assertCount(1, $crawler->filter('turbo-frame#activity-frame .lp-pagination'));

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity?page=2');
        self::assertResponseIsSuccessful();
        self::assertSame(array_slice($ids, 20), $this->rowIds($crawler));
        self::assertSame('2', $crawler->filter('.lp-pagination [aria-current="page"]')->text());
        self::assertSame('25 events', trim($crawler->filter('turbo-frame#activity-count')->text()));
    }

    public function test_the_page_links_keep_the_filters(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-page-links@example.com');
        $project = new Project($owner, 'Page links');
        $em->persist($project);
        for ($index = 0; $index < 21; ++$index) {
            $em->persist(new OutboxEvent($project, 'board.card_moved', 'topic', '{}', new \DateTimeImmutable('-'.$index.' minutes')));
        }
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity?search=moved&family=board');

        self::assertSame(
            '/projects/'.$project->id.'/activity?page=2&search=moved&family=board',
            $crawler->filter('.lp-pagination a[aria-label="Next page"]')->attr('href'),
        );
    }

    public function test_search_matches_the_type_and_the_event_data(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-search@example.com');
        $project = new Project($owner, 'Search');
        $moved = new OutboxEvent($project, 'board.card_moved', 'topic', '{"toStatus":"implementation"}');
        $renamed = new OutboxEvent($project, 'project.renamed', 'topic', '{"toSlug":"alpha"}');
        foreach ([$project, $moved, $renamed] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity?search=Implementation');
        self::assertResponseIsSuccessful();
        self::assertSame([(string) $moved->id], $this->rowIds($crawler));
        self::assertSame('Implementation', $crawler->filter('#activity-search')->attr('value'));
        self::assertSame('1 event', trim($crawler->filter('turbo-frame#activity-count')->text()));

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity?search=renamed');
        self::assertSame([(string) $renamed->id], $this->rowIds($crawler));
    }

    public function test_the_family_filter_narrows_the_list(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-family@example.com');
        $project = new Project($owner, 'Families');
        $merged = new OutboxEvent($project, 'pull_request.merged', 'topic', '{}', new \DateTimeImmutable('-3 minutes'));
        $moved = new OutboxEvent($project, 'board.card_moved', 'topic', '{}', new \DateTimeImmutable('-2 minutes'));
        $revised = new OutboxEvent($project, 'review.document_revised', 'topic', '{}', new \DateTimeImmutable('-1 minute'));
        $submitted = new OutboxEvent($project, 'document.review_submitted', 'topic', '{}');
        foreach ([$project, $merged, $moved, $revised, $submitted] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity?family=pull-request');
        self::assertResponseIsSuccessful();
        self::assertSame([(string) $merged->id], $this->rowIds($crawler));
        self::assertSame('pull-request', $crawler->filter('#activity-family option[selected]')->attr('value'));

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity?family=document');
        self::assertSame([(string) $submitted->id, (string) $revised->id], $this->rowIds($crawler));

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity?family=document&search=revised');
        self::assertSame([(string) $revised->id], $this->rowIds($crawler));
    }

    public function test_a_page_past_the_end_redirects_to_the_last_page_and_keeps_the_filters(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-clamp@example.com');
        $project = new Project($owner, 'Clamp');
        $em->persist($project);
        $em->persist(new OutboxEvent($project, 'board.card_moved', 'topic', '{}'));
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity?page=9&search=moved&family=board');

        self::assertResponseRedirects('/projects/'.$project->id.'/activity?page=1&search=moved&family=board');
    }

    public function test_a_project_with_no_events_shows_the_empty_state_and_no_filter_bar(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-empty@example.com');
        $project = new Project($owner, 'Quiet');
        $em->persist($project);
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.lp-list-filters'));
        self::assertCount(0, $crawler->filter('.lp-data-table'));
        self::assertSame('No recorded activity', trim($crawler->filter('turbo-frame#activity-frame [data-activity-empty] .lp-empty-state__title')->text()));
        self::assertCount(0, $crawler->filter('[data-activity-filtered-empty]'));
        self::assertSame('true', $crawler->filter('[data-controller="worker-run-refresh"]')->attr('data-worker-run-refresh-whole-value'));
    }

    public function test_a_filter_that_matches_nothing_keeps_the_filter_bar(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-filtered-empty@example.com');
        $project = new Project($owner, 'Filtered');
        $em->persist($project);
        $em->persist(new OutboxEvent($project, 'board.card_moved', 'topic', '{}'));
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity?search=nothing-matches');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-list-filters #activity-search'));
        self::assertSame('No activity matches these filters.', trim($crawler->filter('turbo-frame#activity-frame [data-activity-filtered-empty] .lp-empty-state__title')->text()));
        self::assertCount(0, $crawler->filter('[data-activity-empty]'));
        self::assertSame('false', $crawler->filter('[data-controller="worker-run-refresh"]')->attr('data-worker-run-refresh-whole-value'));
    }

    public function test_the_frame_sends_its_links_to_the_top_page(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-frame@example.com');
        $project = new Project($owner, 'Frame');
        $em->persist($project);
        $em->persist(new OutboxEvent($project, 'board.card_moved', 'topic', '{}'));
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity', server: ['HTTP_TURBO_FRAME' => 'activity-frame']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#activity-frame[target="_top"] [data-activity-event-id]'));
    }

    public function test_every_frame_the_live_refresh_reloads_keeps_the_session_read_only(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-read-only-frames@example.com');
        $project = new Project($owner, 'Read-only frames');
        $em->persist($project);
        $em->persist(new OutboxEvent($project, 'board.card_moved', 'topic', '{}'));
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity');
        $refresh = $crawler->filter('[data-controller="worker-run-refresh"]');
        $frames = [
            $refresh->filter('[data-worker-run-refresh-target="frame"]')->attr('id'),
            ...json_decode((string) $refresh->attr('data-worker-run-refresh-frames-value'), true, flags: \JSON_THROW_ON_ERROR),
        ];

        $route = static::getContainer()->get('router')->getRouteCollection()->get('app_project_activity');
        self::assertNotNull($route);
        self::assertEqualsCanonicalizing($frames, (array) $route->getDefault(ReadOnlyAwareSessionHandler::READ_ONLY));
    }

    public function test_only_the_first_page_refreshes_live(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $owner = $this->user($em, 'activity-live-pages@example.com');
        $project = new Project($owner, 'Live pages');
        $em->persist($project);
        for ($index = 0; $index < 21; ++$index) {
            $em->persist(new OutboxEvent($project, 'project.renamed', 'topic', '{}', new \DateTimeImmutable('-'.$index.' minutes')));
        }
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/activity?page=2');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#activity-frame [data-activity-event-id]'));
        self::assertCount(0, $crawler->filter('[data-controller="worker-run-refresh"]'));
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    /** @param non-empty-string $email */
    private function user(EntityManagerInterface $em, string $email): User
    {
        $user = new User(fullName: 'Owner', email: $email, password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        AcceptedTerms::stamp($user, static::getContainer());
        $em->persist($user);

        return $user;
    }

    private function chip(Crawler $crawler, OutboxEvent $event): string
    {
        $chip = $crawler->filter('[data-activity-event-id="'.$event->id.'"] > .lp-status-chip');
        self::assertCount(1, $chip);

        return trim($chip->filter('.lp-tooltip')->count() > 0
            ? str_replace($chip->filter('.lp-tooltip')->text(), '', $chip->text())
            : $chip->text());
    }

    /** @return list<string> */
    private function rowIds(Crawler $crawler): array
    {
        return array_values(array_filter($crawler->filter('[data-activity-event-id]')->each(
            static fn (Crawler $row): ?string => $row->attr('data-activity-event-id'),
        )));
    }
}
