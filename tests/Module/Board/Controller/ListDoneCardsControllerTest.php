<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Command\ListDoneCardsHandler;
use App\Module\Board\Entity\CardStatus;
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
        $older = $this->card($em, $project, 'Older card', CardStatus::Done);
        $older->completedAt = new \DateTimeImmutable('-40 days');
        $newer = $this->card($em, $project, 'Newer card', CardStatus::Done);
        $newer->completedAt = new \DateTimeImmutable('-1 day');
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/done');

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
            $card = $this->card($em, $project, 'Finished '.$index, CardStatus::Done);
            $card->completedAt = new \DateTimeImmutable(\sprintf('-%d minutes', $index));
        }
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/done');
        self::assertResponseIsSuccessful();
        self::assertCount(ListDoneCardsHandler::PER_PAGE, $crawler->filter('.lp-done-row'));

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/done?page=2');
        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('.lp-done-row'));

        // A page past the end lands on the last one rather than on an empty list.
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/done?page=9');
        self::assertResponseRedirects('/projects/'.$project->id.'/board/done?page=2');
    }

    public function test_the_history_is_not_found_while_the_flag_is_off(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->user($em, 'done-flag-off@example.com');
        $project = $this->project($em, $owner);
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/done');

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
        $em->clear();

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/done');

        self::assertResponseStatusCodeSame(403);
    }
}
