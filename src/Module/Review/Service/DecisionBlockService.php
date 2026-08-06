<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\ValueObject\Decision;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;

/**
 * Turns a decision fence in a document into a group of radio controls, and reads
 * the result back. The syntax itself is documented for authors in the
 * loupe-documents skill.
 *
 * Detection happens on the parsed AST rather than on the source text, which is
 * what makes a fence quoted inside a code block inert: CommonMark hands it over
 * as FencedCode, never as the HtmlBlock this looks for.
 *
 * The controls are minted AFTER sanitization, alongside heading ids, so nothing
 * here widens what a document may write: the sanitizer admits no `<input>` at
 * all, and markup a document writes itself can never become a control. Moving
 * this pass ahead of sanitize() would hand that power back.
 */
final readonly class DecisionBlockService
{
    /**
     * Namespaces minted ids away from the page's own (`composer-error` and
     * friends) and from the heading ids computed next to them.
     *
     * Joined with `_`, which ID_PATTERN forbids inside a document's own id, so
     * the block and option namespaces cannot meet. Joining them with `-` did
     * let them: a document declaring both `x-0` and `block-x` minted
     * `decision-block-x-0` twice, once as a block and once as an option.
     */
    private const string OPTION_ID_PREFIX = 'decision_option_';

    private const string ID_SEPARATOR = '_';

    /** Groups a block's radios; inert as a form field, the controls post nothing themselves. */
    private const string RADIO_NAME_PREFIX = 'lp-decision-';

    /**
     * Turbo stream target for one block, so a failed submission can put the
     * radios back without touching the surrounding prose.
     */
    private const string BLOCK_ID_PREFIX = 'decision_block_';

    /**
     * The two attributes every reader keys on. Emission writes them and every
     * matcher below looks for nothing else, so no regex depends on the order the
     * surrounding attributes happen to be written in — an emission the matchers
     * silently stopped recognising would read every stored version as unanswered.
     */
    private const string BLOCK_MARKER = 'data-decision-id';
    private const string OPTION_MARKER = 'data-decision-option';

    /**
     * An id is what a selection is keyed by, so it is deliberately narrow: safe
     * verbatim in an attribute, in a regex character class, and in a URL. The
     * leading character excludes `-`, and 64 is the ceiling.
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
        /** @var list<array{HtmlBlock, string|null}> $markers */
        $markers = [];
        $walker = $event->getDocument()->walker();

        while (null !== $walkerEvent = $walker->next()) {
            $node = $walkerEvent->getNode();
            if (!$walkerEvent->isEntering() || !$node instanceof HtmlBlock) {
                continue;
            }

            $literal = trim($node->getLiteral());

            if (1 === preg_match('~^<!--\s*decision:\s*('.self::ID_PATTERN.')\s*-->$~', $literal, $matches)) {
                $markers[] = [$node, $matches[1]];
            } elseif (1 === preg_match('~^<!--\s*/decision\s*-->$~', $literal)) {
                $markers[] = [$node, null];
            }
        }

        $this->sentinelPairedFences($markers);
    }

    /**
     * Writes a sentinel only where an opener and a closer actually pair up.
     *
     * Pairing here rather than in the markup is what stops a malformed fence
     * breaking a well-formed one further down: an unclosed opener used to leave
     * a sentinel that the pairing regex then matched against the NEXT fence's
     * closer, swallowing both lists and silently costing the valid block its
     * controls. Every other malformed shape degrades where it stands, and this
     * was the one that reached downstream.
     *
     * It also leaves toControls() a guarantee it cannot establish itself: the
     * sentinels it sees are flat and non-overlapping, so its `.*?` can never
     * cross another fence.
     *
     * @param list<array{HtmlBlock, string|null}> $markers in document order, id null for a closer
     */
    private function sentinelPairedFences(array $markers): void
    {
        /** @var array<string, true> $paired */
        $paired = [];
        /** @var array{HtmlBlock, string}|null $open */
        $open = null;

        foreach ($markers as [$node, $id]) {
            if (null !== $id) {
                // A second opener means the first never closed, so it is
                // abandoned: only the nearest can pair with the next closer.
                // A repeated id is abandoned too, and takes the following
                // closer with it — two blocks sharing an id would answer each
                // other's question and collide on the minted element ids.
                $open = isset($paired[$id]) ? null : [$node, $id];

                continue;
            }

            // A closer with nothing open — the document's own stray marker.
            if (null === $open) {
                continue;
            }

            [$openNode, $openId] = $open;
            $openNode->setLiteral($this->openSentinel($openId));
            $node->setLiteral($this->closeSentinel());
            $paired[$openId] = true;
            $open = null;
        }
    }

    /**
     * Rewrites each sentinel-delimited list into its controls.
     *
     * Ordered and unordered lists both convert. Numbering is what lets a reviewer
     * say "option 2" in a comment, so refusing `<ol>` would fight the documents
     * this exists to serve.
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
            '~'.$this->sentinelPrefix().'OPEN_('.self::ID_PATTERN.')_END\s*(<(ul|ol)(?:\s[^>]*)?>)(.*?)(</\3>)\s*'.$this->sentinelPrefix().'CLOSE~s',
            fn (array $matches): string => $this->fieldset($matches[1], $matches[4])
                ?? $matches[2].$matches[4].$matches[5],
            $html,
        );

        // Deliberately not the well-formed sentinel pattern: HtmlSanitizer cuts
        // its input with a raw substr() before parsing, so a large document can
        // lose the second half of a sentinel. Matching the nonce plus whatever
        // follows catches every cut after the nonce.
        $swept = preg_replace(
            '~'.$this->sentinelRoot().'[A-Za-z0-9_-]*~',
            '',
            $withControls ?? $html,
        );

        return self::withoutTrailingPrefixOf(
            $swept ?? throw new \RuntimeException('Decision sentinel sweep failed: '.preg_last_error_msg().'.'),
            $this->sentinelRoot(),
        );
    }

    /**
     * Drops a trailing fragment that is a proper prefix of $marker.
     *
     * The regex sweep above needs the whole nonce to recognise a fragment, so it
     * cannot see a cut that landed inside the nonce itself. Anything severed by
     * truncation is at the very end of the string by construction, so comparing
     * suffixes against prefixes settles it exactly.
     */
    private static function withoutTrailingPrefixOf(string $html, string $marker): string
    {
        for ($length = min(strlen($marker), strlen($html)); $length > 0; --$length) {
            if (substr($html, -$length) === substr($marker, 0, $length)) {
                return substr($html, 0, -$length);
            }
        }

        return $html;
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
            '~<fieldset[^>]*\s'.self::BLOCK_MARKER.'="('.self::ID_PATTERN.')"[^>]*>(.*?)</fieldset>~s',
            $html,
            $blocks,
            PREG_SET_ORDER,
        );

        $decisions = [];
        foreach ($blocks as $block) {
            preg_match_all('~<label[^>]*>(.*?)</label>~s', $block[2], $labels);

            $decisions[] = new Decision(
                $block[1],
                // Shared with headings rather than stripping tags here: an option
                // written as an image alone reduced to '' under strip_tags(), so it
                // reached the agent as an empty string and two such options stored
                // the same label. DisplayLabel reads the `alt` instead.
                array_map(DisplayLabel::fromHtml(...), $labels[1]),
            );
        }

        return $decisions;
    }

    /**
     * One block's markup, verbatim from the version it was rendered into.
     *
     * A failed submission streams this back so the radios show what is stored
     * rather than the click that was refused. Taken from the stored HTML rather
     * than re-rendered, so what replaces the block is byte-identical to what the
     * reviewer already has apart from the selection attributes.
     */
    public function blockHtml(string $html, string $decisionId): ?string
    {
        if (1 !== preg_match('~^'.self::ID_PATTERN.'$~', $decisionId)) {
            return null;
        }

        $found = preg_match(
            '~<fieldset[^>]*\s'.self::BLOCK_MARKER.'="'.preg_quote($decisionId, '~').'"[^>]*>.*?</fieldset>~s',
            $html,
            $matches,
        );

        return 1 === $found ? $matches[0] : null;
    }

    /** The DOM id blockHtml()'s markup carries, for a Turbo stream to target. */
    public static function blockElementId(string $decisionId): string
    {
        return self::BLOCK_ID_PREFIX.$decisionId;
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
        $marked = preg_replace_callback(
            '~<input[^>]*\s'.self::OPTION_MARKER.'="('.self::ID_PATTERN.'):(\d+)"[^>]*>~',
            static function (array $matches) use ($selectedIndexByDecisionId, $readOnly): string {
                $added = '';
                if (($selectedIndexByDecisionId[$matches[1]] ?? null) === (int) $matches[2]) {
                    $added .= ' checked';
                }
                if ($readOnly) {
                    $added .= ' disabled';
                }

                return substr($matches[0], 0, -1).$added.'>';
            },
            $html,
        );

        // Falling back to '' would blank the document body on screen; the
        // neighbouring heading-id pass throws for the same reason.
        return $marked ?? throw new \RuntimeException('Decision selection marking failed: '.preg_last_error_msg().'.');
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
            $optionId = self::OPTION_ID_PREFIX.$id.self::ID_SEPARATOR.$index;
            $options .= sprintf(
                '<div class="lp-decision__option"><input type="radio" name="%s%s" value="%d" id="%s" %s="%s:%d"><label for="%s">%s</label></div>',
                self::RADIO_NAME_PREFIX,
                $id,
                $index,
                $optionId,
                self::OPTION_MARKER,
                $id,
                $index,
                $optionId,
                self::optionLabel($item),
            );
        }

        return sprintf(
            '<fieldset class="lp-decision" id="%s%s" %s="%s">%s</fieldset>',
            self::BLOCK_ID_PREFIX,
            $id,
            self::BLOCK_MARKER,
            $id,
            $options,
        );
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

    /** Everything a sentinel shares, whichever kind it is — the sweep keys on this. */
    private function sentinelRoot(): string
    {
        return 'LPDECISION_'.$this->nonce;
    }

    private function sentinelPrefix(): string
    {
        return $this->sentinelRoot().'_';
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
