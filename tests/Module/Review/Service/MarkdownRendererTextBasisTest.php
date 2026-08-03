<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Review\Service\MarkdownRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HtmlSanitizer\Reference\W3CReference;

/**
 * Guards the two-sided contract behind every comment anchor.
 *
 * An anchor is stored as an offset into DocumentVersion::plainText(), which PHP
 * derives with strip_tags(); the browser re-finds it by walking text nodes, which
 * yields textContent. Both sides must read the same characters in the same order.
 * \Dom\HTMLDocument parses to the HTML5 spec, so it stands in for the browser here.
 */
final class MarkdownRendererTextBasisTest extends TestCase
{
    /** Void elements hold no text, so "keeps its text" does not apply to them. */
    private const array VOID_ELEMENTS = [
        'area', 'br', 'col', 'command', 'embed', 'hr', 'image', 'img', 'input',
        'keygen', 'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * A blocked element leaves a bare text node where the tag was, and the HTML5
     * parser moves such a node out of table context to before the table, while
     * strip_tags() leaves it in place. `<caption>` is rendered rather than blocked
     * for exactly this reason; this is what would notice the next one.
     */
    public function test_php_and_the_html5_parser_read_the_same_text_in_the_same_order(): void
    {
        $renderer = new MarkdownRenderer(new NullLogger());
        $mismatches = [];
        $checked = 0;

        foreach ($this->elementNames() as $element) {
            foreach ($this->positions($element) as $position => $input) {
                ++$checked;
                $html = $renderer->render($input);
                $php = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $dom = $this->textContent($html);

                if ($php !== $dom) {
                    $mismatches[] = sprintf('<%s> %s: php=%s dom=%s', $element, $position, json_encode($php), json_encode($dom));
                }
            }
        }

        self::assertGreaterThan(400, $checked, 'the sweep must actually exercise the element table');
        self::assertSame([], $mismatches);
    }

    /**
     * The block-or-drop decision reads W3CReference::BODY_ELEMENTS, a third-party
     * constant. If a Symfony release reclassifies an element as unsafe it stops
     * being blocked and starts being dropped, deleting its text and shifting every
     * anchor below it — with nothing else in the suite failing.
     */
    public function test_every_element_the_sanitizer_considers_safe_still_contributes_its_text(): void
    {
        $renderer = new MarkdownRenderer(new NullLogger());
        $swallowed = [];
        $checked = 0;

        foreach ($this->elementNames() as $element) {
            // <template> is excluded because the HTML5 parser puts its content in a
            // separate fragment, so no sanitizer configuration can reach it. That is
            // true of the pre-existing config too, so nothing changed for it.
            if (!(W3CReference::BODY_ELEMENTS[$element] ?? false) || in_array($element, [...self::VOID_ELEMENTS, 'template'], true)) {
                continue;
            }

            ++$checked;
            if (!str_contains($renderer->render("<$element>KEEP</$element>"), 'KEEP')) {
                $swallowed[] = $element;
            }
        }

        self::assertGreaterThan(100, $checked, 'the sweep must actually exercise the element table');
        self::assertSame([], $swallowed);
    }

    /** @return list<string> */
    private function elementNames(): array
    {
        return [
            ...array_keys(W3CReference::BODY_ELEMENTS),
            // Never in the reference, so they reach the sanitizer's drop default.
            'script', 'style', 'title', 'iframe', 'object', 'embed', 'svg', 'math',
            'noembed', 'noframes', 'foobar',
        ];
    }

    /** @return array<string, string> */
    private function positions(string $element): array
    {
        return [
            'bare' => "<$element>TEXT</$element>",
            'adjacent' => "<p>before</p><$element>ONE</$element><$element>TWO</$element><p>after</p>",
            'in a table' => "<table><tr><td>CELL</td></tr><$element>TEXT</$element></table>",
            'in a list' => "<ul><li>item</li><$element>TEXT</$element></ul>",
            'wrapping blocks' => "<$element><p>a</p><p>b</p></$element>",
        ];
    }

    private function textContent(string $html): string
    {
        $document = \Dom\HTMLDocument::createFromString(
            '<!doctype html><html><body>'.$html.'</body></html>',
            LIBXML_NOERROR,
        );

        // Throwing rather than defaulting: a missing body would make every
        // comparison in this file pass against an empty string.
        $body = $document->body ?? throw new \LogicException('parsed document has no body');

        return $body->textContent ?? '';
    }
}
