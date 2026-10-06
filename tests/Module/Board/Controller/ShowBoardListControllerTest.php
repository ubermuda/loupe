<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Project\Entity\Project;
use App\Session\ReadOnlyAwareSessionHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

final class ShowBoardListControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_an_anonymous_visitor_is_sent_to_the_login(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->project($em, $this->user($em, 'list-anonymous@example.com'));
        $em->clear();

        $client->request(Request::METHOD_GET, $this->listUrl($project));

        self::assertResponseRedirects('/login');
    }

    public function test_an_outsider_is_refused(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->project($em, $this->user($em, 'list-owner@example.com'));
        $this->card($em, $project, 'Private card', 'next');
        $outsider = $this->user($em, 'list-outsider@example.com');
        $em->clear();

        $client->loginUser($outsider);
        $client->request(Request::METHOD_GET, $this->listUrl($project));

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('Private card', (string) $client->getResponse()->getContent());
    }

    public function test_the_list_route_does_not_write_the_session(): void
    {
        $route = static::getContainer()->get(RouterInterface::class)->getRouteCollection()->get('app_board_list');

        self::assertNotNull($route);
        self::assertTrue($route->getDefault(ReadOnlyAwareSessionHandler::READ_ONLY));
    }

    public function test_the_list_draws_a_row_for_each_card_inside_its_frame(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'list-rows@example.com');
        $project = $this->project($em, $owner);
        $next = $this->card($em, $project, 'Next card', 'next');
        $doing = $this->card($em, $project, 'Doing card', 'in-progress');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $this->listUrl($project));

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('turbo-frame#board-list > .lp-board-list > .lp-board-list__row');
        self::assertSame(
            ['board-row-'.$next->id, 'board-row-'.$doing->id],
            $rows->each(static fn (Crawler $row): string => (string) $row->attr('id')),
        );
        self::assertCount(1, $crawler->filter('turbo-frame#board-list > .lp-board-list > .lp-board-list__header'));
    }

    public function test_the_board_page_holds_an_empty_list_frame_and_no_row(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'list-board-page@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'On the board', 'next');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.lp-board-card'));
        self::assertCount(0, $crawler->filter('.lp-board-list__row'));
        self::assertCount(0, $crawler->filter('.lp-board-list'));
        $frame = $crawler->filter('#board turbo-frame#board-list[data-board-view-target="list"]');
        self::assertCount(1, $frame);
        self::assertNull($frame->attr('src'));
        self::assertSame('', trim($frame->html()));
        self::assertSame($this->listUrl($project), $crawler->filter('#board')->attr('data-board-view-list-url-value'));
    }

    private function listUrl(Project $project): string
    {
        return '/projects/'.$project->id.'/board/list';
    }
}
