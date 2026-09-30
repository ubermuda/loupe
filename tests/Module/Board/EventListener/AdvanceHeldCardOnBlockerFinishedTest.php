<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Board\BoardEventType;
use App\Module\Board\Command\CardLinkInput;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Command\DeleteBoardColumnCommand;
use App\Module\Board\Command\DeleteBoardColumnHandler;
use App\Module\Board\Command\DeleteCardCommand;
use App\Module\Board\Command\DeleteCardHandler;
use App\Module\Board\Command\UpdateCardCommand;
use App\Module\Board\Command\UpdateCardHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\EventListener\AdvanceHeldCardOnBlockerFinished;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Verdict;
use App\Outbox\Repository\OutboxEventRepository;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Ubermuda\FeatureFlagsBundle\Reader\DoctrineFeatureFlagReader;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

#[CoversClass(AdvanceHeldCardOnBlockerFinished::class)]
final class AdvanceHeldCardOnBlockerFinishedTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $this->enableBoard();
        $this->project = $this->makeProject('advance-held');
        foreach (['product-design', 'tech-design', 'implementation'] as $position => $slug) {
            $this->em->persist(new BoardColumn(project: $this->project, label: $slug, slug: $slug, position: 4 + $position));
        }
        $this->em->flush();
    }

    /** @return iterable<string, array{list<string>, string, string}> */
    public static function stages(): iterable
    {
        yield 'product design' => [['product'], 'product-design', 'tech-design'];
        yield 'tech design' => [['design', 'decisions'], 'tech-design', 'implementation'];
    }

    /** @param list<string> $tags */
    #[DataProvider('stages')]
    public function test_the_last_blocker_to_finish_advances_the_held_card(array $tags, string $from, string $to): void
    {
        $first = $this->card('backlog');
        $second = $this->card('in-progress');
        $held = $this->heldCard($tags, $from, [$first, $second]);

        $this->move($first, 'done');
        self::assertSame($from, $this->slugOf($held));

        $this->move($second, 'done');
        self::assertSame($to, $this->slugOf($held));
        self::assertEquals(['type' => 'unblocked', 'blocker' => $second->number], $this->lastCause($held));
    }

    public function test_a_blocker_that_moves_between_open_columns_releases_nothing(): void
    {
        $blocker = $this->card('backlog');
        $held = $this->heldCard(['product'], 'product-design', [$blocker]);

        $this->move($blocker, 'in-progress');

        self::assertSame('product-design', $this->slugOf($held));
    }

    public function test_a_card_a_person_moved_out_of_the_stage_column_stays_where_it_is(): void
    {
        $blocker = $this->card('backlog');
        $held = $this->heldCard(['product'], 'product-design', [$blocker]);
        $this->move($held, 'backlog');

        $this->move($blocker, 'done');

        self::assertSame('backlog', $this->slugOf($held));
    }

    public function test_a_card_with_no_approved_stage_document_stays_where_it_is(): void
    {
        $blocker = $this->card('backlog');
        $card = $this->card('product-design', $this->document(['product']), [$blocker]);

        $this->move($blocker, 'done');

        self::assertSame('product-design', $this->slugOf($card));
    }

    public function test_a_blocking_link_removed_from_the_held_card_advances_it(): void
    {
        $blocker = $this->card('backlog');
        $held = $this->heldCard(['product'], 'product-design', [$blocker]);

        $this->relink($held, []);

        self::assertSame('tech-design', $this->slugOf($held));
        self::assertEquals(['type' => 'unblocked'], $this->lastCause($held));
    }

    public function test_a_blocking_link_removed_from_the_blocker_advances_the_held_card(): void
    {
        $blocker = $this->card('backlog');
        $held = $this->heldCard(['product'], 'product-design', [$blocker]);

        $this->relink($blocker, []);

        self::assertSame('tech-design', $this->slugOf($held));
    }

    public function test_a_blocking_link_turned_to_relates_to_advances_the_held_card(): void
    {
        $blocker = $this->card('backlog');
        $held = $this->heldCard(['product'], 'product-design', [$blocker]);

        $this->relink($held, [new CardLinkInput((string) $blocker->id, CardLinkKind::RelatesTo)]);

        self::assertSame('tech-design', $this->slugOf($held));
    }

    public function test_a_removed_link_leaves_a_card_with_another_open_blocker_where_it_is(): void
    {
        $first = $this->card('backlog');
        $second = $this->card('backlog');
        $held = $this->heldCard(['product'], 'product-design', [$first, $second]);

        $this->relink($first, []);

        self::assertSame('product-design', $this->slugOf($held));
    }

    public function test_a_deleted_blocker_advances_the_held_card(): void
    {
        $blocker = $this->card('backlog');
        $held = $this->heldCard(['product'], 'product-design', [$blocker]);

        $delete = self::getContainer()->get(DeleteCardHandler::class);
        self::assertInstanceOf(DeleteCardHandler::class, $delete);
        $delete(new DeleteCardCommand($this->reload($blocker), CardReporter::Human));
        $this->em->clear();

        self::assertSame('tech-design', $this->slugOf($held));
    }

    public function test_a_column_made_terminal_finishes_its_blockers_and_advances_the_held_card(): void
    {
        $first = $this->card('in-progress');
        $second = $this->card('in-progress');
        $held = $this->heldCard(['product'], 'product-design', [$first, $second]);

        $this->configureColumn($this->reloadProject(), 'in-progress', terminal: true);
        $this->em->clear();

        self::assertSame('tech-design', $this->slugOf($held));
        self::assertSame(1, $this->systemMovesOf($held));
        self::assertEquals(['type' => 'unblocked'], $this->lastCause($held));
    }

    public function test_a_column_made_terminal_leaves_a_card_with_another_open_blocker_where_it_is(): void
    {
        $finishing = $this->card('in-progress');
        $open = $this->card('backlog');
        $held = $this->heldCard(['product'], 'product-design', [$finishing, $open]);

        $this->configureColumn($this->reloadProject(), 'in-progress', terminal: true);
        $this->em->clear();

        self::assertSame('product-design', $this->slugOf($held));
    }

    public function test_a_deleted_column_whose_blockers_go_to_a_terminal_column_advances_the_held_card(): void
    {
        $blocker = $this->card('next');
        $held = $this->heldCard(['product'], 'product-design', [$blocker]);

        $delete = self::getContainer()->get(DeleteBoardColumnHandler::class);
        self::assertInstanceOf(DeleteBoardColumnHandler::class, $delete);
        $project = $this->reloadProject();
        $delete(new DeleteBoardColumnCommand($this->column($project, 'next'), CardReporter::Human, $this->column($project, 'done')));
        $this->em->clear();

        self::assertSame('done', $this->slugOf($blocker));
        self::assertSame('tech-design', $this->slugOf($held));
    }

    public function test_a_deleted_column_whose_blockers_go_to_an_open_column_releases_nothing(): void
    {
        $blocker = $this->card('next');
        $held = $this->heldCard(['product'], 'product-design', [$blocker]);

        $delete = self::getContainer()->get(DeleteBoardColumnHandler::class);
        self::assertInstanceOf(DeleteBoardColumnHandler::class, $delete);
        $project = $this->reloadProject();
        $delete(new DeleteBoardColumnCommand($this->column($project, 'next'), CardReporter::Human, $this->column($project, 'in-progress')));
        $this->em->clear();

        self::assertSame('product-design', $this->slugOf($held));
    }

    public function test_nothing_moves_while_the_board_is_switched_off(): void
    {
        $blocker = $this->card('backlog');
        $held = $this->heldCard(['product'], 'product-design', [$blocker]);
        $this->disableBoard();

        $this->move($blocker, 'done');

        self::assertSame('product-design', $this->slugOf($held));
    }

    /**
     * A card an approval kept in its stage column.
     *
     * @param list<string> $tags
     * @param list<Card>   $blockedBy
     */
    private function heldCard(array $tags, string $column, array $blockedBy): Card
    {
        $document = $this->document($tags);
        $card = $this->card($column, $document, $blockedBy);

        $handler = self::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $handler);
        $reviewer = $this->reloadProject()->owner;
        self::assertInstanceOf(User::class, $reviewer);
        $fresh = $this->em->find(Document::class, $document->id) ?? throw new \LogicException('The document must exist.');
        $handler(new SubmitReviewCommand($reviewer, $fresh, Verdict::Approved->value, 1, 'Approved.'));
        $this->em->clear();
        self::assertSame($column, $this->slugOf($card));

        return $card;
    }

    /** @param list<string> $tags */
    private function document(array $tags): Document
    {
        $handler = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $handler);

        return $handler(new CreateDocumentCommand($this->reloadProject(), 'A design', '# A design', tagNames: $tags));
    }

    /** @param list<Card> $blockedBy */
    private function card(string $column, ?Document $document = null, array $blockedBy = []): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        $card = $handler(new CreateCardCommand(
            project: $this->reloadProject(),
            title: 'A card',
            body: '',
            type: CardType::Feature,
            column: $this->column($this->project, $column),
            documentIds: null === $document ? [] : [(string) $document->id],
            relatedCards: array_map(static fn (Card $other): CardLinkInput => new CardLinkInput((string) $other->id, CardLinkKind::BlockedBy), $blockedBy),
        ));
        $this->em->clear();

        return $card;
    }

    private function move(Card $card, string $column): void
    {
        $update = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $update);
        $fresh = $this->reload($card);
        $update(new UpdateCardCommand($fresh, CardReporter::Human, column: $this->column($fresh->project, $column)));
        $this->em->clear();
    }

    /** @param list<CardLinkInput> $links */
    private function relink(Card $card, array $links): void
    {
        $update = self::getContainer()->get(UpdateCardHandler::class);
        self::assertInstanceOf(UpdateCardHandler::class, $update);
        $update(new UpdateCardCommand($this->reload($card), CardReporter::Human, relatedCards: $links));
        $this->em->clear();
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

    private function reloadProject(): Project
    {
        return $this->em->find(Project::class, $this->project->id) ?? throw new \LogicException('The project must exist.');
    }

    private function systemMovesOf(Card $card): int
    {
        $outbox = self::getContainer()->get(OutboxEventRepository::class);
        self::assertInstanceOf(OutboxEventRepository::class, $outbox);

        $moves = 0;
        foreach ($outbox->findBy(['project' => $this->project->id, 'type' => BoardEventType::CARD_MOVED]) as $row) {
            $payload = json_decode($row->payload, true, 512, \JSON_THROW_ON_ERROR);
            if (\is_array($payload) && $payload['subject'] === ['type' => 'card', 'id' => (string) $card->id] && 'system' === $payload['actor']) {
                ++$moves;
            }
        }

        return $moves;
    }

    /** @return array<string, mixed>|null the cause of the newest move in the card's history */
    private function lastCause(Card $card): ?array
    {
        $detail = $this->em->getConnection()->fetchOne(
            "SELECT detail FROM board_card_events WHERE card_id = :card AND kind = 'moved' ORDER BY occurred_at DESC, id DESC LIMIT 1",
            ['card' => (string) $card->id],
        );
        self::assertIsString($detail);

        return json_decode($detail, true, flags: \JSON_THROW_ON_ERROR)['cause'];
    }

    private function slugOf(Card $card): string
    {
        return (string) $this->em->getConnection()->fetchOne(
            'SELECT k.slug FROM board_cards c JOIN board_columns k ON k.id = c.column_id WHERE c.id = ?',
            [(string) $card->id],
        );
    }
}
