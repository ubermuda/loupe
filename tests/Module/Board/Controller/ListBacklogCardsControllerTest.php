<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Command\ListBacklogCardsHandler;
use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class ListBacklogCardsControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_the_backlog_lists_its_cards_in_rank_order_with_their_epic(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-page@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Big epic', 'next'), CardType::Epic);
        $this->card($em, $project, 'Second', 'backlog', 1);
        $this->childOf($em, $epic, $this->card($em, $project, 'First', 'backlog', 0));
        $this->card($em, $project, 'On the board', 'next', 1);
        $url = $this->backlogUrl($project);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-backlog-card-id]');
        self::assertSame(['First', 'Second'], $rows->filter('.lp-done-row__title')->each(
            static fn (Crawler $node): string => trim($node->text()),
        ));
        self::assertStringContainsString('Big epic', $rows->first()->text());
    }

    public function test_the_backlog_paginates(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-paging@example.com');
        $project = $this->project($em, $owner);
        for ($index = 0; $index < ListBacklogCardsHandler::PER_PAGE + 3; ++$index) {
            $this->card($em, $project, 'Waiting '.$index, 'backlog', $index);
        }
        $url = $this->backlogUrl($project);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();
        self::assertCount(ListBacklogCardsHandler::PER_PAGE, $crawler->filter('[data-backlog-card-id]'));

        $crawler = $client->request(Request::METHOD_GET, $url.'?page=2');
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('[data-backlog-card-id]'));

        $client->request(Request::METHOD_GET, $url.'?page=9');
        self::assertResponseRedirects($url.'?page=2');
    }

    public function test_an_empty_backlog_says_so(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-empty@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'On the board', 'next');
        $url = $this->backlogUrl($project);
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-backlog-card-id]'));
        self::assertCount(1, $crawler->filter('.lp-empty-state'));
    }

    public function test_the_backlog_is_not_found_while_the_flag_is_off(): void
    {
        $client = static::createClient();
        $this->disableBoard();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'backlog-flag-off@example.com');
        $project = $this->project($em, $owner);
        $url = $this->backlogUrl($project);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_stranger_is_forbidden(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'backlog-owner@example.com');
        $stranger = $this->user($em, 'backlog-stranger@example.com');
        $project = $this->project($em, $owner);
        $url = $this->backlogUrl($project);
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(403);
    }

    private function backlogUrl(Project $project): string
    {
        return '/projects/'.$project->id.'/board/backlog';
    }
}
