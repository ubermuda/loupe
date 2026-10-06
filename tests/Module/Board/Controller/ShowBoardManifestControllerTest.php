<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Entity\Forge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\PullRequestSnapshot;
use App\Module\Project\Entity\Project;
use App\Session\ReadOnlyAwareSessionHandler;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

final class ShowBoardManifestControllerTest extends WebTestCase
{
    use BoardScenario;

    public function test_an_anonymous_visitor_is_sent_to_the_login(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $project = $this->project($em, $this->user($em, 'manifest-anonymous@example.com'));
        $this->addTriageColumn($project);
        $em->flush();
        $em->clear();

        $client->request(Request::METHOD_GET, $this->manifestUrl($project));

        self::assertResponseRedirects('/login');
    }

    public function test_an_outsider_is_refused(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $project = $this->project($em, $this->user($em, 'manifest-owner@example.com'));
        $this->addTriageColumn($project);
        $em->flush();
        $this->card($em, $project, 'Private');
        $outsider = $this->user($em, 'manifest-outsider@example.com');
        $em->clear();

        $client->loginUser($outsider);
        $client->request(Request::METHOD_GET, $this->manifestUrl($project));

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('Private', (string) $client->getResponse()->getContent());
    }

    public function test_the_manifest_is_not_found_while_the_board_is_off(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user($em, 'manifest-flag-off@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $this->disableBoard();
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->manifestUrl($project));

        self::assertResponseStatusCodeSame(404);
    }

    public function test_the_manifest_route_does_not_write_the_session(): void
    {
        $route = static::getContainer()->get(RouterInterface::class)->getRouteCollection()->get('app_board_manifest');

        self::assertNotNull($route);
        self::assertTrue($route->getDefault(ReadOnlyAwareSessionHandler::READ_ONLY));
    }

    public function test_an_empty_board_has_an_empty_card_list(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'manifest-empty@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->manifestUrl($project));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"cards":[]', (string) $client->getResponse()->getContent());
    }

    public function test_the_cards_come_in_board_order_with_the_digests_of_the_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'manifest-order@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $triageSecond = $this->card($em, $project, 'Triage second', 'triage', 1);
        $triageFirst = $this->card($em, $project, 'Triage first', 'triage', 0);
        $done = $this->card($em, $project, 'Finished', 'done');
        $epic = $this->closedEpic($em, $this->card($em, $project, 'Closed epic', 'next', 0));
        $child = $this->childOf($em, $epic, $this->card($em, $project, 'Child', 'next', 1));
        $doneChild = $this->childOf($em, $epic, $this->card($em, $project, 'Done child', 'done'));
        $this->linkPullRequest($em, $child);
        $this->warn($em, $project, $triageFirst);
        $em->clear();

        $client->loginUser($owner);
        $manifest = $this->manifest($client, $project);
        $page = $this->page($client, $project);

        self::assertSame(
            [(string) $triageFirst->id, (string) $triageSecond->id, (string) $epic->id, (string) $child->id, (string) $doneChild->id, (string) $done->id],
            array_column($manifest['cards'], 0),
        );
        self::assertSame($page['rows'], array_column($manifest['cards'], 0));
        self::assertSame($page['cards'], array_column($manifest['cards'], 1, 0));
        self::assertSame(
            array_map(static fn (Card $card): string => (string) $card->column->id, [$triageFirst, $triageSecond, $epic, $child, $doneChild, $done]),
            array_column($manifest['cards'], 2),
        );
        self::assertSame(array_fill(0, 6, null), array_column($manifest['cards'], 3));
        self::assertSame($page['lanes'], array_column($manifest['cards'], 3, 0));
    }

    public function test_a_lane_epic_is_flagged_as_a_lane_head_and_keeps_its_list_row_place(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'manifest-lane@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $epic = $this->typed($em, $this->card($em, $project, 'Lane epic', 'next'), CardType::Epic);
        $child = $this->childOf($em, $epic, $this->card($em, $project, 'In the lane', 'triage', 0));
        $other = $this->card($em, $project, 'Outside', 'triage', 1);
        $em->clear();

        $client->loginUser($owner);
        $manifest = $this->manifest($client, $project);
        $page = $this->page($client, $project);

        self::assertContains((string) $epic->id, $page['rows']);
        self::assertArrayNotHasKey((string) $epic->id, $page['cards']);
        self::assertSame([(string) $child->id, (string) $other->id, (string) $epic->id], array_column($manifest['cards'], 0));
        self::assertSame($page['rows'], array_column($manifest['cards'], 0));
        self::assertSame([4, 4, 5], array_map(\count(...), $manifest['cards']));
        self::assertSame($page['laneDigests'][(string) $epic->id], $manifest['cards'][2][4] ?? null);
        self::assertSame([(string) $epic->id, 'other', (string) $epic->id], array_column($manifest['cards'], 3));
        self::assertSame($page['lanes'], array_column($this->faces($manifest), 3, 0));
        self::assertSame($page['cards'], array_column($this->faces($manifest), 1, 0));
        self::assertSame($page['rowDigests'][(string) $epic->id], $manifest['cards'][2][1]);
    }

    public function test_a_lane_epic_in_the_backlog_is_a_lane_head_with_the_digest_its_deck_shows(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'manifest-backlog-lane@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $epic = $this->typed($em, $this->card($em, $project, 'Waiting epic', 'backlog'), CardType::Epic);
        $this->childOf($em, $epic, $this->card($em, $project, 'Waiting child', 'backlog', 1));
        $other = $this->card($em, $project, 'Outside', 'triage');
        $joining = $this->card($em, $project, 'Waiting with no epic yet', 'backlog', 2);
        $em->clear();

        $client->loginUser($owner);
        $manifest = $this->manifest($client, $project);
        $page = $this->page($client, $project);

        self::assertSame([(string) $other->id, (string) $epic->id], array_column($manifest['cards'], 0));
        self::assertSame((string) $this->column($project, 'backlog')->id, $manifest['cards'][1][2]);
        self::assertSame((string) $epic->id, $manifest['cards'][1][3]);
        self::assertSame($page['laneDigests'], [(string) $epic->id => $manifest['cards'][1][4] ?? null]);

        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            'UPDATE board_cards SET parent_card_id = :epic WHERE id = :card',
            ['epic' => (string) $epic->id, 'card' => (string) $joining->id],
        );

        self::assertNotSame($page['laneDigests'][(string) $epic->id], $this->manifest($client, $project)['cards'][1][4] ?? null);
    }

    public function test_the_terminal_totals_are_the_ones_the_history_links_show(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'manifest-history@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $this->card($em, $project, 'Finished today', 'done');
        $old = $this->card($em, $project, 'Finished long ago', 'done');
        $old->completedAt = new \DateTimeImmutable('-1 year');
        $em->flush();
        $doneId = (string) $this->column($project, 'done')->id;
        $em->clear();

        $client->loginUser($owner);
        $manifest = $this->manifest($client, $project);
        $page = $this->page($client, $project);

        self::assertCount(1, $manifest['cards']);
        self::assertSame([$doneId => 2], $manifest['terminalTotals']);
        self::assertSame($page['historyTotals'], $manifest['terminalTotals']);
    }

    public function test_the_backlog_count_is_the_one_the_backlog_button_shows(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'manifest-backlog-count@example.com');
        $project = $this->project($em, $owner);
        $this->card($em, $project, 'Waiting');
        $this->card($em, $project, 'Also waiting', 'backlog', 1);
        $this->card($em, $project, 'Started', 'next');
        $backlogId = (string) $this->column($project, 'backlog')->id;
        $em->clear();

        $client->loginUser($owner);
        $manifest = $this->manifest($client, $project);
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');

        self::assertSame(2, $manifest['backlogCount']);
        self::assertSame('2', $crawler->filter('#board-count-'.$backlogId)->text());
    }

    public function test_a_digest_changes_with_the_parent_title(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'manifest-parent@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $parent = $this->card($em, $project, 'Parent', 'next');
        $child = $this->childOf($em, $parent, $this->card($em, $project, 'Child', 'triage'));
        $em->clear();

        $client->loginUser($owner);
        $before = $this->digestOf($this->manifest($client, $project), $child);
        $this->updateCard($parent, static function (Card $card): void {
            $card->title = 'Renamed parent';
        });
        $after = $this->manifest($client, $project);

        self::assertNotSame($before, $this->digestOf($after, $child));
        self::assertSame($this->page($client, $project)['cards'], array_column($after['cards'], 1, 0));
    }

    public function test_a_digest_changes_with_the_progress_of_an_epic(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'manifest-progress@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $epic = $this->closedEpic($em, $this->card($em, $project, 'Epic', 'next'));
        $child = $this->childOf($em, $epic, $this->card($em, $project, 'Child'));
        $em->clear();

        $client->loginUser($owner);
        $before = $this->digestOf($this->manifest($client, $project), $epic);
        $done = $this->column($project, 'done');
        $this->updateCard($child, static function (Card $card) use ($done): void {
            $card->column = $done;
            $card->completedAt = new \DateTimeImmutable();
        });
        $after = $this->manifest($client, $project);

        self::assertNotSame($before, $this->digestOf($after, $epic));
        self::assertSame($this->page($client, $project)['cards'], array_column($after['cards'], 1, 0));
    }

    public function test_a_digest_changes_with_a_run_warning(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'manifest-warning@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $card = $this->card($em, $project, 'Stuck', 'next');
        $em->clear();

        $client->loginUser($owner);
        $before = $this->digestOf($this->manifest($client, $project), $card);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->warn($em, $em->find(Project::class, $project->id) ?? throw new \LogicException('No project.'), $card);
        $em->clear();
        $after = $this->manifest($client, $project);

        self::assertNotSame($before, $this->digestOf($after, $card));
        self::assertSame($this->page($client, $project)['cards'], array_column($after['cards'], 1, 0));
    }

    public function test_a_digest_changes_with_a_badge_and_stays_the_page_one(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'manifest-badge@example.com');
        $project = $this->project($em, $owner);
        $card = $this->card($em, $project, 'Failing', 'next');
        $em->clear();

        $client->loginUser($owner);
        $before = $this->digestOf($this->manifest($client, $project), $card);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->linkReadPullRequest($em, $em->find(Card::class, $card->id) ?? throw new \LogicException('No card.'), new PullRequestSnapshot(checks: PullRequestChecks::Failed));
        $linked = $this->digestOf($this->manifest($client, $project), $card);
        $em->clear();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $paused = $em->find(Card::class, $card->id) ?? throw new \LogicException('No card.');
        $em->persist(new CardPause($paused, $paused->project, 'on-hold', 'hold', CardPauseKind::Rule, new \DateTimeImmutable()));
        $em->flush();
        $em->clear();
        $after = $this->manifest($client, $project);

        self::assertCount(3, array_unique([$before, $linked, $this->digestOf($after, $card)]));
        self::assertSame($this->page($client, $project)['cards'], array_column($after['cards'], 1, 0));
    }

    public function test_the_structure_digest_is_the_page_one_and_changes_with_a_column_label(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'manifest-structure@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $this->card($em, $project, 'Card');
        $nextId = $this->column($project, 'next')->id;
        $em->clear();

        $client->loginUser($owner);
        $before = $this->manifest($client, $project)['structure'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $before);
        self::assertSame($before, $this->page($client, $project)['structure']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $next = $em->find(BoardColumn::class, $nextId) ?? throw new \LogicException('No column.');
        $next->label = 'Up next';
        $em->flush();
        $em->clear();
        $after = $this->manifest($client, $project)['structure'];

        self::assertNotSame($before, $after);
        self::assertSame($after, $this->page($client, $project)['structure']);
    }

    public function test_the_structure_digest_changes_when_a_lane_is_turned_on(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'manifest-lane-on@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $epic = $this->closedEpic($em, $this->card($em, $project, 'Epic', 'next'));
        $em->clear();

        $client->loginUser($owner);
        $before = $this->manifest($client, $project);
        self::assertSame([(string) $epic->id], array_column($before['cards'], 0));
        $this->updateCard($epic, static function (Card $card): void {
            $card->laneEnabled = true;
        });
        $after = $this->manifest($client, $project);

        self::assertNotSame($before['structure'], $after['structure']);
        self::assertSame([], $this->faces($after));
        self::assertSame([(string) $epic->id], array_column($after['cards'], 0));
        self::assertSame($after['structure'], $this->page($client, $project)['structure']);
    }

    public function test_the_structure_digest_changes_when_two_lane_epics_swap_places(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'manifest-lane-swap@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $first = $this->typed($em, $this->card($em, $project, 'First epic', 'next', 0), CardType::Epic);
        $second = $this->typed($em, $this->card($em, $project, 'Second epic', 'next', 1), CardType::Epic);
        $em->clear();

        $client->loginUser($owner);
        $before = $this->manifest($client, $project);
        $this->updateCard($first, static function (Card $card): void {
            $card->position = 1;
        });
        $this->updateCard($second, static function (Card $card): void {
            $card->position = 0;
        });
        $after = $this->manifest($client, $project);

        self::assertNotSame($before['structure'], $after['structure']);
        self::assertSame($after['structure'], $this->page($client, $project)['structure']);
    }

    public function test_the_structure_digest_ignores_the_body_of_a_lane_epic(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'manifest-lane-body@example.com');
        $project = $this->project($em, $owner);
        $this->addTriageColumn($project);
        $em->flush();
        $epic = $this->closedEpic($em, $this->card($em, $project, 'Epic', 'next'));
        $this->updateCard($epic, static function (Card $card): void {
            $card->laneEnabled = true;
        });

        $client->loginUser($owner);
        $before = $this->manifest($client, $project);
        $this->updateCard($epic, static function (Card $card): void {
            $card->body = 'A new body.';
        });
        $after = $this->manifest($client, $project);
        $page = $this->page($client, $project);

        self::assertSame($before['structure'], $after['structure']);
        self::assertSame($after['structure'], $page['structure']);
        self::assertSame($this->digestOf($before, $epic), $this->digestOf($after, $epic), 'the card face shows no body');
        self::assertSame($page['rowDigests'][(string) $epic->id], $this->digestOf($after, $epic));
    }

    public function test_the_manifest_reads_the_pull_requests_documents_and_runs_in_one_query_whatever_the_card_count(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'manifest-queries@example.com');
        $projects = [];
        foreach (['three-cards' => 3, 'twelve-cards' => 12] as $name => $size) {
            $project = $this->project($em, $owner, $name);
            $this->addTriageColumn($project);
            $em->flush();
            for ($index = 0; $index < $size; ++$index) {
                $this->linkPullRequest($em, $this->card($em, $project, 'Card '.$index, 'triage', $index));
            }
            $projects[$name] = $project;
        }
        $em->clear();

        $client->loginUser($owner);
        // The first request shares the kernel the fixtures used, so its profile holds their queries too.
        $client->request(Request::METHOD_GET, $this->manifestUrl($projects['three-cards']));

        $reads = [];
        foreach ($projects as $name => $project) {
            $client->enableProfiler();
            $client->request(Request::METHOD_GET, $this->manifestUrl($project));
            self::assertResponseIsSuccessful();

            $profile = $client->getProfile();
            self::assertInstanceOf(Profile::class, $profile);
            $collector = $profile->getCollector('db');
            self::assertInstanceOf(DoctrineDataCollector::class, $collector);

            $count = ['selects' => 0, 'cards' => 0, 'links' => 0, 'documents' => 0, 'runs' => 0];
            foreach ($collector->getQueries() as $queries) {
                foreach ($queries as $query) {
                    $sql = (string) $query['sql'];
                    if (!str_starts_with($sql, 'SELECT')) {
                        continue;
                    }
                    ++$count['selects'];
                    $count['cards'] += (int) str_contains($sql, 'FROM board_cards');
                    $count['links'] += (int) str_contains($sql, 'FROM board_card_pull_requests');
                    $count['documents'] += (int) str_contains($sql, 'FROM board_card_documents');
                    $count['runs'] += (int) str_contains($sql, 'FROM bridge_worker_runs');
                }
            }
            $reads[$name] = $count;
        }

        self::assertGreaterThan(0, $reads['twelve-cards']['cards']);
        self::assertSame(0, $reads['twelve-cards']['links']);
        self::assertSame(1, $reads['twelve-cards']['documents']);
        self::assertSame(1, $reads['twelve-cards']['runs']);
        self::assertSame($reads['three-cards']['selects'], $reads['twelve-cards']['selects']);
    }

    private function manifestUrl(Project $project): string
    {
        return '/projects/'.$project->id.'/board/manifest';
    }

    /** @return array{cards: list<array{0: string, 1: string, 2: string, 3: ?string, 4?: string}>, structure: string, terminalTotals: array<string, int>, backlogCount: int} */
    private function manifest(KernelBrowser $client, Project $project): array
    {
        $client->request(Request::METHOD_GET, $this->manifestUrl($project));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        /** @var array{cards: list<array{0: string, 1: string, 2: string, 3: ?string, 4?: string}>, structure: string, terminalTotals: array<string, int>, backlogCount: int} $manifest */
        $manifest = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['cards', 'structure', 'terminalTotals', 'backlogCount'], array_keys($manifest));

        return $manifest;
    }

    /**
     * The digest of each card face on the board page, the lane of each face, the
     * ids of the list rows in order with their digests from the list view, the
     * structure digest, and the total of each history link.
     *
     * @return array{cards: array<string, string>, lanes: array<string, ?string>, laneDigests: array<string, string>, rows: list<string>, rowDigests: array<string, string>, structure: string, historyTotals: array<string, int>}
     */
    private function page(KernelBrowser $client, Project $project): array
    {
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');
        self::assertResponseIsSuccessful();

        $cards = [];
        $lanes = [];
        foreach ($crawler->filter('article[id^="board-card-"]') as $face) {
            self::assertInstanceOf(\DOMElement::class, $face);
            $cards[$face->getAttribute('data-card-id')] = $face->getAttribute('data-card-digest');
            $cell = $face->parentNode;
            self::assertInstanceOf(\DOMElement::class, $cell);
            $lanes[$face->getAttribute('data-card-id')] = $cell->hasAttribute('data-lane') ? $cell->getAttribute('data-lane') : null;
        }

        $historyTotals = [];
        foreach ($crawler->filter('[id^="board-history-"]') as $link) {
            self::assertInstanceOf(\DOMElement::class, $link);
            $historyTotals[substr($link->getAttribute('id'), \strlen('board-history-'))] = (int) $link->getAttribute('data-history-total');
        }

        $rowDigests = [];
        foreach ($client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board/list')->filter('[id^="board-row-"]') as $row) {
            self::assertInstanceOf(\DOMElement::class, $row);
            $rowDigests[$row->getAttribute('data-card-id')] = $row->getAttribute('data-card-digest');
        }

        $laneDigests = [];
        foreach ($crawler->filter('section[id^="board-lane-"] .lp-board-lane__head') as $head) {
            self::assertInstanceOf(\DOMElement::class, $head);
            self::assertInstanceOf(\DOMElement::class, $head->parentNode);
            $laneDigests[substr($head->parentNode->getAttribute('id'), \strlen('board-lane-'))] = $head->getAttribute('data-lane-digest');
        }

        return [
            'cards' => $cards,
            'laneDigests' => $laneDigests,
            'lanes' => $lanes,
            'rows' => array_keys($rowDigests),
            'rowDigests' => $rowDigests,
            'structure' => (string) $crawler->filter('#board')->attr('data-board-structure-digest'),
            'historyTotals' => $historyTotals,
        ];
    }

    /**
     * @param array{cards: list<array{0: string, 1: string, 2: string, 3: ?string, 4?: string}>, structure: string, terminalTotals: array<string, int>, backlogCount: int} $manifest
     *
     * @return list<array{0: string, 1: string, 2: string, 3: ?string, 4?: string}>
     */
    private function faces(array $manifest): array
    {
        return array_values(array_filter($manifest['cards'], static fn (array $entry): bool => !isset($entry[4])));
    }

    /** @param array{cards: list<array{0: string, 1: string, 2: string, 3: ?string, 4?: string}>, structure: string, terminalTotals: array<string, int>, backlogCount: int} $manifest */
    private function digestOf(array $manifest, Card $card): string
    {
        $digests = array_column($manifest['cards'], 1, 0);
        self::assertArrayHasKey((string) $card->id, $digests);

        return $digests[(string) $card->id];
    }

    /** An epic with its lane off, so the board draws it as a card. */
    private function closedEpic(EntityManagerInterface $em, Card $card): Card
    {
        $card->laneEnabled = false;

        return $this->typed($em, $card, CardType::Epic);
    }

    /** @param \Closure(Card): void $change */
    private function updateCard(Card $card, \Closure $change): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $change($em->find(Card::class, $card->id) ?? throw new \LogicException('No card.'));
        $em->flush();
        $em->clear();
    }

    private function linkPullRequest(EntityManagerInterface $em, Card $card): void
    {
        $card->replacePullRequests(new CardPullRequest(
            card: $card,
            url: 'https://github.com/loupe/loupe/pull/1',
            forge: Forge::GitHub,
            repository: 'loupe/loupe',
            number: 1,
        ));
        $em->flush();
    }

    private function warn(EntityManagerInterface $em, Project $project, Card $card): void
    {
        $run = new WorkerRun(
            project: $project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('Card has no id.'),
            cardNumber: $card->number,
            workKind: 'implement',
            state: WorkerRunState::GaveUp,
            runKey: Uuid::v7(),
            endedAt: new \DateTimeImmutable(),
            exitCode: 0,
            hasResult: true,
            output: 'Tests still fail.',
            receivedAt: new \DateTimeImmutable(),
        );
        $em->persist($run);
        $em->persist(new WorkerRunStateChange($run, WorkerRunState::GaveUp, new \DateTimeImmutable(), new \DateTimeImmutable()));
        $em->flush();
    }
}
