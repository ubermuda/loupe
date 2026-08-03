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
        preg_match_all('~<h([1-6]) id="([^"]+)">(.*?)</h\1>~s', $renderedHtml, $matches, PREG_SET_ORDER);

        $headings = [];
        foreach ($matches as $match) {
            $headings[] = new DocumentHeading(
                (int) $match[1],
                html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                trim(html_entity_decode(strip_tags($match[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            );
        }

        return $headings;
    }
}
