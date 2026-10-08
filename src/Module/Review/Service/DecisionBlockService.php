<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\ValueObject\Decision;
use App\Module\Review\ValueObject\DecisionType;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableSection;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns a decision fence in a document into a group of radio or checkbox
 * controls, and reads the result back. The syntax itself is documented for
 * authors in the loupe-documents skill.
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

    /** Groups a block's controls; inert as a form field, they post nothing themselves. */
    private const string CONTROL_NAME_PREFIX = 'lp-decision-';

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
     * How many options the block takes. Absent on every version rendered before
     * multi-choice existed, and those read as single-choice.
     */
    private const string TYPE_MARKER = 'data-decision-type';

    /** The list-item markers an author writes to pick the kind of block. */
    private const string SINGLE_ITEM_MARKER = '~^\( \)\s*~';
    private const string MULTIPLE_ITEM_MARKER = '~^\[[ xX]\]\s*~';

    /** A trailing `(recommended: high)` an author writes after the option it favours. */
    private const string RECOMMENDED_MARKER = '~^(.*\S)\s*\(recommended:\s*(high|moderate|low)\)$~is';

    private const string BADGE_MARKER = 'data-decision-recommended';

    private const array CONFIDENCES = ['high', 'moderate', 'low'];

    /** How many of the three stars a confidence fills. */
    private const array STARS = ['high' => 3, 'moderate' => 2, 'low' => 1];

    private const int STAR_COUNT = 3;

    /** The class a marked table carries once the sentinel is resolved. */
    private const string OPTION_TABLE_CLASS = 'lp-option-table';

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
        /** @var array{HtmlBlock, list<Table>}|null $optionsOpen */
        $optionsOpen = null;
        /** @var list<array{HtmlBlock, HtmlBlock, list<Table>}> $optionFences */
        $optionFences = [];
        $walker = $event->getDocument()->walker();

        while (null !== $walkerEvent = $walker->next()) {
            $node = $walkerEvent->getNode();
            if (!$walkerEvent->isEntering()) {
                continue;
            }

            if ($node instanceof Table) {
                if (null !== $optionsOpen && $node->parent() === $optionsOpen[0]->parent() && self::columnCount($node) >= 2) {
                    $optionsOpen[1][] = $node;
                }

                continue;
            }

            if (!$node instanceof HtmlBlock) {
                continue;
            }

            $literal = trim($node->getLiteral());

            if (1 === preg_match('~^<!--\s*decision:\s*('.self::ID_PATTERN.')\s*-->$~', $literal, $matches)) {
                $markers[] = [$node, $matches[1]];
            } elseif (1 === preg_match('~^<!--\s*/decision\s*-->$~', $literal)) {
                $markers[] = [$node, null];
            } elseif (1 === preg_match('~^<!--\s*options\s*-->$~', $literal)) {
                // A second opener means the first never closed: it is abandoned.
                $optionsOpen = [$node, []];
            } elseif (1 === preg_match('~^<!--\s*/options\s*-->$~', $literal) && null !== $optionsOpen && $node->parent() === $optionsOpen[0]->parent()) {
                $optionFences[] = [$optionsOpen[0], $node, $optionsOpen[1]];
                $optionsOpen = null;
            }
        }

        $this->sentinelPairedFences($markers);
        $this->sentinelOptionTables($optionFences);
    }

    /**
     * Marks each table of a paired options fence with a sentinel in front of it.
     *
     * A fence holding no table of two columns or more is left untouched, so both
     * of its markers show as the annotation a stray comment gets.
     *
     * @param list<array{HtmlBlock, HtmlBlock, list<Table>}> $fences
     */
    private function sentinelOptionTables(array $fences): void
    {
        foreach ($fences as [$opener, $closer, $tables]) {
            if ([] === $tables) {
                continue;
            }

            $opener->setLiteral($this->optionsFenceSentinel());
            $closer->setLiteral($this->optionsFenceSentinel());
            foreach ($tables as $table) {
                $sentinel = new HtmlBlock(HtmlBlock::TYPE_6_BLOCK_ELEMENT);
                $sentinel->setLiteral($this->optionTableSentinel());
                $table->insertBefore($sentinel);
            }
        }
    }

    private static function columnCount(Table $table): int
    {
        $section = $table->firstChild();
        $row = $section instanceof TableSection ? $section->firstChild() : null;

        return $row instanceof TableRow ? iterator_count($row->children()) : 0;
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
     * An opener and its closer are always written together, so the sentinels
     * strictly alternate. toControls() depends on that: it splits the HTML on
     * the close sentinel, which leaves at most one opener per segment.
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
     *
     * A single paragraph may precede the list and becomes the card's question.
     * It must not cross its own `</p>` — a plain `(.*?)</p>` may, by backtracking
     * to a later one — or a block holding two paragraphs folds both into one
     * legend that closes tags it never opened.
     */
    public function toControls(string $html): string
    {
        // Splitting on the close sentinel is what holds a match inside its own
        // block: bounding the body in the pattern spends a backtracking frame
        // per character and stops matching a few tens of KB in, past which no
        // block converts at all. The prompt has to stop at its own `</p>`, so it
        // stays in the pattern — possessive, or it reaches the same cliff.
        $block = '~'.$this->sentinelPrefix().'OPEN_('.self::ID_PATTERN.')_END\s*'
            .'(?:<p>([^<]*+(?:<(?!/p>)[^<]*+)*+)</p>\s*)?'
            .'(<(ul|ol)(?:\s[^>]*)?>)(.*)\z~s';

        $segments = explode($this->closeSentinel(), $html);
        // Whatever follows the last closer opened no block that ever closed.
        $tail = array_key_last($segments);

        foreach ($segments as $index => $segment) {
            if ($index === $tail) {
                break;
            }

            // Thrown per segment rather than collected: a later successful match
            // resets preg_last_error_msg(), so a deferred check reports nothing.
            $segments[$index] = preg_replace_callback(
                $block,
                function (array $matches): string {
                    $closeTag = '</'.$matches[4].'>';
                    // Exactly the `\s` the pattern used to consume here.
                    $rest = rtrim($matches[5], " \t\n\r\v\f");

                    // A block whose list is not the last thing in it stays prose.
                    if (!str_ends_with($rest, $closeTag)) {
                        return $matches[0];
                    }

                    $listHtml = substr($rest, 0, -\strlen($closeTag));

                    return $this->fieldset($matches[1], $listHtml, $matches[2])
                        ?? self::promptParagraph($matches[2]).$matches[3].$listHtml.$closeTag;
                },
                $segment,
            ) ?? throw new \RuntimeException('Decision control conversion failed: '.preg_last_error_msg().'.');
        }

        $html = $this->withOptionTables(implode($this->closeSentinel(), $segments));

        // Deliberately not the well-formed sentinel pattern: HtmlSanitizer cuts
        // its input with a raw substr() before parsing, so a large document can
        // lose the second half of a sentinel. Matching the nonce plus whatever
        // follows catches every cut after the nonce.
        $swept = preg_replace(
            '~'.$this->sentinelRoot().'[A-Za-z0-9_-]*~',
            '',
            $html,
        );

        return self::withoutTrailingPrefixOf(
            $swept ?? throw new \RuntimeException('Decision sentinel sweep failed: '.preg_last_error_msg().'.'),
            $this->sentinelRoot(),
        );
    }

    /**
     * Turns each sentinel-marked table into an option table.
     *
     * Only attributes are added, and strip_tags() drops those, so the text every
     * comment anchor is measured against stays what the browser reads. The
     * labels reach the eye through CSS generated content from `data-label`.
     * Tables never nest, so splitting on the closing tag leaves at most one
     * table in each segment.
     */
    private function withOptionTables(string $html): string
    {
        $sentinel = $this->optionTableSentinel();
        if (!str_contains($html, $sentinel)) {
            return $html;
        }

        $segments = explode('</table>', $html);
        $tail = array_key_last($segments);

        foreach ($segments as $index => $segment) {
            $start = strrpos($segment, $sentinel);
            if ($index === $tail || false === $start) {
                continue;
            }

            $table = substr($segment, $start + \strlen($sentinel));
            if (1 !== preg_match('~^\s*<table>~', $table, $opening)) {
                continue;
            }

            $segments[$index] = substr($segment, 0, $start)
                .'<table class="'.self::OPTION_TABLE_CLASS.'">'
                .self::withCellLabels(substr($table, \strlen($opening[0])));
        }

        return implode('</table>', $segments);
    }

    /** Adds the column heading to each body cell as `data-label`. */
    private static function withCellLabels(string $tableBody): string
    {
        $parts = explode('</thead>', $tableBody, 2);
        if (2 !== \count($parts)) {
            return $tableBody;
        }

        preg_match_all('~<th(?:\s[^>]*)?>([^<]*+(?:<(?!/th>)[^<]*+)*+)</th>~', $parts[0], $headings);
        $labels = array_map(DisplayLabel::fromHtml(...), $headings[1]);

        $rows = explode('</tr>', $parts[1]);
        foreach ($rows as $index => $row) {
            $column = 0;
            $rows[$index] = preg_replace_callback(
                '~<td(\s[^>]*)?>~',
                static function (array $matches) use ($labels, &$column): string {
                    $label = $labels[$column++] ?? '';
                    if ('' === $label) {
                        return $matches[0];
                    }

                    return substr($matches[0], 0, -1).' data-label="'.htmlspecialchars($label, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8').'">';
                },
                $row,
            ) ?? throw new \RuntimeException('Option table labelling failed: '.preg_last_error_msg().'.');
        }

        return $parts[0].'</thead>'.implode('</tr>', $rows);
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
        $decisions = [];
        foreach ($this->fieldsets($html) as $block) {
            preg_match_all('~<label[^>]*>(.*?)</label>~s', $block['inner'], $labels);

            $decisions[] = new Decision(
                $block['id'],
                // Shared with headings rather than stripping tags here: an option
                // written as an image alone reduced to '' under strip_tags(), so it
                // reached the agent as an empty string and two such options stored
                // the same label. DisplayLabel reads the `alt` instead.
                array_map(DisplayLabel::fromHtml(...), $labels[1]),
                self::promptOf($block['inner']),
                $block['type'],
            );
        }

        return $decisions;
    }

    /** The block's question, read from the legend fieldset() writes; '' when it declared none. */
    private static function promptOf(string $inner): string
    {
        if (1 !== preg_match('~<legend[^>]*>(.*?)</legend>~s', $inner, $matches)) {
            return '';
        }

        return DisplayLabel::fromHtml($matches[1]);
    }

    /** A version rendered before multi-choice existed carries no type, and takes one answer. */
    private static function typeOfOpenTag(string $openTag): DecisionType
    {
        if (1 !== preg_match('~\s'.self::TYPE_MARKER.'="([a-z]+)"~', $openTag, $matches)) {
            return DecisionType::Single;
        }

        return DecisionType::tryFrom($matches[1]) ?? DecisionType::Single;
    }

    /**
     * The text of the nearest heading above each block, keyed by decision id.
     *
     * A block with no heading above it is absent.
     *
     * @return array<string, string>
     */
    public function headingsAbove(string $html): array
    {
        preg_match_all('~<h[1-6](?:\s[^>]*)?>(.*?)</h[1-6]>~s', $html, $headings, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $found = [];
        foreach ($this->fieldsets($html) as $block) {
            $above = null;
            foreach ($headings as $heading) {
                if ($heading[0][1] > $block['offset']) {
                    break;
                }
                $above = $heading[1][0];
            }

            if (null !== $above) {
                $found[$block['id']] = DisplayLabel::fromHtml($above);
            }
        }

        return $found;
    }

    /**
     * Every decision fieldset in the rendered HTML, as id, inner markup and whole.
     *
     * Split on the closing tag rather than matched across it. A body written as
     * `(.*?)` costs a backtracking step per character, so one block long enough
     * to pass `pcre.backtrack_limit` made the match fail — and both readers took
     * the failure as "this document has no decisions", telling the agent nothing
     * was there while the page showed a full list. Splitting is safe because the
     * fieldsets emitted here are flat: fieldset() writes one per block and never
     * nests them.
     *
     * @return list<array{id: string, type: DecisionType, inner: string, offset: int}>
     */
    private function fieldsets(string $html): array
    {
        $found = [];
        $segmentOffset = 0;

        foreach (explode('</fieldset>', $html) as $segment) {
            $start = strrpos($segment, '<fieldset');
            $offset = $segmentOffset;
            $segmentOffset += \strlen($segment) + \strlen('</fieldset>');
            if (false === $start) {
                continue;
            }

            $element = substr($segment, $start);
            $matched = preg_match(
                '~^<fieldset[^>]*\s'.self::BLOCK_MARKER.'="('.self::ID_PATTERN.')"[^>]*>~',
                $element,
                $openTag,
            );
            if (1 !== $matched) {
                continue;
            }

            $found[] = [
                'id' => $openTag[1],
                'type' => self::typeOfOpenTag($openTag[0]),
                'inner' => substr($element, \strlen($openTag[0])),
                'offset' => $offset + $start,
            ];
        }

        return $found;
    }

    /** The DOM id a rendered block carries. */
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
     * The stored HTML is the same for every reader, so a recommendation badge
     * gets its accessible name here, in the reader's language. CSS shows it too.
     *
     * The note goes on the block as an attribute, never as text, for the same
     * reason. The page builds its note field from it.
     *
     * @param array<string, list<int>> $selectedIndexesByDecisionId
     * @param array<string, string>    $badgeLabels                 keyed by confidence
     * @param array<string, string>    $notesByDecisionId
     */
    public function withSelections(string $html, array $selectedIndexesByDecisionId, bool $readOnly, array $badgeLabels = [], array $notesByDecisionId = []): string
    {
        if ([] !== $notesByDecisionId) {
            $html = preg_replace_callback(
                '~<fieldset[^>]*\s'.self::BLOCK_MARKER.'="('.self::ID_PATTERN.')"[^>]*>~',
                static fn (array $matches): string => isset($notesByDecisionId[$matches[1]])
                    ? substr($matches[0], 0, -1).' data-decision-note="'.str_replace(
                        ["\r", "\n"],
                        ['&#13;', '&#10;'],
                        htmlspecialchars($notesByDecisionId[$matches[1]], \ENT_QUOTES | \ENT_HTML5),
                    ).'">'
                    : $matches[0],
                $html,
            ) ?? throw new \RuntimeException('Decision note marking failed: '.preg_last_error_msg().'.');
        }

        $html = $this->withBadgeLabels($html, $badgeLabels);

        $marked = preg_replace_callback(
            '~<input[^>]*\s'.self::OPTION_MARKER.'="('.self::ID_PATTERN.'):(\d+)"[^>]*>~',
            static function (array $matches) use ($selectedIndexesByDecisionId, $readOnly): string {
                $added = '';
                if (\in_array((int) $matches[2], $selectedIndexesByDecisionId[$matches[1]] ?? [], true)) {
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
     * Names each recommendation badge in the reader's language.
     *
     * @param array<string, string> $badgeLabels keyed by confidence
     */
    public function withBadgeLabels(string $html, array $badgeLabels): string
    {
        if ([] === $badgeLabels) {
            return $html;
        }

        return preg_replace_callback(
            '~<span class="lp-decision__badge" '.self::BADGE_MARKER.'="([a-z]+)"(?: tabindex="0")?>~',
            static function (array $matches) use ($badgeLabels): string {
                if (!isset($badgeLabels[$matches[1]])) {
                    return $matches[0];
                }

                $label = htmlspecialchars($badgeLabels[$matches[1]], \ENT_QUOTES | \ENT_HTML5);

                return substr($matches[0], 0, -1).' role="note" aria-label="'.$label.'" title="'.$label.'">';
            },
            $html,
        ) ?? throw new \RuntimeException('Decision badge labelling failed: '.preg_last_error_msg().'.');
    }

    /**
     * The badge names withSelections() takes, in the reader's language.
     *
     * @return array<string, string>
     */
    public static function badgeLabels(TranslatorInterface $translator): array
    {
        $labels = [];
        foreach (self::CONFIDENCES as $confidence) {
            $labels[$confidence] = $translator->trans('review.decision.recommended.'.$confidence);
        }

        return $labels;
    }

    /**
     * Three star elements with no text in them, `$filled` of them lit. CSS draws
     * each one, so nothing joins the anchor basis.
     */
    private static function stars(int $filled): string
    {
        $stars = '';
        for ($star = 1; $star <= self::STAR_COUNT; ++$star) {
            $stars .= sprintf('<span class="lp-decision__star%s"></span>', $star <= $filled ? ' lp-decision__star--filled' : '');
        }

        return $stars;
    }

    /**
     * The controls for one block, or null when the list cannot become one.
     *
     * A nested list is refused rather than converted: splitting on `<li>` would
     * cut through the inner list and emit unbalanced tags — into renderedHtml,
     * which is stored, so the browser and strip_tags() would then disagree about
     * the document's text and every anchor below it would be wrong.
     *
     * A list whose items disagree about their marker is refused for a different
     * reason: nothing here can tell which kind the author meant, and guessing
     * one records answers against a block they did not ask for.
     */
    private function fieldset(string $id, string $listHtml, string $promptHtml): ?string
    {
        if (1 === preg_match('~<(?:ul|ol)[\s>]~', $listHtml)) {
            return null;
        }

        preg_match_all('~<li>(.*?)</li>~s', $listHtml, $items);
        $texts = array_map(self::optionText(...), $items[1]);

        $type = self::typeOfItems($texts);
        if (null === $type) {
            return null;
        }

        $control = DecisionType::Multiple === $type ? 'checkbox' : 'radio';
        $marker = DecisionType::Multiple === $type ? self::MULTIPLE_ITEM_MARKER : self::SINGLE_ITEM_MARKER;

        $labels = array_map(static fn (string $text): string => trim((string) preg_replace($marker, '', $text)), $texts);
        $recommended = self::recommendedOption($labels);

        $options = '';
        foreach ($labels as $index => $label) {
            $optionId = self::OPTION_ID_PREFIX.$id.self::ID_SEPARATOR.$index;
            $badge = '';
            if (null !== $recommended && $recommended['index'] === $index) {
                $label = $recommended['label'];
                $badge = sprintf(
                    '<span class="lp-decision__badge" %s="%s" tabindex="0">%s</span>',
                    self::BADGE_MARKER,
                    $recommended['confidence'],
                    self::stars(self::STARS[$recommended['confidence']]),
                );
            }

            $options .= sprintf(
                '<div class="lp-decision__option"><input type="%s" name="%s%s" value="%d" id="%s" %s="%s:%d"><label for="%s">%s</label>%s</div>',
                $control,
                self::CONTROL_NAME_PREFIX,
                $id,
                $index,
                $optionId,
                self::OPTION_MARKER,
                $id,
                $index,
                $optionId,
                $label,
                $badge,
            );
        }

        return sprintf(
            '<fieldset class="lp-decision" id="%s%s" %s="%s" %s="%s"><legend class="lp-decision__prompt">%s</legend><div class="lp-decision__options">%s</div></fieldset>',
            self::BLOCK_ID_PREFIX,
            $id,
            self::BLOCK_MARKER,
            $id,
            self::TYPE_MARKER,
            $type->value,
            trim($promptHtml),
            $options,
        );
    }

    /**
     * The prompt as the document itself wrote it, for a block that stays prose.
     *
     * A refused block keeps its markup unchanged, and the paragraph is part of
     * that markup — dropping it here would delete a sentence the author wrote
     * from the rendered document.
     */
    private static function promptParagraph(string $promptHtml): string
    {
        return '' === trim($promptHtml) ? '' : '<p>'.$promptHtml.'</p>';
    }

    /**
     * One item's markup, with the paragraph a loose list wraps it in removed.
     *
     * A marker survives to here as literal text because TaskListExtension is off
     * — enabling it would delete those characters from plainText() in every
     * document, fence or not, and shift every anchor below the first task list.
     */
    private static function optionText(string $itemHtml): string
    {
        $text = trim($itemHtml);

        return trim((string) preg_replace('~^<p>(.*)</p>$~s', '$1', $text));
    }

    /**
     * The one option the author recommends, its label without the marker, and
     * the confidence. Null when no option or several carry a marker: two
     * recommendations contradict each other, so their text stays as written.
     *
     * @param list<string> $labels
     *
     * @return array{index: int, label: string, confidence: string}|null
     */
    private static function recommendedOption(array $labels): ?array
    {
        $found = null;
        foreach ($labels as $index => $label) {
            if (1 !== preg_match(self::RECOMMENDED_MARKER, $label, $matches)) {
                continue;
            }
            if (null !== $found) {
                return null;
            }

            $found = ['index' => $index, 'label' => $matches[1], 'confidence' => strtolower($matches[2])];
        }

        return $found;
    }

    /**
     * The kind of block these items ask for, or null when they disagree.
     *
     * A marker on every item picks the kind. A marker on none of them is the
     * shape every fence written before multi-choice used, so it stays a
     * single-choice block and its text is left alone.
     *
     * @param list<string> $texts
     */
    private static function typeOfItems(array $texts): ?DecisionType
    {
        $singles = 0;
        $multiples = 0;
        foreach ($texts as $text) {
            if (1 === preg_match(self::MULTIPLE_ITEM_MARKER, $text)) {
                ++$multiples;
            } elseif (1 === preg_match(self::SINGLE_ITEM_MARKER, $text)) {
                ++$singles;
            }
        }

        $count = \count($texts);
        if ($multiples === $count && $count > 0) {
            return DecisionType::Multiple;
        }

        return 0 === $multiples && (0 === $singles || $singles === $count) ? DecisionType::Single : null;
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

    private function optionsFenceSentinel(): string
    {
        return $this->sentinelPrefix().'OPTIONS_FENCE';
    }

    private function optionTableSentinel(): string
    {
        return $this->sentinelPrefix().'OPTIONS_TABLE';
    }

    private function openSentinel(string $id): string
    {
        return $this->sentinelPrefix().'OPEN_'.$id.'_END';
    }

    /** @return non-empty-string */
    private function closeSentinel(): string
    {
        return $this->sentinelPrefix().'CLOSE';
    }
}
