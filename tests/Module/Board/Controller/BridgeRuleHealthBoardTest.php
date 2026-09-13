<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BridgeRuleReport;
use App\Module\Project\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class BridgeRuleHealthBoardTest extends WebTestCase
{
    use BoardScenario;

    private const string BANNER = '[data-testid="bridge-rules-banner"]';

    public function test_the_banner_names_each_dead_rule_its_columns_its_reason_and_when(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $this->enableBoard();
        $owner = $this->user($em, 'rules-banner@example.com');
        $project = $this->project($em, $owner);
        $this->report($em, $project, [
            $this->rule('plan', ['ready'], 'dead', 'column_renamed'),
            $this->rule('review', ['next'], 'live'),
        ], new \DateTimeImmutable('2026-09-13 08:15:00'));
        $this->report($em, $project, [$this->rule('triage', ['backlog', 'next'], 'dead', 'unknown_column')]);

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseIsSuccessful();
        $banner = $crawler->filter(self::BANNER);
        self::assertCount(1, $banner);
        self::assertStringContainsString('A bridge reports 2 dead rules', $banner->text());

        $rules = $banner->filter('[data-bridge-rule]');
        // The newest report reads first.
        self::assertSame(['triage', 'plan'], $rules->each(static fn (Crawler $node): string => (string) $node->attr('data-bridge-rule')));
        $plan = $banner->filter('[data-bridge-rule="plan"]')->text();
        self::assertStringContainsString('ready', $plan);
        self::assertStringContainsString('column_renamed', $plan);
        self::assertStringContainsString('Reported Sep 13, 2026 08:15', $plan);
        $triage = $banner->filter('[data-bridge-rule="triage"]')->text();
        self::assertStringContainsString('backlog, next', $triage);
        self::assertStringContainsString('unknown_column', $triage);
        self::assertStringNotContainsString('review', $banner->text());
    }

    public function test_no_banner_without_a_report(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $this->enableBoard();
        $owner = $this->user($em, 'rules-banner-none@example.com');
        $project = $this->project($em, $owner);

        $crawler = $this->board($client, $owner, $project);

        self::assertCount(1, $crawler->filter('.lp-board'));
        self::assertCount(0, $crawler->filter(self::BANNER));
    }

    public function test_no_banner_while_every_rule_is_live(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $this->enableBoard();
        $owner = $this->user($em, 'rules-banner-live@example.com');
        $project = $this->project($em, $owner);
        $this->report($em, $project, [$this->rule('plan', ['next'], 'live'), $this->rule('review', ['in-progress'], 'live')]);

        $crawler = $this->board($client, $owner, $project);

        self::assertCount(1, $crawler->filter('.lp-board'));
        self::assertCount(0, $crawler->filter(self::BANNER));
    }

    public function test_another_projects_report_shows_no_banner_and_no_warning(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $this->enableBoard();
        $owner = $this->user($em, 'rules-banner-other@example.com');
        $project = $this->project($em, $owner, 'Quiet App');
        $other = $this->project($em, $owner, 'Noisy App');
        $this->report($em, $other, [$this->rule('plan', ['next'], 'live'), $this->rule('gone', ['ready'], 'dead', 'column_deleted')]);

        $crawler = $this->board($client, $owner, $project);

        self::assertCount(0, $crawler->filter(self::BANNER));
        self::assertCount(0, $crawler->filter('.lp-board__rule-warning'));
    }

    public function test_a_stranger_gets_no_board_and_so_no_banner(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $this->enableBoard();
        $project = $this->project($em, $this->user($em, 'rules-banner-owner@example.com'));
        $stranger = $this->user($em, 'rules-banner-stranger@example.com');
        $this->report($em, $project, [$this->rule('plan', ['ready'], 'dead', 'column_renamed')]);

        $client->loginUser($stranger);
        $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('column_renamed', (string) $client->getResponse()->getContent());
    }

    public function test_the_rename_and_delete_dialogs_warn_when_a_live_rule_watches_the_column(): void
    {
        $client = static::createClient();
        $em = $this->em();
        $this->enableBoard();
        $owner = $this->user($em, 'rules-warning@example.com');
        $project = $this->project($em, $owner);
        $this->report($em, $project, [
            $this->rule('plan', ['next'], 'live'),
            // A dead rule no longer matches, so renaming its column breaks nothing new.
            $this->rule('stale', ['in-progress'], 'dead', 'column_renamed'),
        ]);

        $crawler = $this->board($client, $owner, $project);

        [$rename, $delete] = $this->dialogs($crawler, 'next');
        self::assertStringContainsString('A bridge rule watches this column. Renaming it changes its slug', $rename->filter('.lp-board__rule-warning')->text());
        self::assertStringContainsString('A bridge rule watches this column. Deleting it', $delete->filter('.lp-board__rule-warning')->text());

        [$rename, $delete] = $this->dialogs($crawler, 'in-progress');
        self::assertCount(0, $rename->filter('.lp-board__rule-warning'));
        self::assertCount(0, $delete->filter('.lp-board__rule-warning'));

        self::assertCount(0, $this->columnSection($crawler, 'backlog')->filter('.lp-board__rule-warning'));
    }

    /** @return array{Crawler, Crawler} the rename dialog, then the delete dialog */
    private function dialogs(Crawler $crawler, string $slug): array
    {
        $dialogs = $this->columnSection($crawler, $slug)->filter('dialog');
        self::assertCount(2, $dialogs);

        return [$dialogs->eq(0), $dialogs->eq(1)];
    }

    private function columnSection(Crawler $crawler, string $slug): Crawler
    {
        $column = $crawler->filter('[data-column-slug="'.$slug.'"]');
        self::assertCount(1, $column);

        return $column;
    }

    private function board(KernelBrowser $client, User $owner, Project $project): Crawler
    {
        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * @param list<string> $columns
     *
     * @return array{name: string, on: string, columns: list<string>, state: string, reason: ?string}
     */
    private function rule(string $name, array $columns, string $state, ?string $reason = null): array
    {
        return ['name' => $name, 'on' => 'board.card_moved', 'columns' => $columns, 'state' => $state, 'reason' => $reason];
    }

    /** @param list<array{name: string, on: string, columns: list<string>, state: string, reason: ?string}> $rules */
    private function report(EntityManagerInterface $em, Project $project, array $rules, ?\DateTimeImmutable $receivedAt = null): void
    {
        $em->persist(new BridgeRuleReport($project, Uuid::v4(), $rules, $receivedAt ?? new \DateTimeImmutable()));
        $em->flush();
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
