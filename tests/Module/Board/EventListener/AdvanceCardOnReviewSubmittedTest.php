<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Board\Command\CardLinkInput;
use App\Module\Board\Command\CreateCardCommand;
use App\Module\Board\Command\CreateCardHandler;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLinkKind;
use App\Module\Board\Entity\CardType;
use App\Module\Board\EventListener\AdvanceCardOnReviewSubmitted;
use App\Module\Project\Entity\Project;
use App\Module\Review\Command\CreateDocumentCommand;
use App\Module\Review\Command\CreateDocumentHandler;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Verdict;
use App\Tests\Module\Board\Mcp\BoardToolScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(AdvanceCardOnReviewSubmitted::class)]
final class AdvanceCardOnReviewSubmittedTest extends KernelTestCase
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
        $this->project = $this->makeProject('advance-on-review');
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
    public function test_an_approval_moves_a_card_with_no_blocker(array $tags, string $from, string $to): void
    {
        $document = $this->document($tags);
        $card = $this->card($from, $document);

        $this->approve($document);

        self::assertSame($to, $this->slugOf($card));
    }

    /** @param list<string> $tags */
    #[DataProvider('stages')]
    public function test_an_approval_keeps_a_card_with_an_open_blocker_in_its_column(array $tags, string $from, string $to): void
    {
        $blocker = $this->card('in-progress');
        $document = $this->document($tags);
        $card = $this->card($from, $document, [$blocker]);

        $this->approve($document);

        self::assertNotSame($to, $this->slugOf($card));
        self::assertSame($from, $this->slugOf($card));
        self::assertSame(DocumentStatus::Approved->value, $this->statusOf($document));
    }

    /** @param list<string> $tags */
    #[DataProvider('stages')]
    public function test_an_approval_moves_a_card_whose_blockers_are_all_finished(array $tags, string $from, string $to): void
    {
        $blocker = $this->card('done');
        $document = $this->document($tags);
        $card = $this->card($from, $document, [$blocker]);

        $this->approve($document);

        self::assertSame($to, $this->slugOf($card));
    }

    public function test_a_card_a_held_card_blocks_does_not_hold_it(): void
    {
        $document = $this->document(['product']);
        $card = $this->card('product-design', $document);
        $this->card('backlog', blockedBy: [$card]);

        $this->approve($document);

        self::assertSame('tech-design', $this->slugOf($card));
    }

    /** @param list<string> $tags */
    private function document(array $tags): Document
    {
        $handler = self::getContainer()->get(CreateDocumentHandler::class);
        self::assertInstanceOf(CreateDocumentHandler::class, $handler);

        return $handler(new CreateDocumentCommand($this->project, 'A design', '# A design', tagNames: $tags));
    }

    /** @param list<Card> $blockedBy */
    private function card(string $column, ?Document $document = null, array $blockedBy = []): Card
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
            relatedCards: array_map(static fn (Card $other): CardLinkInput => new CardLinkInput((string) $other->id, CardLinkKind::BlockedBy), $blockedBy),
        ));
    }

    private function approve(Document $document): void
    {
        $handler = self::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $handler);

        $reviewer = $this->project->owner;
        self::assertInstanceOf(User::class, $reviewer);
        $handler(new SubmitReviewCommand($reviewer, $document, Verdict::Approved->value, 1, 'Approved.'));
    }

    private function slugOf(Card $card): string
    {
        return (string) $this->em->getConnection()->fetchOne(
            'SELECT k.slug FROM board_cards c JOIN board_columns k ON k.id = c.column_id WHERE c.id = ?',
            [(string) $card->id],
        );
    }

    private function statusOf(Document $document): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT status FROM documents WHERE id = ?', [(string) $document->id]);
    }
}
