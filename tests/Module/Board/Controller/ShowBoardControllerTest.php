<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;

final class ShowBoardControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_the_board_shows_four_columns_with_one_drop_target_each(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-columns@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'Backlog card', 'backlog');
        $this->card($em, $project, 'Next card', 'next');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('.lp-board__column'));
        self::assertCount(4, $crawler->filter('[data-board-drag-target="group"]'));
        self::assertCount(0, $crawler->filter('[data-priority], [data-card-priority]'));
        self::assertCount(2, $crawler->filter('[data-board-drag-target="card"]'));
        self::assertCount(1, $crawler->filter('dialog[data-card-drawer-target="dialog"] turbo-frame#card-drawer-frame'));
        self::assertCount(2, $crawler->filter('.lp-board__column a[data-turbo-frame="card-drawer-frame"][data-action="click->card-drawer#prepare"]'));
        self::assertSelectorTextContains('.lp-board-toolbar__count', '2 cards');
        self::assertSelectorTextContains('.lp-board-toolbar__mode-button[aria-pressed="true"]', 'Board');
        self::assertSelectorExists('a[href="/projects/'.$project->id.'/edit"]');

        $counts = $crawler->filter('.lp-board__column-count')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );
        self::assertSame(['1', '1', '0', '0'], $counts);
    }

    public function test_the_board_renders_the_columns_its_project_holds(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-own-columns@example.com');
        $project = $this->project($em, $owner);
        $this->column($project, 'backlog')->label = 'Ideas';
        $em->persist(new BoardColumn(project: $project, label: 'Won’t do', slug: 'wont-do', position: 4, terminal: true));
        $em->flush();
        $this->card($em, $project, 'Dropped', 'wont-do');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        $titles = $crawler->filter('.lp-board__column-title')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );
        // A literal label passes through the translator unchanged.
        self::assertSame(['Ideas', 'Next', 'In progress', 'Done', 'Won’t do'], $titles);
        $wontDo = $crawler->filter('.lp-board__column')->last();
        self::assertStringContainsString('Dropped', $wontDo->text());
        self::assertCount(1, $wontDo->filter('.lp-board__column-link'));
    }

    public function test_a_card_face_carries_the_move_fields_and_no_move_control(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-face@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Draggable');
        $cardId = $card->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        // The drag submits this form, so the fields are on the face. Dragging is
        // the only interaction the face offers, so nothing renders a control.
        $face = $crawler->filter('[data-card-id="'.$cardId.'"]');
        self::assertSame('pointerdown->board-drag#press', $face->attr('data-action'));
        self::assertCount(1, $crawler->filter('#board [data-board-drag-target="message"]'));
        self::assertCount(1, $face->filter('form[hidden][data-board-drag-target="moveForm"]'));
        self::assertCount(1, $face->filter('select[name$="[column]"]'));
        self::assertCount(0, $face->filter('details'));
        self::assertCount(0, $face->filter('button'));
    }

    public function test_a_card_shows_its_type_and_a_pull_request_indicator(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-indicator@example.com');
        $project = $this->project($em, $owner);
        $plain = $this->card($em, $project, 'No links');
        $linked = $this->card($em, $project, 'Has links');
        $linked->replacePullRequests(new CardPullRequest(
            card: $linked,
            url: 'https://github.com/loupe/loupe/pull/7',
            forge: Forge::GitHub,
            repository: 'loupe/loupe',
            number: 7,
        ));
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-card-id="'.$linked->id.'"] .lp-board-card__pulls'));
        self::assertCount(0, $crawler->filter('[data-card-id="'.$plain->id.'"] .lp-board-card__pulls'));
        self::assertStringContainsString('Feature', $crawler->filter('[data-card-id="'.$plain->id.'"]')->text());
    }

    public function test_the_done_column_shows_only_the_recent_slice_and_links_to_the_history(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-done@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'Finished today', 'done');
        $old = $this->card($em, $project, 'Finished long ago', 'done');
        $old->completedAt = new \DateTimeImmutable('-30 days');
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        $done = $crawler->filter('.lp-board__column')->last();
        self::assertStringContainsString('Finished today', $done->text());
        self::assertStringNotContainsString('Finished long ago', $done->text());
        // The link still counts every Done card, not only the ones on screen.
        self::assertStringContainsString('2', $done->filter('.lp-board__column-link')->text());
        self::assertStringContainsString(
            '/board/terminal/',
            (string) $done->filter('.lp-board__column-link')->attr('href'),
        );
    }

    public function test_the_board_is_not_found_while_the_flag_is_off(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-flag-off@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_stranger_is_forbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-owner@example.com');
        $stranger = $this->user($em, 'board-stranger@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_sidebar_offers_the_board_only_while_the_flag_is_on(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'board-sidebar@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href$="/board"]'));

        $this->enableBoard();
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/documents');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href$="/board"]'));
    }

    public function test_the_board_loads_every_card_pull_request_with_the_cards(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'board-queries@example.com');
        $project = $this->project($em, $owner, 'many-cards');
        for ($index = 0; $index < 12; ++$index) {
            $this->linkedCard($em, $project, 'Card '.$index);
        }
        $em->clear();

        $client->loginUser($owner);
        $client->enableProfiler();
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');
        self::assertResponseIsSuccessful();

        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $boardReads = 0;
        $lazyLinkReads = 0;
        foreach ($collector->getQueries() as $queries) {
            foreach ($queries as $query) {
                $sql = (string) $query['sql'];
                if (!str_starts_with($sql, 'SELECT')) {
                    continue;
                }
                if (str_contains($sql, 'FROM board_cards')) {
                    ++$boardReads;
                }
                if (str_contains($sql, 'FROM board_card_pull_requests')) {
                    ++$lazyLinkReads;
                }
            }
        }

        // Guard: without it the link assertion below would also hold for a
        // request that read no cards at all.
        self::assertGreaterThan(0, $boardReads);
        // The links ride along on the board's own query. Drop the fetch-join in
        // CardRepository and each card on the page loads its own.
        self::assertSame(0, $lazyLinkReads);
    }

    private function linkedCard(EntityManagerInterface $em, Project $project, string $title): Card
    {
        $card = $this->card($em, $project, $title);
        $card->replacePullRequests(new CardPullRequest(
            card: $card,
            url: 'https://github.com/loupe/loupe/pull/1',
            forge: Forge::GitHub,
            repository: 'loupe/loupe',
            number: 1,
        ));
        $em->flush();

        return $card;
    }
}
