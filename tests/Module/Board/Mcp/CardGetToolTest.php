<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Mcp;

use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPauseKind;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Mcp\CardCreateTool;
use App\Module\Board\Mcp\CardGetTool;
use App\Module\Forge\Entity\PullRequestChecks;
use App\Module\Forge\Entity\PullRequestMergeability;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Tests\Support\McpTokenScenario;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CardGetToolTest extends KernelTestCase
{
    use BoardToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private CardGetTool $tool;
    private CardCreateTool $createTool;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $tool = self::getContainer()->get(CardGetTool::class);
        self::assertInstanceOf(CardGetTool::class, $tool);
        $this->tool = $tool;

        $createTool = self::getContainer()->get(CardCreateTool::class);
        self::assertInstanceOf(CardCreateTool::class, $createTool);
        $this->createTool = $createTool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->disableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The board is switched off on this instance.');
        ($this->tool)('01920000-0000-7000-8000-000000000000');
    }

    public function test_a_card_reads_back_with_its_pull_request_links(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get'));
        $created = ($this->createTool)('Ship it', '## Body', 'tooling', pullRequestUrls: [
            'https://github.com/ubermuda/loupe/pull/7',
        ]);

        $card = ($this->tool)($created['cardId']);

        self::assertSame($created['cardId'], $card['cardId']);
        self::assertSame($created['number'], $card['number']);
        self::assertSame(1, $card['number']);
        self::assertSame('Ship it', $card['title']);
        self::assertSame('## Body', $card['body']);
        self::assertSame('tooling', $card['type']);
        self::assertCount(1, $card['pullRequests']);
        self::assertSame('ubermuda/loupe', $card['pullRequests'][0]['repository']);
        self::assertSame(7, $card['pullRequests'][0]['number']);
    }

    public function test_a_card_reads_its_active_pause_and_null_once_released(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-pause'));
        $created = ($this->createTool)('Ship it', 'Body', 'feature');
        self::assertNull(($this->tool)($created['cardId'])['pause']);
        $card = $this->em->find(Card::class, $created['cardId']);
        self::assertInstanceOf(Card::class, $card);
        $handler = self::getContainer()->get(PauseCardHandler::class);
        self::assertInstanceOf(PauseCardHandler::class, $handler);
        $pause = $handler(new PauseCardCommand($card, 'review-failed', 'fix-on-review', CardPauseKind::Retries));
        self::assertNotNull($pause);

        self::assertSame([
            'pauseId' => (string) $pause->id,
            'kind' => 'retries',
            'reason' => 'review-failed',
            'ruleId' => 'fix-on-review',
            'since' => $pause->createdAt->format(\DATE_ATOM),
        ], ($this->tool)($created['cardId'])['pause']);

        $pause->release('owner-resumed', new \DateTimeImmutable());
        $this->em->flush();

        self::assertNull(($this->tool)($created['cardId'])['pause']);
    }

    public function test_a_card_reads_the_stored_pull_request_state(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('card-get-state');
        $this->actAsMcpTokenBoundTo($project);
        $created = ($this->createTool)('Ship it', 'Body', 'tooling', pullRequestUrls: [
            'https://github.com/Ubermuda/Loupe/pull/7',
            'https://example.com/merge-requests/3',
        ]);
        $projectId = $project->id ?? throw new \LogicException('The project is persisted.');
        $row = $this->forgeRows()->findByKeys($projectId, [['forge' => 'github', 'repository' => 'ubermuda/loupe', 'number' => 7]])[0];
        $row->checks = PullRequestChecks::Failed;
        $row->failedChecks = ['phpunit'];
        $row->mergeability = PullRequestMergeability::Conflicting;
        $row->refreshedAt = new \DateTimeImmutable('2026-09-27T11:00:00+00:00');
        $this->em->flush();

        $read = ($this->tool)($created['cardId']);

        $state = $read['pullRequests'][0]['state'];
        self::assertNotNull($state);
        self::assertSame('open', $state['state']);
        self::assertSame('failed', $state['checks']);
        self::assertSame(['phpunit'], $state['failedChecks']);
        self::assertSame('conflicting', $state['mergeability']);
        self::assertSame('2026-09-27T11:00:00+00:00', $state['refreshedAt']);
        self::assertNull($read['pullRequests'][1]['state']);
        self::assertNotContains('automation', array_keys($read));
    }

    public function test_an_approval_of_an_older_head_reads_outdated(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('card-get-outdated');
        $this->actAsMcpTokenBoundTo($project);
        $created = ($this->createTool)('Ship it', 'Body', 'tooling', pullRequestUrls: ['https://github.com/ubermuda/loupe/pull/7']);
        $projectId = $project->id ?? throw new \LogicException('The project is persisted.');
        $row = $this->forgeRows()->findByKeys($projectId, [['forge' => 'github', 'repository' => 'ubermuda/loupe', 'number' => 7]])[0];
        $row->review = PullRequestReview::Approved;
        $row->approvalId = 'review7';
        $row->approvalSha = $row->coveredSha = 'approved7';
        $row->headSha = 'pushed7';
        $row->refreshedAt = new \DateTimeImmutable('2026-09-27T11:00:00+00:00');
        $this->em->flush();

        $state = ($this->tool)($created['cardId'])['pullRequests'][0]['state'];

        self::assertNotNull($state);
        self::assertSame('approval-outdated', $state['review']);
        self::assertFalse($state['readyToMerge']);
    }

    public function test_each_card_reads_its_links_from_its_own_side(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-links'));
        $blocker = ($this->createTool)('Blocker', 'Body', 'feature');
        $blocked = ($this->createTool)('Blocked', 'Body', 'feature', relatedCards: [['cardId' => $blocker['cardId'], 'kind' => 'blocked-by']]);

        self::assertSame(
            [['cardId' => $blocker['cardId'], 'number' => 1, 'title' => 'Blocker', 'status' => 'backlog', 'kind' => 'blocked-by']],
            ($this->tool)($blocked['cardId'])['relatedCards'],
        );
        self::assertSame(
            [['cardId' => $blocked['cardId'], 'number' => 2, 'title' => 'Blocked', 'status' => 'backlog', 'kind' => 'blocks']],
            ($this->tool)($blocker['cardId'])['relatedCards'],
        );
    }

    public function test_a_card_in_another_project_is_not_reachable(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-theirs'));
        $theirs = ($this->createTool)('Not yours', 'Body', 'feature');

        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-mine'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not found or not accessible');
        ($this->tool)($theirs['cardId']);
    }

    public function test_a_malformed_id_is_reported_rather_than_fatal(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-malformed'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"not-a-uuid" is not a valid card ID.');
        ($this->tool)('not-a-uuid');
    }

    public function test_a_card_reads_back_by_its_number(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-number'));
        ($this->createTool)('First', 'Body', 'feature');
        $second = ($this->createTool)('Second', 'Body', 'feature');

        $card = ($this->tool)(number: 2);

        self::assertSame($second['cardId'], $card['cardId']);
        self::assertSame(2, $card['number']);
    }

    public function test_a_number_reads_the_card_of_the_bound_project(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-number-theirs'));
        $theirs = ($this->createTool)('Theirs', 'Body', 'feature');
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-number-mine'));
        $mine = ($this->createTool)('Mine', 'Body', 'feature');
        self::assertSame($theirs['number'], $mine['number']);

        $card = ($this->tool)(number: 1);

        self::assertSame($mine['cardId'], $card['cardId']);
    }

    public function test_an_unknown_number_is_refused_with_the_number(): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-number-unknown'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('This project has no card 7.');
        ($this->tool)(number: 7);
    }

    /** @return iterable<string, array{?string, ?int, string}> */
    public static function refusedHandles(): iterable
    {
        yield 'zero' => [null, 0, 'Card numbers count from 1, so 0 is not a card number.'];
        yield 'negative' => [null, -3, 'Card numbers count from 1, so -3 is not a card number.'];
        yield 'both' => ['01920000-0000-7000-8000-000000000000', 1, 'Pass cardId or number, not both.'];
        yield 'neither' => [null, null, 'Pass cardId or number.'];
    }

    #[DataProvider('refusedHandles')]
    public function test_a_bad_handle_is_refused(?string $cardId, ?int $number, string $message): void
    {
        $this->enableBoard();
        $this->actAsMcpTokenBoundTo($this->makeProject('card-get-refused'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage($message);
        ($this->tool)($cardId, $number);
    }

    public function test_a_card_reads_back_each_linked_feedback_item_in_full(): void
    {
        $this->enableBoard();
        $project = $this->makeProject('card-get-feedback');
        $this->actAsMcpTokenBoundTo($project);
        $created = ($this->createTool)('Fix the header', 'Body', 'site-review');
        $card = $this->em->find(Card::class, $created['cardId']);
        self::assertInstanceOf(Card::class, $card);

        $drawn = new SiteReviewComment($project, 0, 'The logo is blurry', 'https://app.example/page', 'card:preview')
            ->addAnchor('header .logo', 'Logo', 'Acme', 'the ', ' mark');
        $drawn->strokes = [['space' => 'page', 'points' => [[0.1, 0.2], [0.3, 0.4]]]];
        $addressed = new SiteReviewComment($project, 1, 'The footer overlaps', 'https://app.example/other');
        $addressed->status = SiteReviewCommentStatus::Addressed;
        foreach ([$drawn, $addressed] as $comment) {
            $this->em->persist($comment);
            $this->em->persist(new CardSiteReviewComment($card, $comment));
        }
        $this->em->flush();
        $this->em->clear();

        self::assertSame([
            [
                'id' => (string) $drawn->id,
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
                'createdAt' => $drawn->createdAt->format(\DATE_ATOM),
            ],
            [
                'id' => (string) $addressed->id,
                'url' => 'https://app.example/other',
                'anchors' => [],
                'body' => 'The footer overlaps',
                'hasDrawing' => false,
                'status' => 'addressed',
                'context' => null,
                'createdAt' => $addressed->createdAt->format(\DATE_ATOM),
            ],
        ], ($this->tool)($created['cardId'])['siteReviewComments']);
    }

    private function forgeRows(): ForgePullRequestRepository
    {
        $rows = self::getContainer()->get(ForgePullRequestRepository::class);
        self::assertInstanceOf(ForgePullRequestRepository::class, $rows);

        return $rows;
    }
}
