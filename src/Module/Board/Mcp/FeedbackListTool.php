<?php

declare(strict_types=1);

namespace App\Module\Board\Mcp;

use App\Mcp\FlagGatedToolInterface;
use App\Module\Board\Command\ListFeedbackCommand;
use App\Module\Board\Command\ListFeedbackHandler;
use App\Module\Board\Install\BoardInstallFlags;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;

/**
 * Lists the feedback of the whole project, each item with the card it belongs to.
 *
 * @phpstan-import-type FeedbackAnchorSummary from CardPayload
 *
 * @phpstan-type FeedbackListItem array{id: string, url: string, anchors: list<FeedbackAnchorSummary>, body: string, hasDrawing: bool, status: string, context: ?string, createdAt: string, cardId: ?string, number: ?int, title: ?string}
 */
#[McpTool(name: self::NAME, description: 'List the feedback of the project bound to your MCP token. Feedback is what a reviewer leaves on a page with the site-review widget, and each item belongs to a card. Each item has its id, the page url, the anchors (the elements it points at), the body, hasDrawing, the status, the context (what a preview page said it served, or null), createdAt, and the cardId, number and title of its card. Returns the pending items by default. Pass status to read the addressed or the resolved ones, or all. Fix each item, then mark it with feedback_mark_addressed. To read the feedback of one card, use card_get.')]
final readonly class FeedbackListTool implements FlagGatedToolInterface
{
    public const string NAME = 'feedback_list';

    public function __construct(
        private BoardFlagGate $gate,
        private BoardSubjectResolver $subjects,
        private ListFeedbackHandler $listFeedback,
    ) {
    }

    #[\Override]
    public function gatedToolName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public function requiredFlag(): string
    {
        return BoardInstallFlags::FLAG_BOARD_ENABLED;
    }

    /**
     * @param string|null $status which feedback to return: pending (the default), addressed, resolved, or all
     *
     * @return array{feedback: list<FeedbackListItem>}
     */
    public function __invoke(?string $status = null): array
    {
        $this->gate->requireEnabled();

        try {
            $filter = match ($status) {
                null, 'pending' => SiteReviewCommentStatus::Pending,
                'all' => null,
                'addressed' => SiteReviewCommentStatus::Addressed,
                'resolved' => SiteReviewCommentStatus::Resolved,
                default => throw new ToolCallException(\sprintf('Unknown status "%s". Use pending, addressed, resolved or all.', $status)),
            };

            $view = ($this->listFeedback)(new ListFeedbackCommand($this->subjects->requireProject(), $filter));
            $links = $view->links;

            return ['feedback' => array_map(
                static function (SiteReviewComment $comment) use ($links): array {
                    $card = ($links[(string) $comment->id] ?? null)?->card;

                    return [
                        ...CardPayload::feedback($comment),
                        'cardId' => null === $card ? null : (string) $card->id,
                        'number' => $card?->number,
                        'title' => $card?->title,
                    ];
                },
                $view->feedback,
            )];
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ToolCallException('The feedback could not be read. The error has been logged.', previous: $e);
        }
    }
}
