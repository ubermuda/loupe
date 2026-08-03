<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\ValueObject\Decision;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;

/**
 * Turns a decision fence in a document into a group of radio controls, and reads
 * the result back.
 *
 * The fence is a pair of HTML comments around an ordinary list:
 *
 *     <!-- decision: deploy-target -->
 *     - [ ] Ship to staging first
 *     - [ ] Ship straight to production
 *     <!-- /decision -->
 *
 * Comments were chosen over a visible marker because every other Markdown
 * renderer hides them, so the block degrades to the list it already is.
 *
 * Detection happens on the parsed AST rather than on the source text, which is
 * what makes a fence quoted inside a code block inert: CommonMark hands it over
 * as FencedCode, never as the HtmlBlock this looks for.
 *
 * The controls are minted AFTER sanitization, alongside heading ids, so nothing
 * here widens what a document may write. In particular these radios are not the
 * `<input>` the sanitizer admits — that one is forced to type=checkbox, and
 * unifying the two would silently turn every decision into a checkbox.
 */
final readonly class DecisionBlockService
{
    /**
     * Namespaces minted ids away from the page's own (`composer-error` and
     * friends) and from the heading ids computed next to them.
     */
    private const string OPTION_ID_PREFIX = 'decision-';

    /** Groups a block's radios; inert as a form field, the controls post nothing themselves. */
    private const string RADIO_NAME_PREFIX = 'lp-decision-';

    /**
     * An id is what a selection is keyed by, so it is deliberately narrow: safe
     * verbatim in an attribute, in a regex character class, and in a URL.
     */
    private const string ID_PATTERN = '[a-z0-9][a-z0-9-]{0,63}';

    /**
     * A fence has to survive CommonMark and the sanitizer to be found again
     * afterwards, and neither preserves comments — so the markers are swapped
     * for text that does. The random component is what stops a document from
     * writing the sentinel itself and minting controls out of a plain list.
     */
    private string $nonce;

    public function __construct()
    {
        $this->nonce = bin2hex(random_bytes(16));
    }

    /**
     * Replaces every well-formed fence marker with its sentinel.
     *
     * A repeated id is left alone rather than transformed: two blocks sharing an
     * id would answer each other's question and collide on the minted element
     * ids. Its now-unpaired closing sentinel is dropped by toControls().
     */
    public function markParsedDocument(DocumentParsedEvent $event): void
    {
        /** @var array<string, true> $seen */
        $seen = [];
        $walker = $event->getDocument()->walker();

        while (null !== $walkerEvent = $walker->next()) {
            $node = $walkerEvent->getNode();
            if (!$walkerEvent->isEntering() || !$node instanceof HtmlBlock) {
                continue;
            }

            $literal = trim($node->getLiteral());

            if (1 === preg_match('~^<!--\s*decision:\s*('.self::ID_PATTERN.')\s*-->$~', $literal, $matches)) {
                if (isset($seen[$matches[1]])) {
                    continue;
                }
                $seen[$matches[1]] = true;
                $node->setLiteral($this->openSentinel($matches[1]));

                continue;
            }

            if (1 === preg_match('~^<!--\s*/decision\s*-->$~', $literal)) {
                $node->setLiteral($this->closeSentinel());
            }
        }
    }

    /**
     * Rewrites each sentinel-delimited list into its controls.
     *
     * The trailing sweep is not tidiness: the result is persisted as a version's
     * renderedHtml, and a sentinel left in it would sit in plainText() forever —
     * shifting every comment anchor below it on that version.
     */
    public function toControls(string $html): string
    {
        // The list must follow the opening sentinel immediately. A block holding
        // anything else — prose, no list at all — keeps whatever it had; only
        // the markers go.
        $withControls = preg_replace_callback(
            '~'.$this->sentinelPrefix().'OPEN_('.self::ID_PATTERN.')_END\s*<ul>(.*?)</ul>\s*'.$this->sentinelPrefix().'CLOSE~s',
            fn (array $matches): string => $this->fieldset($matches[1], $matches[2]) ?? '<ul>'.$matches[2].'</ul>',
            $html,
        );

        return (string) preg_replace(
            '~'.$this->sentinelPrefix().'(OPEN_'.self::ID_PATTERN.'_END|CLOSE)~',
            '',
            $withControls ?? $html,
        );
    }

    /**
     * Reads the decisions back out of rendered HTML.
     *
     * The rendered HTML is the source of truth rather than the Markdown: it is
     * what the reviewer answered against, and it is already stored, so the
     * payload can never describe a block the page does not show.
     *
     * @return list<Decision>
     */
    public function extract(string $html): array
    {
        preg_match_all(
            '~<fieldset class="lp-decision" data-decision-id="('.self::ID_PATTERN.')">(.*?)</fieldset>~s',
            $html,
            $blocks,
            PREG_SET_ORDER,
        );

        $decisions = [];
        foreach ($blocks as $block) {
            preg_match_all('~<label for="[^"]*">(.*?)</label>~s', $block[2], $labels);

            $decisions[] = new Decision(
                $block[1],
                array_map(self::plainText(...), $labels[1]),
            );
        }

        return $decisions;
    }

    /**
     * Shows the recorded answers, and on an earlier version locks them.
     *
     * Applied at display time rather than baked into the stored HTML, so a
     * selection never rewrites a version. Only attributes are added, and
     * strip_tags() drops those — the anchor basis is untouched.
     *
     * @param array<string, int> $selectedIndexByDecisionId
     */
    public function withSelections(string $html, array $selectedIndexByDecisionId, bool $readOnly): string
    {
        return (string) preg_replace_callback(
            '~<input type="radio" name="'.self::RADIO_NAME_PREFIX.'('.self::ID_PATTERN.')" value="(\d+)"([^>]*)>~',
            static function (array $matches) use ($selectedIndexByDecisionId, $readOnly): string {
                $attributes = $matches[3];
                if (($selectedIndexByDecisionId[$matches[1]] ?? null) === (int) $matches[2]) {
                    $attributes .= ' checked';
                }
                if ($readOnly) {
                    $attributes .= ' disabled';
                }

                return sprintf(
                    '<input type="radio" name="%s%s" value="%s"%s>',
                    self::RADIO_NAME_PREFIX,
                    $matches[1],
                    $matches[2],
                    $attributes,
                );
            },
            $html,
        );
    }

    /**
     * The controls for one block, or null when the list is not a flat one.
     *
     * A nested list is refused rather than converted: splitting on `<li>` would
     * cut through the inner list and emit unbalanced tags — into renderedHtml,
     * which is stored, so the browser and strip_tags() would then disagree about
     * the document's text and every anchor below it would be wrong.
     */
    private function fieldset(string $id, string $listHtml): ?string
    {
        if (1 === preg_match('~<(?:ul|ol)[\s>]~', $listHtml)) {
            return null;
        }

        preg_match_all('~<li>(.*?)</li>~s', $listHtml, $items);

        $options = '';
        foreach ($items[1] as $index => $item) {
            $optionId = self::OPTION_ID_PREFIX.$id.'-'.$index;
            $options .= sprintf(
                '<div class="lp-decision__option"><input type="radio" name="%s%s" value="%d" id="%s" data-decision-option><label for="%s">%s</label></div>',
                self::RADIO_NAME_PREFIX,
                $id,
                $index,
                $optionId,
                $optionId,
                self::optionLabel($item),
            );
        }

        return sprintf('<fieldset class="lp-decision" data-decision-id="%s">%s</fieldset>', $id, $options);
    }

    /**
     * A loose list wraps each item in a paragraph, and `[ ]` survives as literal
     * text because TaskListExtension is off — enabling it would delete those two
     * characters from plainText() in every document, fence or not, and shift
     * every anchor below the first task list.
     */
    private static function optionLabel(string $itemHtml): string
    {
        $label = trim($itemHtml);
        $label = (string) preg_replace('~^<p>(.*)</p>$~s', '$1', $label);

        return trim((string) preg_replace('~^\[[ xX]\]\s*~', '', trim($label)));
    }

    private static function plainText(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function sentinelPrefix(): string
    {
        return 'LPDECISION_'.$this->nonce.'_';
    }

    private function openSentinel(string $id): string
    {
        return $this->sentinelPrefix().'OPEN_'.$id.'_END';
    }

    private function closeSentinel(): string
    {
        return $this->sentinelPrefix().'CLOSE';
    }
}
