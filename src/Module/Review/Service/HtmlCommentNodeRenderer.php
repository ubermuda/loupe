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

        // Anchored at both ends: a block that merely starts with a comment, or
        // carries trailing markup on the closing line, falls through untouched
        // rather than being partly swallowed.
        if (1 !== preg_match('~^<!--(.*)-->$~s', trim($node->getLiteral()), $matches)) {
            return null;
        }

        $text = trim($matches[1]);
        if ('' === $text) {
            return null;
        }

        // Unescaped, a comment containing `<script>` would reach the sanitizer
        // as a real element and be dropped, taking the note with it.
        return ($node instanceof HtmlBlock ? $this->blockOpen : $this->inlineOpen)
            .htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
            .$this->close;
    }
}
