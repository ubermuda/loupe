<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Command\ListDoneCardsHandler;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class ListDoneCardsControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_the_history_lists_every_done_card_newest_first(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'done-history@example.com');
        $project = $this->project($em, $owner);
        $older = $this->card($em, $project, 'Older card', 'done');
        $older->completedAt = new \DateTimeImmutable('-40 days');
        $newer = $this->card($em, $project, 'Newer card', 'done');
        $newer->completedAt = new \DateTimeImmutable('-1 day');
        $em->flush();
        $url = $this->historyUrl($project, 'done');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $titles = $crawler->filter('.lp-done-row__title')->each(
            static fn (Crawler $node): string => trim($node->text()),
        );
        self::assertSame(['Newer card', 'Older card'], $titles);
    }

    public function test_the_history_paginates(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'done-paging@example.com');
        $project = $this->project($em, $owner);
        for ($index = 0; $index < ListDoneCardsHandler::PER_PAGE + 3; ++$index) {
            $card = $this->card($em, $project, 'Finished '.$index, 'done');
            $card->completedAt = new \DateTimeImmutable(\sprintf('-%d minutes', $index));
        }
        $em->flush();
        $url = $this->historyUrl($project, 'done');
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();
        self::assertCount(ListDoneCardsHandler::PER_PAGE, $crawler->filter('.lp-done-row'));

        $crawler = $client->request(Request::METHOD_GET, $url.'?page=2');
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('.lp-done-row'));

        // A page past the end lands on the last one rather than on an empty list.
        $client->request(Request::METHOD_GET, $url.'?page=9');
        self::assertResponseRedirects($url.'?page=2');
    }

    public function test_a_column_that_is_not_terminal_has_no_history(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'done-open-column@example.com');
        $project = $this->project($em, $owner);
        $open = $this->historyUrl($project, 'next');
        $done = $this->historyUrl($project, 'done');
        $em->clear();

        $client->loginUser($owner);
        // Guard: the terminal column of the same board answers, so the 404 below is about the column.
        $client->request(Request::METHOD_GET, $done);
        self::assertResponseIsSuccessful();

        $client->request(Request::METHOD_GET, $open);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_column_of_another_board_is_not_found(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();

        $owner = $this->user($em, 'done-cross-board@example.com');
        $mine = $this->project($em, $owner, 'mine');
        $other = $this->project($em, $owner, 'other');
        $url = '/projects/'.$mine->id.'/board/done/'.$this->column($other, 'done')->id;
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_history_is_not_found_while_the_flag_is_off(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'done-flag-off@example.com');
        $project = $this->project($em, $owner);
        $url = $this->historyUrl($project, 'done');
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

        $owner = $this->user($em, 'done-owner@example.com');
        $stranger = $this->user($em, 'done-stranger@example.com');
        $project = $this->project($em, $owner);
        $url = $this->historyUrl($project, 'done');
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(403);
    }

    private function historyUrl(Project $project, string $slug): string
    {
        return '/projects/'.$project->id.'/board/done/'.$this->column($project, $slug)->id;
    }
}
