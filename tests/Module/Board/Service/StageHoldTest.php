<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Service;

use App\Module\Board\Command\CardLinkInput;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Service\StageHold;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(StageHold::class)]
final class StageHoldTest extends KernelTestCase
{
    use BoardToolScenario;

    private EntityManagerInterface $em;
    private StageHold $hold;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $hold = self::getContainer()->get(StageHold::class);
        self::assertInstanceOf(StageHold::class, $hold);
        $this->hold = $hold;

        $this->enableBoard();
        $this->project = $this->makeProject('stage-hold');
        foreach (['product-design', 'tech-design', 'implementation'] as $position => $slug) {
            $this->em->persist(new BoardColumn(project: $this->project, label: $slug, slug: $slug, position: 4 + $position));
        }
        $this->em->flush();
    }

    public function test_open_blockers_list_the_blocking_cards_in_open_columns_by_number(): void
    {
        $second = $this->card('backlog');
        $first = $this->card('in-progress');
        $blocked = $this->card('product-design', blockedBy: [$second, $first]);

        self::assertSame([$second->number, $first->number], $this->numbers($this->hold->openBlockers($blocked)));
    }

    public function test_a_blocker_in_a_terminal_column_is_not_open(): void
    {
        $finished = $this->card('done');
        $open = $this->card('backlog');
        $blocked = $this->card('product-design', blockedBy: [$finished, $open]);

        self::assertSame([$open->number], $this->numbers($this->hold->openBlockers($blocked)));
    }

    public function test_a_relates_to_link_does_not_block(): void
    {
        $related = $this->card('backlog');
        $card = $this->card('product-design', relatesTo: [$related]);

        self::assertSame([], $this->hold->openBlockers($card));
    }

    public function test_a_card_the_card_blocks_is_not_its_blocker(): void
    {
        $card = $this->card('product-design');
        $this->card('backlog', blockedBy: [$card]);

        self::assertSame([], $this->hold->openBlockers($card));
    }

    public function test_an_approved_product_document_holds_a_card_in_product_design(): void
    {
        $card = $this->card('product-design', $this->document(['product'], DocumentStatus::Approved));

        self::assertSame(['from' => 'product-design', 'to' => 'tech-design'], $this->hold->heldStage($card));
    }

    public function test_an_approved_tech_design_holds_a_card_in_tech_design(): void
    {
        $card = $this->card('tech-design', $this->document(['design', 'decisions'], DocumentStatus::Approved));

        self::assertSame(['from' => 'tech-design', 'to' => 'implementation'], $this->hold->heldStage($card));
    }

    public function test_a_document_in_review_holds_nothing(): void
    {
        $card = $this->card('product-design', $this->document(['product'], DocumentStatus::InReview));

        self::assertNull($this->hold->heldStage($card));
    }

    public function test_a_document_tagged_for_both_stages_holds_nothing(): void
    {
        $card = $this->card('product-design', $this->document(['product', 'design', 'decisions'], DocumentStatus::Approved));

        self::assertNull($this->hold->heldStage($card));
    }

    public function test_a_document_of_another_stage_holds_nothing(): void
    {
        $card = $this->card('product-design', $this->document(['design', 'decisions'], DocumentStatus::Approved));

        self::assertNull($this->hold->heldStage($card));
    }

    public function test_an_untagged_approved_document_holds_nothing(): void
    {
        $card = $this->card('product-design', $this->document([], DocumentStatus::Approved));

        self::assertNull($this->hold->heldStage($card));
    }

    public function test_the_stage_reads_the_status_the_database_holds(): void
    {
        $document = $this->document(['product'], DocumentStatus::Approved);
        $card = $this->card('product-design', $document);
        $this->em->getConnection()->update('documents', ['status' => DocumentStatus::InReview->value], ['id' => (string) $document->id]);

        self::assertNull($this->hold->heldStage($card));
    }

    public function test_a_stage_column_made_terminal_holds_nothing(): void
    {
        $card = $this->card('product-design', $this->document(['product'], DocumentStatus::Approved));
        $this->em->getConnection()->update('board_columns', ['terminal' => 'true'], ['id' => (string) $this->column($this->project, 'product-design')->id]);

        self::assertNull($this->hold->heldStage($card));
    }

    public function test_an_archived_document_holds_nothing(): void
    {
        $document = $this->document(['product'], DocumentStatus::Approved);
        $card = $this->card('product-design', $document);
        $this->em->getConnection()->update('documents', ['archived_at' => '2026-09-30 12:00:00'], ['id' => (string) $document->id]);

        self::assertNull($this->hold->heldStage($card));
    }

    public function test_the_stage_reads_the_column_the_database_holds(): void
    {
        $card = $this->card('product-design', $this->document(['product'], DocumentStatus::Approved));
        $this->em->getConnection()->update('board_cards', ['column_id' => (string) $this->column($this->project, 'tech-design')->id], ['id' => (string) $card->id]);

        self::assertNull($this->hold->heldStage($card));
    }

    public function test_held_by_names_the_open_blockers_of_an_approved_card(): void
    {
        $blocker = $this->card('backlog');
        $card = $this->card('product-design', $this->document(['product'], DocumentStatus::Approved), [$blocker]);

        self::assertSame([$blocker->number], $this->numbers($this->hold->heldBy($card)));
    }

    public function test_held_by_is_empty_without_an_approved_stage_document(): void
    {
        $blocker = $this->card('backlog');
        $card = $this->card('product-design', $this->document(['product'], DocumentStatus::InReview), [$blocker]);

        self::assertSame([], $this->hold->heldBy($card));
    }

    /** @param list<string> $tags */
    private function document(array $tags, DocumentStatus $status): Document
    {
        $handler = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $handler);

        $document = $handler(new CreateDocumentCommand($this->project, 'A design', '# A design', tagNames: $tags));
        $document->status = $status;
        $this->em->flush();

        return $document;
    }

    /**
     * @param list<Card> $blockedBy
     * @param list<Card> $relatesTo
     */
    private function card(string $column, ?Document $document = null, array $blockedBy = [], array $relatesTo = []): Card
    {
        $handler = self::getContainer()->get(CreateCardHandler::class);
        self::assertInstanceOf(CreateCardHandler::class, $handler);

        return $handler(new CreateCardCommand(
            project: $this->project,
            title: 'A card',
            body: '',
            type: CardType::Feature,
            column: $this->column($this->project, $column),
            documentIds: null === $document ? [] : [(string) $document->id],
            relatedCards: [
                ...array_map(static fn (Card $other): CardLinkInput => new CardLinkInput((string) $other->id, CardLinkKind::BlockedBy), $blockedBy),
                ...array_map(static fn (Card $other): CardLinkInput => new CardLinkInput((string) $other->id, CardLinkKind::RelatesTo), $relatesTo),
            ],
        ));
    }

    /**
     * @param list<Card> $cards
     *
     * @return list<int>
     */
    private function numbers(array $cards): array
    {
        return array_map(static fn (Card $card): int => $card->number, $cards);
    }
}
