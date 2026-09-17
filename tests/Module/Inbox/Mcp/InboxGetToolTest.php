<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\Mcp;

use App\Module\Inbox\Command\ReplyToInboxItemCommand;
use App\Module\Inbox\Command\ReplyToInboxItemHandler;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Mcp\InboxAskTool;
use App\Module\Inbox\Mcp\InboxGetTool;
use App\Module\Inbox\Mcp\InboxListTool;
use App\Module\Review\Command\SubmitReviewCommand;
use App\Module\Review\Command\SubmitReviewHandler;
use App\Module\Review\Command\UndoVerdictCommand;
use App\Module\Review\Command\UndoVerdictHandler;
use App\Security\McpBoundProjectVoter;
use App\Tests\Support\McpTokenScenario;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class InboxGetToolTest extends KernelTestCase
{
    use InboxToolScenario;
    use McpTokenScenario;

    private EntityManagerInterface $em;
    private InboxGetTool $tool;
    private RecordingAuditor $audit;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        // Installed before the tool is built, so the voter it reaches records here.
        $this->audit = RecordingAuditor::installedIn(self::getContainer());

        $tool = self::getContainer()->get(InboxGetTool::class);
        self::assertInstanceOf(InboxGetTool::class, $tool);
        $this->tool = $tool;
    }

    public function test_the_tool_refuses_while_the_flag_is_off(): void
    {
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-get-flag-off'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The inbox is switched off on this instance.');
        ($this->tool)((string) Uuid::v4());
    }

    public function test_get_and_reader_list_return_the_original_review_result_after_withdrawal(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('read-review-result');
        $document = $this->document($this->em, $project);
        $document->addVersion('# Design', '<h1>Design</h1>');
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);
        $ask = self::getContainer()->get(InboxAskTool::class);
        self::assertInstanceOf(InboxAskTool::class, $ask);
        $sessionId = (string) Uuid::v4();
        $asked = $ask($sessionId, [[
            'kind' => 'review',
            'title' => 'Review the design',
            'blocking' => true,
            'reviewDocumentId' => (string) $document->id,
        ]]);
        $itemId = $asked['items'][0]['itemId'];
        $item = $this->em->find(InboxItem::class, $itemId);
        self::assertInstanceOf(InboxItem::class, $item);
        $reply = self::getContainer()->get(ReplyToInboxItemHandler::class);
        $posted = $reply(new ReplyToInboxItemCommand($item, $project->owner, 'Read this additional context.', (string) Uuid::v4()));
        $open = ($this->tool)($itemId);
        self::assertSame([[
            'replyId' => (string) $posted->id,
            'authorId' => (string) $project->owner->id,
            'authorName' => $project->owner->fullName,
            'body' => 'Read this additional context.',
            'createdAt' => $posted->createdAt->format(\DATE_ATOM),
        ]], $open['replies']);
        self::assertNotNull($open['review']);
        self::assertSame('document', $open['review']['targetKind']);
        self::assertSame((string) $document->id, $open['review']['documentId']);
        self::assertNull($open['review']['verdict']);
        $submit = self::getContainer()->get(SubmitReviewHandler::class);
        self::assertInstanceOf(SubmitReviewHandler::class, $submit);
        $result = $submit(new SubmitReviewCommand($project->owner, $document, 'approved', 1, 'Ready to build.'));
        $undo = self::getContainer()->get(UndoVerdictHandler::class);
        self::assertInstanceOf(UndoVerdictHandler::class, $undo);
        $withdrawal = $undo(new UndoVerdictCommand($document, $project->owner, (string) $result->id));
        $expected = [
            'targetKind' => 'document',
            'targetLabel' => 'The design',
            'documentId' => (string) $document->id,
            'pullRequestId' => null,
            'verdict' => 'approved',
            'note' => 'Ready to build.',
            'reviewerId' => (string) $project->owner->id,
            'reviewerName' => $project->owner->fullName,
            'submittedAt' => $result->submittedAt->format(\DATE_ATOM),
            'reviewedVersionNumber' => 1,
            'documentReviewId' => (string) $result->id,
            'withdrawal' => [
                'reviewId' => (string) $withdrawal->id,
                'reviewerId' => (string) $project->owner->id,
                'reviewerName' => $project->owner->fullName,
                'submittedAt' => $withdrawal->submittedAt->format(\DATE_ATOM),
            ],
        ];
        $this->em->clear();

        $closed = ($this->tool)($itemId, $sessionId);
        self::assertSame('done', $closed['state']);
        self::assertSame($expected, $closed['review']);
        $list = self::getContainer()->get(InboxListTool::class);
        self::assertInstanceOf(InboxListTool::class, $list);
        $rows = $list(askId: $asked['askId'], readerSessionId: $sessionId);
        self::assertCount(1, $rows['items']);
        $row = $rows['items'][0];
        self::assertArrayHasKey('review', $row);
        self::assertSame($expected, $row['review'] ?? null);
    }

    public function test_an_item_reads_back_in_full_with_its_links_and_its_asks(): void
    {
        $this->enableInbox();
        $project = $this->makeProject('inbox-get');
        $card = $this->card($this->em, $project, 7);
        $document = $this->document($this->em, $project);
        $this->em->flush();
        $this->actAsMcpTokenBoundTo($project);
        $sessionId = (string) Uuid::v4();
        $asked = $this->askQuestion('Which column?', [
            'body' => '## Detail',
            'multiple' => true,
            'freeText' => true,
            'cardIds' => [(string) $card->id],
            'documentIds' => [(string) $document->id],
        ], $sessionId);

        $item = ($this->tool)($asked['items'][0]['itemId']);

        self::assertSame($asked['items'][0]['itemId'], $item['itemId']);
        self::assertSame(1, $item['number']);
        self::assertSame('question', $item['kind']);
        self::assertSame('## Detail', $item['body']);
        self::assertSame(['yes', 'no'], $item['options']);
        self::assertTrue($item['multiple']);
        self::assertTrue($item['freeText']);
        self::assertSame('open', $item['state']);
        self::assertSame([], $item['selectedOptions']);
        self::assertNull($item['answerText']);
        self::assertNull($item['closeNote']);
        self::assertSame([['cardId' => (string) $card->id, 'number' => 7, 'title' => 'Ship it']], $item['cards']);
        self::assertSame([['documentId' => (string) $document->id, 'title' => 'The design']], $item['documents']);
        self::assertSame([['askId' => $asked['askId'], 'sessionId' => $sessionId, 'closedAt' => null]], $item['asks']);
    }

    public function test_an_item_in_another_project_is_not_reachable_and_the_refusal_is_audited(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-get-theirs'));
        $theirs = $this->askQuestion('Not yours');

        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-get-mine'));

        try {
            ($this->tool)($theirs['items'][0]['itemId']);
            self::fail('Expected a refusal for an item of another project.');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('not found or not accessible', $e->getMessage());
        }

        $record = $this->audit->record('inbox.mcp_access_denied');
        self::assertSame(McpBoundProjectVoter::INBOX_ITEM_READ, $record->context['attribute']);
        self::assertSame($theirs['items'][0]['itemId'], $record->context['subjectId']);
    }

    public function test_a_malformed_id_is_reported_rather_than_fatal(): void
    {
        $this->enableInbox();
        $this->actAsMcpTokenBoundTo($this->makeProject('inbox-get-malformed'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"not-a-uuid" is not a valid item ID.');
        ($this->tool)('not-a-uuid');
    }
}
