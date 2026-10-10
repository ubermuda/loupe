<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\AgentReview\Entity\AgentReview;
use App\Module\AgentReview\Entity\AgentReviewConclusion;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardDocument;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Bridge\Service\CardHolds;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Entity\PullRequestState;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Tag;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\CardMoveGuard;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Repository\WorkflowBindingRepository;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261003055236;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261003055236.php';

final class BindEveryProjectMigrationTest extends KernelTestCase
{
    use ActionScenario;

    private const string NOW = '2026-10-03 12:00:00';

    /** @var array<string, Tag> */
    private array $tags = [];

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_a_board_with_a_column_for_each_lifecycle_slot_binds_lifecycle_as_the_handler_does(): void
    {
        $migrated = $this->lifecycleBoard('migrated');
        $handled = $this->lifecycleBoard('handled');
        $this->bindHandler()(new BindWorkflowTemplateCommand($handled, 'lifecycle', $this->slugColumns($handled)));

        $this->migrate();

        $binding = $this->service(WorkflowBindingRepository::class)->findOneByProjectId($this->id($migrated));
        self::assertNotNull($binding);
        $source = $this->service(ShippedTemplates::class)->source('lifecycle');
        self::assertSame(['lifecycle', $source['version'] ?? null], [$binding->templateKey, $binding->templateVersion]);
        self::assertSame($this->row($handled)['definition'], $this->row($migrated)['definition']);
        self::assertSame($this->links($handled), $this->links($migrated));
        self::assertSame(['implementation', 'in-review', 'next', 'product-design', 'tech-design'], array_keys($this->links($migrated)));
    }

    public function test_a_board_that_lacks_a_lifecycle_slot_binds_simple_with_no_link(): void
    {
        $project = $this->workflowProject('migration-simple');

        $this->migrate();

        self::assertSame('simple', $this->row($project)['template_key']);
        self::assertEquals($this->service(ShippedTemplates::class)->source('simple'), json_decode($this->row($project)['definition'], true));
        self::assertSame([], $this->links($project));
    }

    public function test_a_lifecycle_slot_on_a_terminal_column_binds_simple(): void
    {
        $project = $this->lifecycleBoard('migration-flagged');
        $this->column($project, 'in-review')->terminal = true;
        $this->em()->flush();

        $this->migrate();

        self::assertSame('simple', $this->row($project)['template_key']);
        self::assertSame([], $this->links($project));
    }

    public function test_a_bound_project_keeps_its_binding_and_its_cards_still_get_a_baseline(): void
    {
        $project = $this->workflowProject('migration-bound');
        $binding = $this->bindHandler()(new BindWorkflowTemplateCommand($project, 'simple', []));
        $card = $this->card($project, 'next');

        $this->migrate();

        self::assertSame([(string) $binding->id, 'simple'], [$this->row($project)['id'], $this->row($project)['template_key']]);
        self::assertSame([(string) $card->id], $this->baselinedCardIds($project));
    }

    public function test_every_card_gets_one_baseline_and_a_second_run_changes_nothing(): void
    {
        $project = $this->lifecycleBoard('migration-baseline');
        $cards = array_map(fn (string $slug): Card => $this->card($project, $slug), ['backlog', 'next', 'implementation', 'done']);

        $this->migrate();
        $this->migrate();

        $ids = array_map(static fn (Card $card): string => (string) $card->id, $cards);
        sort($ids);
        self::assertSame($ids, $this->baselinedCardIds($project));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM workflow_bindings WHERE project_id = ?', [$this->id($project)->toRfc4122()]));
        self::assertCount(5, $this->links($project));
    }

    public function test_the_first_evaluations_of_a_board_seeded_like_production_fire_nothing(): void
    {
        $project = $this->lifecycleBoard('migration-quiet');
        $cards = $this->productionCards($project);
        $simple = $this->workflowProject('migration-quiet-simple');
        $simpleMerged = $this->card($simple, 'in-progress');
        $this->pullRequest($simpleMerged, PullRequestState::Merged);
        $cards['simple merged'] = $simpleMerged;
        $cards['simple done'] = $this->card($simple, 'done');
        $held = $cards['held tech design'];
        $this->service(CardHolds::class)->hold($project, $this->cardId($held), null);
        $columns = array_map(static fn (Card $card): string => $card->column->slug, $cards);

        $this->migrate();
        foreach ([self::NOW, '2026-10-03 12:01:00'] as $at) {
            foreach ($cards as $card) {
                $this->service(Engine::class)->evaluate($this->cardId($card), new \DateTimeImmutable($at));
            }
        }

        $this->em()->clear();
        $states = $this->service(WorkflowRuleStateRepository::class);
        $truths = [];
        foreach ($cards as $name => $card) {
            $fresh = $this->em()->find(Card::class, $this->cardId($card)) ?? throw new \LogicException('The card is gone.');
            self::assertSame($columns[$name], $fresh->column->slug, $name);
            self::assertSame([], $this->liveRequests($fresh), $name);
            self::assertNull($this->service(CardPauseRepository::class)->findActiveForCard($fresh), $name);
            foreach ($states->findForCard($fresh->snapshot()->id) as $ruleId => $state) {
                self::assertSame([0, 0, null], [$state->fires, $state->attempts, $state->lastRefusal], $name.' '.$ruleId);
                $truths[$name.' '.$ruleId] = $state->truth;
            }
        }
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM work_requests WHERE project_id IN (?, ?)', [$this->id($project)->toRfc4122(), $this->id($simple)->toRfc4122()]));

        foreach ([
            'backlog open pull request pull-request-reopened',
            'backlog epic epic-close',
            'backlog child child-to-next',
            'product design product-design-session',
            'approved design tech-design-approved',
            'implementation no pull request implement',
            'failing checks fix-in-implementation',
            'epic with no children breakdown',
            'epic with an open child epic-draft',
            'mergeable merge-ready',
            'implementation merged merged',
            'done teardown',
            'simple merged merged',
            'simple done teardown',
        ] as $rule) {
            self::assertTrue($truths[$rule] ?? null, $rule);
        }
        self::assertSame([], $states->findForCard($held->snapshot()->id));
        self::assertSame([$this->cardId($held)->toRfc4122()], $this->baselinedCardIds($project));
    }

    public function test_after_the_cutover_a_card_is_managed_unless_someone_holds_it(): void
    {
        $project = $this->lifecycleBoard('migration-managed');
        $managed = $this->card($project, 'tech-design');
        $held = $this->card($project, 'tech-design');
        $this->service(CardHolds::class)->hold($project, $this->cardId($held), null);

        $this->migrate();

        $guard = $this->service(CardMoveGuard::class);
        $to = $this->column($project, 'in-review');
        self::assertFalse($guard->allows($managed->snapshot(), $to->ref(), Actor::Human, null));
        self::assertTrue($guard->allows($held->snapshot(), $to->ref(), Actor::Human, null));
    }

    /** @return array<string, Card> */
    private function productionCards(Project $project): array
    {
        $cards = [];
        $cards['backlog'] = $this->card($project, 'backlog');
        $cards['backlog open pull request'] = $this->card($project, 'backlog');
        $this->pullRequest($cards['backlog open pull request']);
        $cards['backlog epic'] = $this->card($project, 'backlog', 'epic');
        $this->pullRequest($cards['backlog epic']);
        $cards['backlog parent'] = $this->card($project, 'backlog', 'epic');
        $cards['backlog child'] = $this->card($project, 'backlog', parent: $cards['backlog parent']);
        $this->approvedDocument($cards['backlog child'], 'tech-design');
        $cards['next'] = $this->card($project, 'next');
        $cards['product design'] = $this->card($project, 'product-design');
        $cards['approved design'] = $this->card($project, 'tech-design');
        $this->approvedDocument($cards['approved design'], 'tech-design');
        $cards['held tech design'] = $this->card($project, 'tech-design');
        $this->approvedDocument($cards['held tech design'], 'tech-design');
        $cards['implementation no pull request'] = $this->card($project, 'implementation');
        $cards['failing checks'] = $this->card($project, 'implementation');
        $this->pullRequest($cards['failing checks'])->checks = PullRequestChecks::Failed;
        $cards['epic with no children'] = $this->card($project, 'implementation', 'epic');
        $cards['epic with an open child'] = $this->card($project, 'implementation', 'epic');
        $this->pullRequest($cards['epic with an open child']);
        $cards['open child'] = $this->card($project, 'implementation', parent: $cards['epic with an open child']);
        $this->pullRequest($cards['open child'], base: 'epic', head: 'child');
        $cards['mergeable'] = $this->card($project, 'in-review');
        $mergeable = $this->pullRequest($cards['mergeable']);
        $mergeable->checks = PullRequestChecks::Passed;
        $mergeable->mergeability = PullRequestMergeability::Mergeable;
        $mergeable->review = PullRequestReview::Approved;
        $mergeable->coveredSha = $mergeable->headSha;
        $passed = new AgentReview($project, $cards['mergeable'], $mergeable, $mergeable->headSha ?? '', 'Fine.', AgentReviewConclusion::Success, []);
        $passed->postedAt = new \DateTimeImmutable(self::NOW);
        $this->em()->persist($passed);
        $cards['implementation merged'] = $this->card($project, 'implementation');
        $this->pullRequest($cards['implementation merged'], PullRequestState::Merged);
        $cards['done'] = $this->card($project, 'done');
        $this->pullRequest($cards['done'], PullRequestState::Merged);
        $this->em()->flush();

        return $cards;
    }

    /** The seeded columns, plus one column for each Lifecycle slot, named by its slug. */
    private function lifecycleBoard(string $name): Project
    {
        $project = $this->workflowProject($name);
        $column = new BoardColumn(project: $project, label: 'implementation', slug: 'implementation', position: 20);
        $this->em()->persist($column);
        $this->seededColumns[$project->id.'/implementation'] = $column;
        $this->em()->flush();

        return $project;
    }

    private function card(Project $project, string $column, string $type = 'feature', ?Card $parent = null): Card
    {
        $card = new Card($project, $this->column($project, $column), 'Card', '', ++$this->cardNumber, $type);
        $card->parent = $parent;
        $this->em()->persist($card);
        $this->em()->flush();

        return $card;
    }

    private function approvedDocument(Card $card, string $tagName): void
    {
        $document = new Document($card->project->owner, $card->project, 'Design');
        $document->status = DocumentStatus::Approved;
        $key = $card->project->id.'/'.$tagName;
        if (!isset($this->tags[$key])) {
            $this->tags[$key] = new Tag($card->project, $tagName);
            $this->em()->persist($this->tags[$key]);
        }
        $document->tags->add($this->tags[$key]);
        $this->em()->persist($document);
        $this->em()->persist(new CardDocument($card, $document));
        $this->em()->flush();
    }

    /** @return array<string, \Symfony\Component\Uid\Uuid> */
    private function slugColumns(Project $project): array
    {
        $ids = [];
        foreach (['next', 'product-design', 'tech-design', 'implementation', 'in-review'] as $slug) {
            $ids[$slug] = $this->column($project, $slug)->id ?? throw new \LogicException('The column is not flushed.');
        }

        return $ids;
    }

    private function migrate(): void
    {
        $migration = new Version20261003055236($this->em()->getConnection(), new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->em()->getConnection()->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return array<string, string> */
    private function row(Project $project): array
    {
        $row = $this->em()->getConnection()->fetchAssociative('SELECT id, template_key, definition FROM workflow_bindings WHERE project_id = ?', [$this->id($project)->toRfc4122()]);
        self::assertIsArray($row);

        return array_map(static fn (mixed $value): string => \is_string($value) ? $value : throw new \LogicException('A binding column is text.'), $row);
    }

    /** @return array<string, string> each slot key, mapped to the slug of its column */
    private function links(Project $project): array
    {
        /** @var array<string, string> $links */
        $links = $this->em()->getConnection()->fetchAllKeyValue(
            'SELECT l.slot_key, c.slug FROM workflow_slot_links l JOIN board_columns c ON c.id = l.column_id WHERE l.project_id = ? ORDER BY l.slot_key',
            [$this->id($project)->toRfc4122()],
        );

        return $links;
    }

    /** @return list<string> */
    private function baselinedCardIds(Project $project): array
    {
        /** @var list<string> $ids */
        $ids = $this->em()->getConnection()->fetchFirstColumn('SELECT card_id FROM workflow_pending_baselines WHERE project_id = ? ORDER BY card_id', [$this->id($project)->toRfc4122()]);

        return $ids;
    }

    /** @return list<\App\Module\Bridge\Entity\WorkRequest> */
    private function liveRequests(Card $card): array
    {
        return $this->service(\App\Module\Bridge\Repository\WorkRequestRepository::class)->findLiveForCard($this->cardId($card));
    }

    private function id(Project $project): \Symfony\Component\Uid\Uuid
    {
        return $project->id ?? throw new \LogicException('The project is not flushed.');
    }

    private function cardId(Card $card): \Symfony\Component\Uid\Uuid
    {
        return $card->id ?? throw new \LogicException('The card is not flushed.');
    }
}
