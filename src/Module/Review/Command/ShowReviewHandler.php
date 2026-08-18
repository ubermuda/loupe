<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DecisionSelectionRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Repository\ReviewRepository;
use App\Module\Review\Service\DecisionBlockService;
use App\Module\Review\ValueObject\Anchor;

final readonly class ShowReviewHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private CommentRepository $comments,
        private ReviewRepository $reviews,
        private DecisionSelectionRepository $decisionSelections,
        private DecisionBlockService $decisionBlocks,
    ) {
    }

    /**
     * Returns the review state for the current version of an already-authorized document.
     *
     * Comments are grouped into threads: the top-level list contains only root comments (no parent);
     * each root comment carries its direct replies in the `thread` key. Only the root reports a
     * `status` — it is a property of the thread, and a reply has none of its own.
     *
     * Each reported `quote` is widened to whole words (see snapToWordEdges) so a reader
     * doesn't have to guess which sentence a mid-word excerpt came from — EXCEPT on a
     * comment carrying a `replacement`, where the quote is the span to be substituted
     * rather than an excerpt to read. Widening one of those would hand back a string
     * containing characters the reviewer never selected, and replacing it in the
     * markdown would delete them.
     *
     * `replacement` is null for a prose comment, '' for a strike (remove the quote),
     * and the new text for a suggested rewording. Only root comments carry it — a
     * reply proposes nothing.
     *
     * `author` reports the class of writer rather than the writer: the payload is machine-facing,
     * so a human reviewer's name, email and id stay out of it.
     *
     * `decisions` lists every decision block in the current version, answered or
     * not, keyed by the identifier the document declared. `selected` reports the
     * option as it read when the reviewer chose it rather than whatever now sits
     * at that index, so a reworded or reordered block cannot rewrite the answer;
     * `answered_at_version` says which version they were reading at the time.
     *
     * @return array{
     *     status: string,
     *     verdict: string|null,
     *     version: int,
     *     comments: list<array{id: string, quote: string, body: string, replacement: string|null, author: 'agent'|'human', status: string, orphaned: bool, thread: list<array{id: string, quote: string, body: string, author: 'agent'|'human', orphaned: bool}>}>,
     *     decisions: list<array{id: string, options: list<string>, selected: string|null, selected_index: int|null, answered_at: string|null, answered_at_version: int|null}>
     * }
     */
    public function __invoke(ShowReviewCommand $command): array
    {
        $document = $command->document;

        $currentVersion = $this->documentVersions->findLatest($document);
        $review = $this->reviews->findLatestByVersion($currentVersion);
        $allComments = $this->comments->findByVersion($currentVersion);

        // Index replies by parent id so we can build threads in O(n).
        /** @var array<string, list<Comment>> $repliesByParentId */
        $repliesByParentId = [];
        foreach ($allComments as $comment) {
            if (null !== $comment->parent) {
                $repliesByParentId[(string) $comment->parent->id][] = $comment;
            }
        }

        $threadedComments = [];
        foreach ($allComments as $comment) {
            // Only top-level (parentless) comments appear in the root list.
            if (null !== $comment->parent) {
                continue;
            }

            $commentId = (string) $comment->id;
            $replies = $repliesByParentId[$commentId] ?? [];

            $thread = array_map(
                static fn (Comment $reply) => [
                    'id' => (string) $reply->id,
                    'quote' => self::snapToWordEdges($reply->anchor),
                    'body' => $reply->body,
                    'author' => $reply->author->isAgent() ? 'agent' : 'human',
                    'orphaned' => $reply->orphaned,
                ],
                $replies,
            );

            $threadedComments[] = [
                'id' => $commentId,
                'quote' => $comment->isSuggestion
                    ? $comment->anchor->quote
                    : self::snapToWordEdges($comment->anchor),
                'body' => $comment->body,
                'replacement' => $comment->replacement,
                'author' => $comment->author->isAgent() ? 'agent' : 'human',
                'status' => $comment->threadStatus->value,
                'orphaned' => $comment->orphaned,
                'thread' => array_values($thread),
            ];
        }

        $selections = $this->decisionSelections->findByDocumentIndexedByDecisionId($document);
        $decisions = [];
        foreach ($this->decisionBlocks->extract($currentVersion->renderedHtml) as $decision) {
            $selection = $selections[$decision->id] ?? null;
            $selectedIndex = null === $selection
                ? null
                : $decision->resolveIndex($selection->optionLabel, $selection->optionIndex);

            $decisions[] = [
                'id' => $decision->id,
                'options' => $decision->options,
                'selected' => $selection?->optionLabel,
                // Always indexes the `options` reported alongside it, because the
                // page resolves it by the same rule. Null means the chosen option
                // is no longer offered.
                'selected_index' => $selectedIndex,
                'answered_at' => $selection?->selectedAt->format(\DateTimeInterface::ATOM),
                'answered_at_version' => $selection?->versionNumber,
            ];
        }

        return [
            'status' => $document->status->value,
            'verdict' => $review?->verdict->value,
            'version' => $currentVersion->versionNumber,
            'comments' => $threadedComments,
            'decisions' => $decisions,
        ];
    }

    /**
     * Widens a quote outwards to the nearest whitespace on each side, so a selection
     * that started or ended mid-word is reported as whole words.
     *
     * Reporting only — the stored anchor is untouched, and nothing here feeds
     * AnchorService, so resolution and offsetHint are unaffected. The widening draws
     * on the anchor's own prefix/suffix rather than the document text: for an orphaned
     * comment the stored offset points into a version that no longer exists, so the
     * document text would splice in characters from an unrelated location.
     */
    private static function snapToWordEdges(Anchor $anchor): string
    {
        if ('' === $anchor->quote) {
            return '';
        }

        $lead = '';
        // \z, not $ — $ also matches before a trailing newline, which would drag a
        // word across a line break onto a quote that already starts at a line edge.
        if (1 === preg_match('/\A\S/u', $anchor->quote) && 1 === preg_match('/\S+\z/u', $anchor->prefix, $before)) {
            $lead = self::completesAWord($before[0], $anchor->prefix);
        }

        $trail = '';
        if (1 === preg_match('/\S\z/u', $anchor->quote) && 1 === preg_match('/\A\S+/u', $anchor->suffix, $after)) {
            $trail = self::completesAWord($after[0], $anchor->suffix);
        }

        return $lead.$anchor->quote.$trail;
    }

    /**
     * The run of non-whitespace to splice on, or '' when it fills the whole context.
     *
     * A run that reaches the far end of the captured context means no word boundary
     * was found inside it, so the real boundary lies somewhere the anchor never
     * recorded. Splicing then adds text the commenter did not select rather than
     * completing their word — and it is the normal case, not an edge case: scripts
     * written without spaces (Japanese, Chinese, Thai, Lao, Khmer) have no boundary
     * to find at all, and neither do URLs, file paths or identifiers.
     */
    private static function completesAWord(string $run, string $context): string
    {
        return $run === $context ? '' : $run;
    }
}
