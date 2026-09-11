<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardOrigin;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Mcp\CardPayload;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\TestCase;

final class CardPayloadTest extends TestCase
{
    public function test_the_list_shape_carries_the_eight_summary_keys(): void
    {
        $comments = $this->createMock(CardSiteReviewCommentRepository::class);
        $comments->expects($this->never())->method('findForCards');

        $rows = new CardPayload($comments)->forCardList([$this->card()]);

        self::assertCount(1, $rows);
        self::assertSame(
            ['cardId', 'number', 'title', 'type', 'priority', 'status', 'reporter', 'updatedAt'],
            array_keys($rows[0]),
        );
        self::assertSame('Drag ordering', $rows[0]['title']);
        self::assertSame('high', $rows[0]['priority']);
    }

    public function test_the_full_shape_does_query_the_site_review_comments(): void
    {
        $comments = $this->createMock(CardSiteReviewCommentRepository::class);
        // Without this the test above passes even on a payload that never ran
        // the query on either path.
        $comments->expects($this->once())->method('findForCards')->willReturn([]);

        $rows = new CardPayload($comments)->forCards([$this->card()]);

        self::assertArrayHasKey('body', $rows[0]);
        self::assertSame([], $rows[0]['siteReviewComments']);
    }

    private function card(): Card
    {
        $owner = new User(fullName: 'Riley', email: 'riley@example.com', password: 'hashed');

        return new Card(
            project: new Project($owner, 'board'),
            title: 'Drag ordering',
            body: 'Body',
            number: 7,
            type: CardType::Feature,
            priority: CardPriority::High,
            status: CardStatus::Backlog,
            origin: CardOrigin::Agent,
        );
    }
}
