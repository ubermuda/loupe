<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Wraps an HTML comment's text in markers that survive HTML sanitization, so
 * {@see MarkdownRenderer} can turn it into a visible annotation afterwards. A
 * comment sanitizes away entirely — tag and text both — so its text has to
 * leave the parser as ordinary text to reach the other side at all.
 *
 * Registered for HtmlBlock and HtmlInline. Taking block-vs-inline from the node
 * type rather than from the shape of the rendered HTML is what leaves a comment
 * inside a fenced code block alone: fenced content is a FencedCode node and
 * never arrives here. Returning null hands the node back to CommonMark's own
 * renderer.
 */
final readonly class HtmlCommentNodeRenderer implements NodeRendererInterface
{
    /** The whole literal is one or more comments and nothing else. */
    private const string COMMENT_RUN = '~^(?:\s*<!--(?:(?!-->).)*-->)+\s*$~s';

    /** One comment within such a run, capturing its text. */
    private const string ONE_COMMENT = '~<!--((?:(?!-->).)*)-->~s';

    public function __construct(
        private string $blockOpen,
        private string $inlineOpen,
        private string $close,
    ) {
    }

    #[\Override]
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): ?string
    {
        if (!$node instanceof HtmlBlock && !$node instanceof HtmlInline) {
            return null;
        }

        $literal = trim($node->getLiteral());

        // `<!-- a --><!-- b -->` is one HtmlBlock, so this reads a run of
        // comments; `.*?` would swallow the markup between two of them.
        //
        // A PCRE error reading as "not a comment" is deliberate, unlike
        // MarkdownRenderer's passes that throw: detection may fail safe,
        // transformation may not.
        if (1 !== preg_match(self::COMMENT_RUN, $literal)) {
            return null;
        }

        preg_match_all(self::ONE_COMMENT, $literal, $matches);

        $open = $node instanceof HtmlBlock ? $this->blockOpen : $this->inlineOpen;
        $wrapped = '';
        foreach ($matches[1] as $text) {
            $text = trim($text);
            if ('' === $text) {
                continue;
            }

            // Unescaped, a comment containing `<script>` would reach the
            // sanitizer as a real element and be dropped, taking the note with it.
            $wrapped .= $open.htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8').$this->close;
        }

        return '' === $wrapped ? null : $wrapped;
    }
}
