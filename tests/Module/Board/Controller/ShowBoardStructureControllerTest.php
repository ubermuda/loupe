<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;
use App\Session\ReadOnlyAwareSessionHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\UX\Turbo\TurboBundle;

final class ShowBoardStructureControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_an_anonymous_visitor_is_sent_to_the_login(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $project = $this->project($em, $this->user($em, 'structure-anonymous@example.com'));
        $em->clear();

        $client->request(Request::METHOD_GET, $this->structureUrl($project));

        self::assertResponseRedirects('/login');
    }

    public function test_an_outsider_is_refused(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $project = $this->project($em, $this->user($em, 'structure-owner@example.com'));
        $outsider = $this->user($em, 'structure-outsider@example.com');
        $em->clear();

        $client->loginUser($outsider);
        $client->request(Request::METHOD_GET, $this->structureUrl($project));

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('board-structure', (string) $client->getResponse()->getContent());
    }

    public function test_the_structure_is_not_found_while_the_board_is_off(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'structure-flag-off@example.com');
        $project = $this->project($em, $owner);
        $this->disableBoard();
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->structureUrl($project));

        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_structure_route_does_not_write_the_session(): void
    {
        $route = static::getContainer()->get(RouterInterface::class)->getRouteCollection()->get('app_board_structure');

        self::assertNotNull($route);
        self::assertTrue($route->getDefault(ReadOnlyAwareSessionHandler::READ_ONLY));
    }

    public function test_a_board_without_lanes_draws_each_column_with_an_empty_group(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'structure-plain@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Visible on the page', 'next');
        $columns = $this->columnIds($project);
        $em->clear();

        $client->loginUser($owner);
        [$stream, $template] = $this->structure($client, $project);

        self::assertStringNotContainsString('Visible on the page', $template->html());
        self::assertCount(0, $template->filter('.lp-board-card'));
        self::assertCount(0, $template->filter('.lp-board-list__row'));
        self::assertCount(1, $template->filter('.lp-board__columns'));
        self::assertSame($columns, $template->filter('section.lp-board__column')->each(static fn (Crawler $node): string => (string) $node->attr('data-column-id')));
        foreach ($columns as $columnId) {
            $group = $template->filter('section#board-column-'.$columnId.' > #board-group-'.$columnId);
            self::assertCount(1, $group);
            self::assertSame($columnId, $group->attr('data-column'));
            self::assertSame('group', $group->attr('data-board-drag-target'));
            self::assertSame('', trim($group->html()));
            self::assertCount(1, $template->filter('#board-count-'.$columnId));
        }
        self::assertSame('1', $template->filter('#board-count-'.$card->column->id)->text());
        self::assertCount(1, $template->filter('[id^="board-history-"]'));

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');
        self::assertSame(
            $client->getCrawler()->filter('#board')->attr('data-board-structure-digest'),
            $stream->attr('data-structure-digest'),
        );
    }

    public function test_a_board_with_a_lane_draws_the_lane_and_empty_cells(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'structure-lanes@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Lane epic', 'next'), CardType::Epic);
        $this->childOf($em, $epic, $this->card($em, $project, 'Child in the lane', 'in-progress'));
        $this->card($em, $project, 'Outside the lane', 'in-progress');
        $columns = $this->columnIds($project);
        $inProgressId = (string) $this->column($project, 'in-progress')->id;
        $backlogId = (string) $this->column($project, 'backlog')->id;
        $em->clear();

        $client->loginUser($owner);
        [$stream, $template] = $this->structure($client, $project);

        self::assertCount(0, $template->filter('.lp-board-card'));
        self::assertStringNotContainsString('Child in the lane', $template->html());
        self::assertStringNotContainsString('Outside the lane', $template->html());
        self::assertCount(1, $template->filter('.lp-board__columns--lanes'));
        $lane = $template->filter('section#board-lane-'.$epic->id);
        self::assertCount(1, $lane);
        self::assertStringContainsString('Lane epic', $lane->filter('.lp-board-lane__head')->text());
        self::assertSame('0/1 done', $lane->filter('[data-lane-progress]')->text());
        self::assertCount(1, $template->filter('.lp-board-lane--other'));
        foreach ([(string) $epic->id, 'other'] as $laneKey) {
            foreach ($columns as $columnId) {
                $cell = $template->filter('#board-cell-'.$laneKey.'-'.$columnId);
                self::assertCount(1, $cell);
                self::assertSame('', trim($cell->html()));
            }
        }
        self::assertSame('1', $lane->filter('section[data-column-id="'.$inProgressId.'"] [data-cell-count]')->text());
        self::assertSame('1', $template->filter('.lp-board-lane--other section[data-column-id="'.$inProgressId.'"] [data-cell-count]')->text());
        self::assertCount(0, $template->filter('[data-column-id="'.$backlogId.'"]'));
        self::assertCount(\count($columns), $template->filter('.lp-board-lane--other .lp-board__add-card'));

        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');
        self::assertSame(
            $client->getCrawler()->filter('#board')->attr('data-board-structure-digest'),
            $stream->attr('data-structure-digest'),
        );
    }

    public function test_the_column_tags_come_in_column_order(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'structure-tags@example.com');
        $project = $this->project($em, $owner);
        $columns = $this->columnIds($project);
        $em->clear();

        $client->loginUser($owner);
        [, $template] = $this->structure($client, $project);

        $tags = $template->filter('[data-board-structure-tags] > [data-column-tag]');
        self::assertSame($columns, $tags->each(static fn (Crawler $node): string => (string) $node->attr('data-column-tag')));
        self::assertSame('Next', trim($tags->eq(0)->text()));
    }

    public function test_a_list_row_names_its_column_tag(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'structure-row-tag@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Tagged', 'next');
        $nextId = (string) $this->column($project, 'next')->id;
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/list');

        self::assertResponseIsSuccessful();
        $tag = $crawler->filter('#board-row-'.$card->id.' > [data-column-tag]');
        self::assertCount(1, $tag);
        self::assertSame($nextId, $tag->attr('data-column-tag'));
    }

    private function structureUrl(Project $project): string
    {
        return '/projects/'.$project->id.'/board/structure';
    }

    /** @return array{Crawler, Crawler} the stream element, then its template content */
    private function structure(KernelBrowser $client, Project $project): array
    {
        $client->request(Request::METHOD_GET, $this->structureUrl($project));
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith(TurboBundle::STREAM_MEDIA_TYPE, (string) $client->getResponse()->headers->get('Content-Type'));

        $content = (string) $client->getResponse()->getContent();
        self::assertSame(1, substr_count($content, '<turbo-stream'));
        $crawler = new Crawler($content);
        $stream = $crawler->filter('turbo-stream');
        self::assertCount(1, $stream);
        self::assertSame('board-structure', $stream->attr('action'));
        self::assertSame('board', $stream->attr('target'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', (string) $stream->attr('data-structure-digest'));

        $template = $stream->filter('template');
        self::assertCount(1, $template);

        return [$stream, new Crawler('<div>'.$template->html().'</div>')];
    }

    /** @return list<string> the columns the board draws, which leave the Backlog out */
    private function columnIds(Project $project): array
    {
        return array_map(
            fn (string $slug): string => (string) $this->column($project, $slug)->id,
            ['next', 'in-progress', 'done'],
        );
    }
}
