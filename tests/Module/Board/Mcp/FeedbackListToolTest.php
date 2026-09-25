<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\FeedbackListTool;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FeedbackListToolTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private FeedbackListTool $tool;
    private CardCreateTool $createTool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(FeedbackListTool::class);
        self::assertInstanceOf(FeedbackListTool::class, $tool);
        $this->tool = $tool;

        $createTool = self::getContainer()->get(CardCreateTool::class);
        self::assertInstanceOf(CardCreateTool::class, $createTool);
        $this->createTool = $createTool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->disableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('feedback-list-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The board is switched off on this instance.');
        ($this->tool)();
    }

    public function test_the_default_lists_the_pending_feedback_with_its_card(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('feedback-list');
        $this->actAsMcpTokenBoundTo($project);
        $card = $this->card(($this->createTool)('Fix the header', 'Body', 'site-review')['cardId']);

        $first = $this->feedback($project, 0, 'The logo is blurry', $card, context: 'card:preview');
        $first->addAnchor('header .logo', 'Logo', 'Acme', 'the ', ' mark');
        $first->strokes = [['space' => 'page', 'points' => [[0.1, 0.2], [0.3, 0.4]]]];
        $this->feedback($project, 1, 'Already fixed', $card, SiteReviewCommentStatus::Addressed);
        $unlinked = $this->feedback($project, 2, 'No card for this one', null);
        $this->em->flush();
        $this->em->clear();

        $result = ($this->tool)();

        self::assertSame([
            [
                'id' => (string) $first->id,
                'url' => 'https://app.example/page',
                'anchors' => [[
                    'selector' => 'header .logo',
                    'text' => 'Logo',
                    'quote' => 'Acme',
                    'quotePrefix' => 'the ',
                    'quoteSuffix' => ' mark',
                ]],
                'body' => 'The logo is blurry',
                'hasDrawing' => true,
                'status' => 'pending',
                'context' => 'card:preview',
                'createdAt' => $first->createdAt->format(\DATE_ATOM),
                'cardId' => (string) $card->id,
                'number' => $card->number,
                'title' => 'Fix the header',
            ],
            [
                'id' => (string) $unlinked->id,
                'url' => 'https://app.example/page',
                'anchors' => [],
                'body' => 'No card for this one',
                'hasDrawing' => false,
                'status' => 'pending',
                'context' => null,
                'createdAt' => $unlinked->createdAt->format(\DATE_ATOM),
                'cardId' => null,
                'number' => null,
                'title' => null,
            ],
        ], $result['feedback']);
    }

    public function test_a_status_filter_narrows_the_list(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('feedback-list-status');
        $this->actAsMcpTokenBoundTo($project);
        $card = $this->card(($this->createTool)('Card', 'Body', 'site-review')['cardId']);

        $this->feedback($project, 0, 'Pending one', $card);
        $this->feedback($project, 1, 'Addressed one', $card, SiteReviewCommentStatus::Addressed);
        $this->feedback($project, 2, 'Resolved one', $card, SiteReviewCommentStatus::Resolved);
        $this->em->flush();

        self::assertSame(['Addressed one'], array_column(($this->tool)('addressed')['feedback'], 'body'));
        self::assertSame(['Resolved one'], array_column(($this->tool)('resolved')['feedback'], 'body'));
        self::assertSame(['Pending one'], array_column(($this->tool)('pending')['feedback'], 'body'));
        self::assertSame(['Pending one', 'Addressed one', 'Resolved one'], array_column(($this->tool)('all')['feedback'], 'body'));
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('feedback-list-unknown'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Unknown status "open". Use pending, addressed, resolved or all.');
        ($this->tool)('open');
    }

    public function test_another_projects_feedback_is_not_listed(): void
    {
        $this->enableBoard();
        $theirs = $this->makeProject('feedback-list-theirs');
        $this->actAsMcpTokenBoundTo($theirs);
        $theirCard = $this->card(($this->createTool)('Theirs', 'Body', 'site-review')['cardId']);
        $this->feedback($theirs, 0, 'Not yours', $theirCard);

        $mine = $this->makeProject('feedback-list-mine');
        $this->actAsMcpTokenBoundTo($mine);
        $myCard = $this->card(($this->createTool)('Mine', 'Body', 'site-review')['cardId']);
        $this->feedback($mine, 0, 'Yours', $myCard);
        $this->em->flush();

        self::assertSame(['Yours'], array_column(($this->tool)()['feedback'], 'body'));
    }

    public function test_an_unbound_token_is_refused(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('feedback-list-unbound');
        $this->feedback($project, 0, 'Not reachable', null);
        $this->em->flush();
        $this->actAsUnboundMcpToken($project->owner);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('MCP token is not bound to a project. Mint a project token from the Connect page.');
        ($this->tool)();
    }

    private function card(string $cardId): Card
    {
        $card = $this->em->find(Card::class, $cardId);
        self::assertInstanceOf(Card::class, $card);

        return $card;
    }

    private function feedback(Project $project, int $position, string $body, ?Card $card, SiteReviewCommentStatus $status = SiteReviewCommentStatus::Pending, ?string $context = null): SiteReviewComment
    {
        $comment = new SiteReviewComment($project, $position, $body, 'https://app.example/page', $context);
        $comment->status = $status;
        $this->em->persist($comment);

        if (null !== $card) {
            $this->em->persist(new CardSiteReviewComment($card, $comment));
        }

        return $comment;
    }
}
