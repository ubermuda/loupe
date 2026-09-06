<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

/**
 * Wraps each heading of a rendered version together with its approval control,
 * on the way to the page and never into the stored HTML.
 *
 * The control must add NO text. The pane's textContent is the basis every
 * comment anchor is measured against and every section digest is taken from, so
 * one character of label here moves every anchor below the first heading. Tags,
 * attributes and an SVG survive strip_tags() as nothing, which is why the button
 * carries its name on aria-label and its icon as markup.
 *
 * The heading pattern matches HeadingExtractor's. Two spellings of "which
 * headings exist" would drift, and the ids this keys on come from that class.
 */
final readonly class SectionControlInjector
{
    /**
     * @param array<string, string> $controlsByHeadingId control markup, keyed by heading id, already free of whitespace between its tags
     */
    public function inject(string $html, array $controlsByHeadingId): string
    {
        if ([] === $controlsByHeadingId) {
            return $html;
        }

        $injected = preg_replace_callback(
            '~<h([1-6])((?:\s[^>]*)?)>(.*?)</h\1>~s',
            static function (array $matches) use ($controlsByHeadingId): string {
                if (1 !== preg_match('~\bid="([^"]*)"~', $matches[2], $id)) {
                    return $matches[0];
                }

                $headingId = html_entity_decode($id[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $control = $controlsByHeadingId[$headingId] ?? null;
                if (null === $control) {
                    return $matches[0];
                }

                // No whitespace between the three parts, for the reason above.
                return '<div class="lp-section-head">'.$matches[0].$control.'</div>';
            },
            $html,
        );

        // Falling back to the unwrapped HTML would drop every control with no
        // error; DecisionBlockService throws on its own passes for the same reason.
        return $injected ?? throw new \RuntimeException('Section control injection failed: '.preg_last_error_msg().'.');
    }
}
