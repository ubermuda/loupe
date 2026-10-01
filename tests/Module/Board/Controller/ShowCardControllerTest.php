<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardAutomation;
use App\Module\Board\Entity\CardAutomationAction;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Entity\CardLink;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class ShowCardControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_the_card_page_shows_the_stored_state_of_each_pull_request(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'card-pull-state@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Ship the state');
        $failing = $this->link($card, 7);
        $merged = $this->link($card, 8);
        $draft = $this->link($card, 9);
        $unread = $this->link($card, 10);
        $card->replacePullRequests($failing, $merged, $draft, $unread);

        $row = $this->row($em, $project, 7);
        $row->checks = PullRequestChecks::Failed;
        $row->failedChecks = ['phpunit', 'phpstan'];
        $row->mergeability = PullRequestMergeability::Conflicting;
        $row->review = PullRequestReview::ChangesRequested;
        $this->row($em, $project, 8)->state = PullRequestState::Merged;
        $draftRow = $this->row($em, $project, 9);
        $draftRow->draft = true;
        $draftRow->checks = PullRequestChecks::Passed;
        $draftRow->review = PullRequestReview::Approved;
        // Linking creates the row before any forge read, so it holds only defaults.
        $em->persist(new ForgePullRequest($project, 'github', 'loupe/loupe', 10));
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $failingRow = $crawler->filter('[data-linked-pull-request="'.$failing->id.'"]');
        self::assertSame(['Open', 'Checks failed', 'Conflict', 'Changes requested'], $this->chips($failingRow));
        self::assertSame(3, $failingRow->filter('.lp-status-chip--failed')->count());
        self::assertSame('Failed checks: phpunit, phpstan', trim($failingRow->filter('.lp-pull-request-state__failed')->text()));
        self::assertSame(['Merged'], $this->chips($crawler->filter('[data-linked-pull-request="'.$merged->id.'"]')));
        self::assertSame(['Draft', 'Checks passed', 'Approved'], $this->chips($crawler->filter('[data-linked-pull-request="'.$draft->id.'"]')));
        $unreadRow = $crawler->filter('[data-linked-pull-request="'.$unread->id.'"]');
        self::assertSame([], $this->chips($unreadRow));
        self::assertStringContainsString('Not reported', $unreadRow->text());
    }

    public function test_the_card_page_says_not_reported_when_no_state_is_stored(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'card-pull-no-state@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'No state yet');
        $link = $this->link($card, 7);
        $card->replacePullRequests($link);
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-linked-pull-request="'.$link->id.'"]');
        self::assertCount(1, $row);
        self::assertSame([], $this->chips($row));
        self::assertStringContainsString('Not reported', $row->text());
        self::assertCount(0, $crawler->filter('[data-card-automation]'));
    }

    public function test_the_card_page_shows_the_last_automation_action_and_its_block(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'card-automation-blocked@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Stuck in a loop');
        $card->replacePullRequests($this->link($card, 7));
        $automation = new CardAutomation($card);
        $automation->fixRounds = 3;
        $automation->blockedReason = 'checks-failed';
        $automation->lastAction = CardAutomationAction::Stopped;
        $automation->lastActionAt = new \DateTimeImmutable('-2 hours');
        $em->persist($automation);
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $line = $crawler->filter('[data-card-automation]');
        self::assertCount(1, $line);
        self::assertStringContainsString('The automation stopped.', $line->text());
        self::assertStringContainsString('2h ago', $line->filter('time')->text());
        $blocked = $crawler->filter('[data-card-automation-blocked]');
        self::assertCount(1, $blocked);
        self::assertStringContainsString('the checks fail', $blocked->text());
        self::assertStringContainsString('when a person moves the card, when the checks pass, or when a reviewer approves or requests changes', $blocked->text());
    }

    public function test_an_approved_card_names_the_open_blockers_that_hold_it(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'card-held@example.com');
        $project = $this->project($em, $owner);
        $em->persist(new BoardColumn(project: $project, label: 'Product design', slug: 'product-design', position: 4));
        $em->flush();
        $finished = $this->card($em, $project, 'Finished', 'done');
        $first = $this->card($em, $project, 'First blocker');
        $second = $this->card($em, $project, 'Second blocker', 'in-progress');
        $held = $this->card($em, $project, 'Waiting', 'product-design');
        $document = new Document($owner, $project, 'A product');
        $tag = new Tag($project, 'product');
        $document->tags->add($tag);
        $document->status = DocumentStatus::Approved;
        $em->persist($tag);
        $em->persist($document);
        $em->persist(new CardDocument($held, $document));
        foreach ([$finished, $second, $first] as $blocker) {
            $em->persist(new CardLink($blocker, $held, CardLinkKind::Blocks));
        }
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$held->id);

        self::assertResponseIsSuccessful();
        $note = $crawler->filter('[data-card-held]');
        self::assertCount(1, $note);
        self::assertStringContainsString('Approved, and waits for its blockers:', $note->text());
        $links = $note->filter('[data-card-held-blocker]');
        self::assertSame(['#'.$first->number, '#'.$second->number], $links->each(static fn (Crawler $link): string => $link->text()));
        self::assertSame('/projects/'.$project->id.'/board/cards/'.$first->id, $links->first()->attr('href'));

        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$first->id);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-card-held]'));
    }

    public function test_a_finished_card_shows_no_block(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'card-automation-finished@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Merged by the agent', 'done');
        $automation = new CardAutomation($card);
        $automation->blockedReason = 'checks-failed';
        $automation->lastAction = CardAutomationAction::Stopped;
        $em->persist($automation);
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-card-automation-blocked]'));
    }

    public function test_a_stop_whose_block_was_cleared_shows_no_line(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'card-automation-cleared@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Moved back by a person');
        $automation = new CardAutomation($card);
        $automation->lastAction = CardAutomationAction::Stopped;
        $automation->lastActionAt = new \DateTimeImmutable('-2 hours');
        $em->persist($automation);
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-card-automation]'));
    }

    public function test_the_card_page_shows_a_fix_round_with_no_block(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'card-automation-fix@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Fix requested');
        $card->replacePullRequests($this->link($card, 7));
        $automation = new CardAutomation($card);
        $automation->fixRounds = 2;
        $automation->lastAction = CardAutomationAction::FixRequested;
        $em->persist($automation);
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('The automation asked for a fix.', $crawler->filter('[data-card-automation]')->text());
        self::assertCount(0, $crawler->filter('[data-card-automation] time'));
        self::assertCount(0, $crawler->filter('[data-card-automation-blocked]'));
    }

    private function link(Card $card, int $number): CardPullRequest
    {
        return new CardPullRequest(
            card: $card,
            url: 'https://github.com/loupe/loupe/pull/'.$number,
            forge: Forge::GitHub,
            repository: 'loupe/loupe',
            number: $number,
        );
    }

    private function row(EntityManagerInterface $em, Project $project, int $number): ForgePullRequest
    {
        $row = new ForgePullRequest($project, 'github', 'loupe/loupe', $number);
        $row->refreshedAt = new \DateTimeImmutable();
        $em->persist($row);

        return $row;
    }

    /** @return list<string> */
    private function chips(Crawler $row): array
    {
        return $row->filter('.lp-pull-request-state > .lp-status-chip')->each(
            static function (Crawler $chip): string {
                $tooltip = $chip->filter('.lp-tooltip');

                return trim(str_replace(0 === $tooltip->count() ? '' : $tooltip->text(), '', $chip->text()));
            },
        );
    }
}
