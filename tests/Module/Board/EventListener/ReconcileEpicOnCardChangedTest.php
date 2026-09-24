<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Board\BoardEventType;
use App\Module\Board\Command\CardLinkInput;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Project\Entity\Project;
use App\Outbox\Repository\OutboxEventRepository;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\FeatureFlagsBundle\Reader\DoctrineFeatureFlagReader;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class ReconcileEpicOnCardChangedTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private CreateCardHandler $createCard;
    private UpdateCardHandler $updateCard;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $create = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $create);
        $this->createCard = $create;

        $update = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $update);
        $this->updateCard = $update;

        $this->enableBoard();
    }

    public function test_the_last_child_to_finish_closes_its_epic(): void
    {
        $project = $this->lifecycleProject('epic-close');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $first = $this->card($project, parent: $epic);
        $second = $this->card($project, parent: $epic);
        $third = $this->card($project, parent: $epic);

        $this->update($first, 'done');
        $this->update($second, 'done');
        self::assertSame('implementation', $this->slugOf($epic));

        $this->update($third, 'done');

        self::assertSame('done', $this->slugOf($epic));
        self::assertNotNull($this->reload($epic)->completedAt);
        $last = $this->movedRows($project)[3];
        self::assertSame(['cardNumber' => $epic->number, 'fromStatus' => 'implementation', 'toStatus' => 'done', 'actor' => 'system'], $last);
    }

    public function test_the_epic_closes_in_the_first_terminal_column_in_board_order(): void
    {
        $project = $this->lifecycleProject('epic-close-first-terminal');
        $archive = new BoardColumn($this->reloadProject($project), 'Archive', 'archive', 10, terminal: true);
        $this->em->persist($archive);
        $this->em->flush();
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $child = $this->card($project, parent: $epic);

        $this->update($child, 'archive');

        self::assertSame('done', $this->slugOf($epic));
    }

    public function test_a_child_that_reopens_moves_its_done_epic_back_to_implementation(): void
    {
        $project = $this->lifecycleProject('epic-reopen');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $child = $this->card($project, parent: $epic);
        $this->update($child, 'done');
        self::assertSame('done', $this->slugOf($epic));

        $this->update($child, 'next');

        self::assertSame('implementation', $this->slugOf($epic));
        $last = array_last($this->movedRows($project));
        self::assertSame(['cardNumber' => $epic->number, 'fromStatus' => 'done', 'toStatus' => 'implementation', 'actor' => 'system'], $last);
    }

    public function test_an_open_card_that_joins_a_done_epic_reopens_it(): void
    {
        $project = $this->lifecycleProject('epic-join');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $this->update($this->card($project, parent: $epic), 'done');
        self::assertSame('done', $this->slugOf($epic));

        $this->update($this->card($project), parentCardId: (string) $epic->id);

        self::assertSame('implementation', $this->slugOf($epic));
    }

    public function test_the_epic_closes_when_its_only_open_child_leaves_it(): void
    {
        $project = $this->lifecycleProject('epic-leave');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $this->update($this->card($project, parent: $epic), 'done');
        $open = $this->card($project, parent: $epic);
        self::assertSame('implementation', $this->slugOf($epic));

        $this->update($open, parentCardId: '');

        self::assertSame('done', $this->slugOf($epic));
    }

    public function test_an_epic_left_with_no_children_does_not_move(): void
    {
        $project = $this->lifecycleProject('epic-empty');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $only = $this->card($project, parent: $epic);

        $this->update($only, parentCardId: '');

        self::assertSame(0, $this->countChildren($epic));
        self::assertSame('implementation', $this->slugOf($epic));
    }

    public function test_a_board_with_no_terminal_column_never_closes_an_epic(): void
    {
        $project = $this->lifecycleProject('epic-no-terminal');
        $done = $this->column($project, 'done');
        $done->terminal = false;
        $this->em->flush();
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $child = $this->card($project, parent: $epic);

        $this->update($child, 'done');

        self::assertSame('implementation', $this->slugOf($epic));
        self::assertCount(1, $this->movedRows($project));
    }

    public function test_a_board_with_no_implementation_column_leaves_a_done_epic_alone(): void
    {
        $project = $this->makeProject('epic-no-implementation');
        $epic = $this->card($project, 'in-progress', CardType::Epic);
        $child = $this->card($project, parent: $epic);
        $this->update($child, 'done');
        self::assertSame('done', $this->slugOf($epic));

        $this->update($child, 'next');

        self::assertSame('done', $this->slugOf($epic));
    }

    public function test_a_child_whose_last_blocker_finishes_starts_in_implementation(): void
    {
        $project = $this->lifecycleProject('epic-release');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $blocker = $this->card($project, parent: $epic);
        $blocked = $this->card($project, parent: $epic, blockedBy: [$blocker]);

        $this->update($blocker, 'done');

        self::assertSame('implementation', $this->slugOf($blocked));
        self::assertSame('implementation', $this->slugOf($epic));
        $rows = $this->movedRows($project);
        self::assertCount(2, $rows);
        self::assertSame(['cardNumber' => $blocked->number, 'fromStatus' => 'backlog', 'toStatus' => 'implementation', 'actor' => 'system'], $rows[1]);
    }

    public function test_a_blocked_card_with_no_parent_stays_in_the_backlog(): void
    {
        $project = $this->lifecycleProject('epic-release-no-parent');
        $blocker = $this->card($project);
        $blocked = $this->card($project, blockedBy: [$blocker]);

        $this->update($blocker, 'done');

        self::assertSame('backlog', $this->slugOf($blocked));
    }

    public function test_a_blocked_child_outside_the_default_column_stays_where_it_is(): void
    {
        $project = $this->lifecycleProject('epic-release-not-default');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $blocker = $this->card($project, parent: $epic);
        $blocked = $this->card($project, 'next', parent: $epic, blockedBy: [$blocker]);

        $this->update($blocker, 'done');

        self::assertSame('next', $this->slugOf($blocked));
    }

    public function test_a_blocked_child_waits_while_another_blocker_is_open(): void
    {
        $project = $this->lifecycleProject('epic-release-other-blocker');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $first = $this->card($project, parent: $epic);
        $second = $this->card($project, parent: $epic);
        $blocked = $this->card($project, parent: $epic, blockedBy: [$first, $second]);

        $this->update($first, 'done');
        self::assertSame('backlog', $this->slugOf($blocked));

        $this->update($second, 'done');
        self::assertSame('implementation', $this->slugOf($blocked));
    }

    public function test_a_card_that_blocks_nothing_releases_nothing(): void
    {
        $project = $this->lifecycleProject('epic-release-direction');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $blocked = $this->card($project, parent: $epic);
        // The link runs the other way: the moved card is the one that waits.
        $mover = $this->card($project, parent: $epic, blockedBy: [$blocked]);

        $this->update($mover, 'done');

        self::assertSame('backlog', $this->slugOf($blocked));
    }

    public function test_a_board_with_no_implementation_column_releases_nothing(): void
    {
        $project = $this->makeProject('epic-release-no-implementation');
        $epic = $this->card($project, 'in-progress', CardType::Epic);
        $blocker = $this->card($project, parent: $epic);
        $blocked = $this->card($project, parent: $epic, blockedBy: [$blocker]);

        $this->update($blocker, 'done');

        self::assertSame('backlog', $this->slugOf($blocked));
    }

    /**
     * The epic's own move is automatic, and it releases the cards the epic
     * blocks. Those land in an open column, so the chain ends there.
     */
    public function test_the_chain_of_automatic_moves_stops_after_one_level(): void
    {
        $project = $this->lifecycleProject('epic-chain');
        $first = $this->card($project, 'implementation', CardType::Epic);
        $second = $this->card($project, 'implementation', CardType::Epic);
        $child = $this->card($project, parent: $first);
        $waiting = $this->card($project, parent: $second, blockedBy: [$first]);

        $this->update($child, 'done');

        self::assertSame('done', $this->slugOf($first));
        self::assertSame('implementation', $this->slugOf($waiting));
        self::assertSame('implementation', $this->slugOf($second));
        self::assertSame([
            ['cardNumber' => $child->number, 'fromStatus' => 'backlog', 'toStatus' => 'done', 'actor' => 'agent'],
            ['cardNumber' => $first->number, 'fromStatus' => 'implementation', 'toStatus' => 'done', 'actor' => 'system'],
            ['cardNumber' => $waiting->number, 'fromStatus' => 'backlog', 'toStatus' => 'implementation', 'actor' => 'system'],
        ], $this->movedRows($project));
    }

    public function test_nothing_moves_while_the_board_is_switched_off(): void
    {
        $project = $this->lifecycleProject('epic-flag-off');
        $epic = $this->card($project, 'implementation', CardType::Epic);
        $child = $this->card($project, parent: $epic);
        $this->disableBoard();

        $this->update($child, 'done');

        self::assertSame('implementation', $this->slugOf($epic));
    }

    /** The four seeded columns and an open `implementation` column after them. */
    private function lifecycleProject(string $label): Project
    {
        $project = $this->makeProject($label);
        $this->em->persist(new BoardColumn($project, 'Implementation', 'implementation', 4));
        $this->em->flush();

        return $project;
    }

    /** @param list<Card> $blockedBy */
    private function card(
        Project $project,
        string $column = 'backlog',
        CardType $type = CardType::Feature,
        ?Card $parent = null,
        array $blockedBy = [],
    ): Card {
        $card = ($this->createCard)(new CreateCardCommand(
            $this->reloadProject($project),
            'Card',
            '',
            $type,
            column: $this->column($project, $column),
            relatedCards: array_map(static fn (Card $blocker): CardLinkInput => new CardLinkInput((string) $blocker->id, CardLinkKind::BlockedBy), $blockedBy),
            parentCardId: null === $parent ? null : (string) $parent->id,
        ));
        $this->em->clear();

        return $card;
    }

    private function update(Card $card, ?string $column = null, ?string $parentCardId = null): void
    {
        $fresh = $this->reload($card);
        ($this->updateCard)(new UpdateCardCommand(
            $fresh,
            CardReporter::Agent,
            column: null === $column ? null : $this->column($fresh->project, $column),
            parentCardId: $parentCardId,
        ));
        $this->em->clear();
    }

    private function slugOf(Card $card): string
    {
        return (string) $this->em->getConnection()->fetchOne(
            'SELECT k.slug FROM board_cards c JOIN board_columns k ON k.id = c.column_id WHERE c.id = ?',
            [(string) $card->id],
        );
    }

    private function countChildren(Card $card): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM board_cards WHERE parent_card_id = ?', [(string) $card->id]);
    }

    /** @return list<array{cardNumber: mixed, fromStatus: mixed, toStatus: mixed, actor: mixed}> */
    private function movedRows(Project $project): array
    {
        $outbox = self::getContainer()->get(OutboxEventRepository::class);
        self::assertInstanceOf(OutboxEventRepository::class, $outbox);

        $rows = [];
        foreach ($outbox->findBy(['project' => $project->id, 'type' => BoardEventType::CARD_MOVED], ['sequence' => 'ASC']) as $row) {
            $payload = json_decode($row->payload, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            $rows[] = [
                'cardNumber' => $payload['cardNumber'] ?? null,
                'fromStatus' => $payload['fromStatus'] ?? null,
                'toStatus' => $payload['toStatus'] ?? null,
                'actor' => $payload['actor'] ?? null,
            ];
        }

        return $rows;
    }

    private function disableBoard(): void
    {
        $flags = self::getContainer()->get(FeatureFlagRepository::class);
        self::assertInstanceOf(FeatureFlagRepository::class, $flags);
        $flags->findAllIndexed()[BoardInstallFlags::FLAG_BOARD_ENABLED]->value = false;
        $this->em->flush();
        // The reader keeps the flags it read for the rest of the request.
        $reader = self::getContainer()->get(DoctrineFeatureFlagReader::class);
        self::assertInstanceOf(DoctrineFeatureFlagReader::class, $reader);
        $reader->reset();
    }

    private function reload(Card $card): Card
    {
        return $this->em->find(Card::class, $card->id) ?? throw new \LogicException('The card must exist.');
    }

    private function reloadProject(Project $project): Project
    {
        return $this->em->find(Project::class, $project->id) ?? throw new \LogicException('The project must exist.');
    }
}
