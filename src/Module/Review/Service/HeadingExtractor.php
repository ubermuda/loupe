<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\ValueObject\DocumentHeading;

/**
 * Lists the headings of a rendered document version, in document order.
 *
 * Reads DocumentVersion::$renderedHtml rather than the Markdown source: the
 * rendered HTML is what the reviewer sees and what plainText() is derived from,
 * so headings found here are addressable both as page anchors and as positions
 * in the text every comment anchor is measured against.
 */
final readonly class HeadingExtractor
{
    /**
     * Headings stored before MarkdownRenderer started emitting ids carry none,
     * and nothing can link to them — `app:review:rerender-versions` is what
     * brings an old version up to date.
     *
     * @return list<DocumentHeading>
     */
    public function extract(string $renderedHtml): array
    {
        // Matches any heading and reads the id out of its attributes, rather than
        // pinning one attribute in one position: a renderer that later emits a
        // second attribute would otherwise silently yield no headings at all.
        preg_match_all(
            '~<h([1-6])((?:\s[^>]*)?)>(.*?)</h\1>~s',
            $renderedHtml,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );

        $headings = [];
        foreach ($matches as $match) {
            if (1 !== preg_match('~\bid="([^"]*)"~', $match[2][0], $id)) {
                continue;
            }

            $headings[] = new DocumentHeading(
                (int) $match[1][0],
                $this->decode($id[1]),
                trim($this->decode(strip_tags($match[3][0]))),
                // Everything before the heading's own text, measured the way
                // plainText() measures it. strip_tags() and entity decoding both act
                // per character, so the basis for a prefix is the prefix of the basis.
                mb_strlen($this->decode(strip_tags(substr($renderedHtml, 0, $match[3][1])))),
            );
        }

        return $headings;
    }

    private function decode(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
