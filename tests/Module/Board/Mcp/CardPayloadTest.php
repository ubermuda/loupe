<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Mcp\CardPayload;
use App\Module\Board\Repository\CardLinkRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\TestCase;

final class CardPayloadTest extends TestCase
{
    public function test_the_list_shape_carries_the_eight_summary_keys(): void
    {
        $comments = $this->createMock(CardSiteReviewCommentRepository::class);
        $comments->expects($this->never())->method('findForCards');
        $links = $this->createMock(CardLinkRepository::class);
        $links->expects($this->never())->method('findForCards');
        $cards = $this->createMock(CardRepository::class);
        $cards->expects($this->never())->method('findChildrenOfCards');

        $rows = new CardPayload($comments, $links, $cards)->forCardList([$this->card()]);

        self::assertCount(1, $rows);
        self::assertSame(
            ['cardId', 'number', 'title', 'type', 'status', 'reporter', 'parentCardId', 'updatedAt'],
            array_keys($rows[0]),
        );
        self::assertSame('Drag ordering', $rows[0]['title']);
    }

    public function test_the_full_shape_does_query_the_site_review_comments_and_the_card_links(): void
    {
        $comments = $this->createMock(CardSiteReviewCommentRepository::class);
        // Without these the test above passes even on a payload that never ran
        // the queries on either path.
        $comments->expects($this->once())->method('findForCards')->willReturn([]);
        $links = $this->createMock(CardLinkRepository::class);
        $links->expects($this->once())->method('findForCards')->willReturn([]);
        $feature = $this->card();
        $epic = $this->card(CardType::Epic);
        $otherEpic = $this->card(CardType::Epic);
        $cards = $this->createMock(CardRepository::class);
        // One call for the whole page, with the epics alone.
        $cards->expects($this->once())->method('findChildrenOfCards')->with([$epic, $otherEpic])->willReturn([]);

        $rows = new CardPayload($comments, $links, $cards)->forCards([$feature, $epic, $otherEpic]);

        self::assertArrayHasKey('body', $rows[0]);
        self::assertSame([], $rows[0]['siteReviewComments']);
        self::assertSame([], $rows[0]['relatedCards']);
        self::assertNull($rows[0]['progress']);
        self::assertSame(['done' => 0, 'total' => 0], $rows[1]['progress']);
    }

    public function test_a_page_with_no_epic_reads_no_children(): void
    {
        $cards = $this->createMock(CardRepository::class);
        $cards->expects($this->never())->method('findChildrenOfCards');

        $rows = new CardPayload($this->createStub(CardSiteReviewCommentRepository::class), $this->createStub(CardLinkRepository::class), $cards)->forCards([$this->card()]);

        self::assertSame([], $rows[0]['children']);
    }

    private function card(CardType $type = CardType::Feature): Card
    {
        $owner = new User(fullName: 'Riley', email: 'riley@example.com', password: 'hashed');

        $project = new Project($owner, 'board');

        return new Card(
            project: $project,
            column: new BoardColumn(project: $project, label: 'board.card.status.backlog', slug: 'backlog', position: 0, isDefault: true),
            title: 'Drag ordering',
            body: 'Body',
            number: 7,
            type: $type,
            origin: CardReporter::Agent,
        );
    }
}
