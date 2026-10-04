<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\BoardAutomationSettings;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardAutomation;
use App\Module\Board\Entity\CardAutomationAction;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\Forge;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
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

    public function test_the_card_page_says_an_approval_of_an_older_head_is_outdated(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'card-pull-outdated@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Approved before a push');
        $outdated = $this->link($card, 7);
        $current = $this->link($card, 8);
        $card->replacePullRequests($outdated, $current);
        $stale = $this->approved($this->inLine($this->row($em, $project, 7)));
        $stale->headSha = 'pushed7';
        $stale->checks = PullRequestChecks::Passed;
        $this->approved($this->inLine($this->row($em, $project, 8)))->checks = PullRequestChecks::Passed;
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $outdatedRow = $crawler->filter('[data-linked-pull-request="'.$outdated->id.'"]');
        self::assertSame(['Open', 'Checks passed', 'Approval outdated'], $this->chips($outdatedRow));
        self::assertSame(1, $outdatedRow->filter('.lp-status-chip--pending')->count());
        self::assertSame(['Open', 'Checks passed', 'Approved'], $this->chips($crawler->filter('[data-linked-pull-request="'.$current->id.'"]')));
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

    public function test_the_card_page_shows_the_last_automation_action(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'card-automation-synced@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Stuck in a loop');
        $card->replacePullRequests($this->link($card, 7));
        $automation = new CardAutomation($card);
        $automation->lastAction = CardAutomationAction::Synced;
        $automation->lastActionAt = new \DateTimeImmutable('-2 hours');
        $em->persist($automation);
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $line = $crawler->filter('[data-card-automation]');
        self::assertCount(1, $line);
        self::assertStringContainsString('The automation updated the pull request branch with its base.', $line->text());
        self::assertStringContainsString('2h ago', $line->filter('time')->text());
    }

    public function test_a_stop_shows_no_line(): void
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

    public function test_the_card_page_shows_a_fix_round(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'card-automation-fix@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Fix requested');
        $card->replacePullRequests($this->link($card, 7));
        $automation = new CardAutomation($card);
        $automation->lastAction = CardAutomationAction::FixRequested;
        $em->persist($automation);
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('The automation asked for a fix.', $crawler->filter('[data-card-automation]')->text());
        self::assertCount(0, $crawler->filter('[data-card-automation] time'));
    }

    public function test_the_card_page_shows_the_sync_status_of_each_open_pull_request(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'card-sync-status@example.com');
        $project = $this->project($em, $owner);
        $em->persist(new BoardAutomationSettings($project, syncBehind: true));
        $card = $this->card($em, $project, 'Keep up with main');
        $links = [];
        foreach ([20, 21, 22, 23, 24, 25, 26] as $number) {
            $links[$number] = $this->link($card, $number);
        }
        $card->replacePullRequests(...array_values($links));

        $this->inLine($this->row($em, $project, 20))->mergeability = PullRequestMergeability::Behind;
        $this->approved($this->inLine($this->row($em, $project, 21)))->syncFailedReason = 'permission';
        $this->approved($this->inLine($this->row($em, $project, 22)))->syncFailedReason = 'api_failed_http_status_500';
        $holder = $this->approved($this->inLine($this->row($em, $project, 23)));
        $holder->mergeability = PullRequestMergeability::Mergeable;
        $holder->syncedSha = $holder->headSha;
        $this->approved($this->inLine($this->row($em, $project, 24)))->mergeability = PullRequestMergeability::Behind;
        $this->approved($this->inLine($this->row($em, $project, 25)))->mergeability = PullRequestMergeability::Conflicting;
        $this->approved($this->inLine($this->row($em, $project, 26)))->syncFailedReason = 'retries_exhausted';
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        $expected = [
            20 => ['waits-for-approval', 'Waits for an approval'],
            21 => ['sync-failed', 'Sync failed: the app may not write to the repository'],
            22 => ['sync-failed', 'Sync failed: api_failed_http_status_500'],
            23 => ['synced-checks-running', 'Synced, checks running'],
            24 => ['waits-turn', 'Waits its turn behind #23'],
            25 => ['conflicts', 'Conflicts with the base'],
            26 => ['sync-failed', 'Sync failed: the forge failed each try to update the branch'],
        ];
        foreach ($expected as $number => [$status, $text]) {
            $line = $crawler->filter('[data-linked-pull-request="'.$links[$number]->id.'"] [data-pull-request-sync]');
            self::assertCount(1, $line, 'Pull request #'.$number);
            self::assertSame($status, $line->attr('data-pull-request-sync'));
            self::assertSame($text, trim($line->text()));
        }
    }

    public function test_the_card_page_shows_no_sync_status_while_the_setting_is_off(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'card-sync-status-off@example.com');
        $project = $this->project($em, $owner);
        $em->persist(new BoardAutomationSettings($project, syncBehind: false));
        $card = $this->card($em, $project, 'Left behind');
        $link = $this->link($card, 30);
        $card->replacePullRequests($link);
        $this->inLine($this->row($em, $project, 30))->mergeability = PullRequestMergeability::Behind;
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/cards/'.$card->id);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-linked-pull-request="'.$link->id.'"] [data-pull-request-state="open"]'));
        self::assertCount(0, $crawler->filter('[data-pull-request-sync]'));
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

    private function inLine(ForgePullRequest $row): ForgePullRequest
    {
        $row->headSha = 'head'.$row->number;
        $row->baseBranch = 'main';
        $row->defaultBranch = 'main';

        return $row;
    }

    private function approved(ForgePullRequest $row): ForgePullRequest
    {
        $row->approvalId = 'review-'.$row->number;
        $row->approvalSha = $row->headSha;
        $row->coveredSha = $row->headSha;
        $row->approvedAt = new \DateTimeImmutable('-'.$row->number.' minutes');
        $row->review = PullRequestReview::Approved;

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
