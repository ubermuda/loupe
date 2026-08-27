<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use Symfony\Component\HtmlSanitizer\Parser\NativeParser;
use Symfony\Component\HtmlSanitizer\Parser\ParserInterface;

/**
 * Removes `<style>` and `<title>` from the tree the sanitizer is about to visit.
 *
 * MarkdownRenderer blocks by default so an unanticipated element keeps its text,
 * and these two are the ones whose text must not be kept. No configuration can
 * say so: HtmlSanitizer builds the body context from the elements that are NOT
 * in W3CReference::HEAD_ELEMENTS, so `dropElement('style')` never reaches the
 * visitor and the default action applies — printing a stylesheet as prose.
 *
 * Stripping at parse time is what a regex pre-pass could not do safely: an
 * unclosed `<style>` swallows the rest of the document into its text, which the
 * HTML parser resolves and a pattern cannot.
 */
final readonly class HeadElementStrippingParser implements ParserInterface
{
    /** @var list<string> */
    private const array STRIPPED = ['style', 'title'];

    private NativeParser $inner;

    public function __construct()
    {
        $this->inner = new NativeParser();
    }

    #[\Override]
    public function parse(string $html, string $context = 'body'): ?\Dom\Node
    {
        $parsed = $this->inner->parse($html, $context);
        if (null !== $parsed) {
            $this->strip($parsed);
        }

        return $parsed;
    }

    private function strip(\Dom\Node $node): void
    {
        // Snapshotted: Dom\NodeList is live, so removing during the walk would
        // skip the sibling that shifts into the vacated slot.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (\in_array(strtolower($child->nodeName), self::STRIPPED, true)) {
                $node->removeChild($child);
                continue;
            }

            $this->strip($child);
        }
    }
}
