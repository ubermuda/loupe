<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Controller;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Entity\Forge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Profiler\Profile;
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
        $this->disableBoard();
        $em->clear();

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, $this->manifestUrl($project));

        self::assertResponseStatusCodeSame(404);
    }

    public function test_an_empty_board_has_an_empty_card_list(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'manifest-empty@example.com');
        $project = $this->project($em, $owner);
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
        $backlogSecond = $this->card($em, $project, 'Backlog second', 'backlog', 1);
        $backlogFirst = $this->card($em, $project, 'Backlog first', 'backlog', 0);
        $done = $this->card($em, $project, 'Finished', 'done');
        $epic = $this->closedEpic($em, $this->card($em, $project, 'Closed epic', 'next', 0));
        $child = $this->childOf($em, $epic, $this->card($em, $project, 'Child', 'next', 1));
        $doneChild = $this->childOf($em, $epic, $this->card($em, $project, 'Done child', 'done'));
        $this->linkPullRequest($em, $child);
        $this->warn($em, $project, $backlogFirst, 'backlog');
        $em->clear();

        $client->loginUser($owner);
        $manifest = $this->manifest($client, $project);
        $page = $this->page($client, $project);

        self::assertSame(
            [(string) $backlogFirst->id, (string) $backlogSecond->id, (string) $epic->id, (string) $child->id, (string) $doneChild->id, (string) $done->id],
            array_column($manifest['cards'], 0),
        );
        self::assertSame($page['rows'], array_column($manifest['cards'], 0));
        self::assertSame($page['cards'], array_column($manifest['cards'], 1, 0));
    }

    public function test_a_lane_epic_is_left_out_because_the_page_draws_it_as_a_lane_head(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'manifest-lane@example.com');
        $project = $this->project($em, $owner);
        $epic = $this->typed($em, $this->card($em, $project, 'Lane epic', 'next'), CardType::Epic);
        $child = $this->childOf($em, $epic, $this->card($em, $project, 'In the lane', 'backlog', 0));
        $other = $this->card($em, $project, 'Outside', 'backlog', 1);
        $em->clear();

        $client->loginUser($owner);
        $manifest = $this->manifest($client, $project);
        $page = $this->page($client, $project);

        self::assertContains((string) $epic->id, $page['rows']);
        self::assertArrayNotHasKey((string) $epic->id, $page['cards']);
        self::assertSame([(string) $child->id, (string) $other->id], array_column($manifest['cards'], 0));
        self::assertSame($page['cards'], array_column($manifest['cards'], 1, 0));
    }

    public function test_a_digest_changes_with_the_parent_title(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'manifest-parent@example.com');
        $project = $this->project($em, $owner);
        $parent = $this->card($em, $project, 'Parent', 'next');
        $child = $this->childOf($em, $parent, $this->card($em, $project, 'Child'));
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
        $card = $this->card($em, $project, 'Stuck', 'next');
        $em->clear();

        $client->loginUser($owner);
        $before = $this->digestOf($this->manifest($client, $project), $card);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->warn($em, $em->find(Project::class, $project->id) ?? throw new \LogicException('No project.'), $card, 'next');
        $em->clear();
        $after = $this->manifest($client, $project);

        self::assertNotSame($before, $this->digestOf($after, $card));
        self::assertSame($this->page($client, $project)['cards'], array_column($after['cards'], 1, 0));
    }

    public function test_the_structure_digest_is_the_page_one_and_changes_with_a_column_label(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'manifest-structure@example.com');
        $project = $this->project($em, $owner);
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
        self::assertSame([], $after['cards']);
        self::assertSame($after['structure'], $this->page($client, $project)['structure']);
    }

    public function test_the_manifest_reads_each_card_input_in_one_query_whatever_the_card_count(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->enableBoard();
        $owner = $this->user($em, 'manifest-queries@example.com');
        $projects = [];
        foreach (['three-cards' => 3, 'twelve-cards' => 12] as $name => $size) {
            $project = $this->project($em, $owner, $name);
            for ($index = 0; $index < $size; ++$index) {
                $this->linkPullRequest($em, $this->card($em, $project, 'Card '.$index, 'backlog', $index));
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

    /** @return array{cards: list<array{string, string}>, structure: string} */
    private function manifest(KernelBrowser $client, Project $project): array
    {
        $client->request(Request::METHOD_GET, $this->manifestUrl($project));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        /** @var array{cards: list<array{string, string}>, structure: string} $manifest */
        $manifest = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['cards', 'structure'], array_keys($manifest));

        return $manifest;
    }

    /**
     * The digest of each card face on the board page, the ids of the list rows
     * in order, and the structure digest.
     *
     * @return array{cards: array<string, string>, rows: list<string>, structure: string}
     */
    private function page(KernelBrowser $client, Project $project): array
    {
        $crawler = $client->request(Request::METHOD_GET, '/projects/'.$project->id.'/board');
        self::assertResponseIsSuccessful();

        $cards = [];
        foreach ($crawler->filter('article[id^="board-card-"]') as $face) {
            self::assertInstanceOf(\DOMElement::class, $face);
            $cards[$face->getAttribute('data-card-id')] = $face->getAttribute('data-card-digest');
        }

        return [
            'cards' => $cards,
            'rows' => $crawler->filter('[id^="board-row-"]')->each(static fn ($row): string => (string) $row->attr('data-card-id')),
            'structure' => (string) $crawler->filter('#board')->attr('data-board-structure-digest'),
        ];
    }

    /** @param array{cards: list<array{string, string}>, structure: string} $manifest */
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

    private function warn(EntityManagerInterface $em, Project $project, Card $card, string $columnSlug): void
    {
        $run = new WorkerRun(
            project: $project,
            bridgeId: Uuid::v7(),
            cardId: $card->id ?? throw new \LogicException('Card has no id.'),
            cardNumber: $card->number,
            ruleName: 'implement',
            state: WorkerRunState::GaveUp,
            runKey: Uuid::v7(),
            endedAt: new \DateTimeImmutable(),
            exitCode: 0,
            hasResult: true,
            output: 'Tests still fail.',
            receivedAt: new \DateTimeImmutable(),
            cardColumn: $columnSlug,
        );
        $em->persist($run);
        $em->persist(new WorkerRunStateChange($run, WorkerRunState::GaveUp, new \DateTimeImmutable(), new \DateTimeImmutable()));
        $em->flush();
    }
}
