<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\FrontMatter\Exception\InvalidFrontMatterException;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Reference\W3CReference;

final readonly class MarkdownRenderer
{
    /**
     * Namespaces every computed heading id so a document can never mint one that
     * collides with an id the review page already uses (`composer-error` is a
     * plausible heading and a real Turbo stream target).
     */
    private const string HEADING_ID_PREFIX = 'heading-';

    private MarkdownConverter $converter;

    /** The same converter minus the front-matter extension, for a `---` block that cannot become a table. */
    private MarkdownConverter $plainConverter;

    private HtmlSanitizer $sanitizer;

    /**
     * Markers wrapped around an HTML comment's text so it crosses the sanitizer
     * as ordinary text (see {@see HtmlCommentNodeRenderer}). Their random
     * component is minted per instance and never leaves the process, so no
     * document can forge one and have its own prose promoted to an annotation.
     */
    private string $noteBlockOpen;
    private string $noteInlineOpen;
    private string $noteClose;

    /** Matches one wrapped comment, capturing block-vs-inline and the text. */
    private string $notePattern;

    /**
     * Ceiling on the text one front-matter block may flatten to, counted as it
     * is built so the work stops at the ceiling rather than after it.
     *
     * YAML aliases expand without a budget, so `a: &a [*b, *b, *b, …]` repeated
     * per level multiplies by nine each time. Measured unguarded: 409 bytes of
     * source became 27 MB of HTML in 9.5 s, and each further level costs another
     * 9x. Neither existing guard helps — DocumentCreateTool::MAX_MARKDOWN_BYTES
     * caps the source and the growth is exponential in it, while the sanitizer's
     * own limit never sees this table, which is built outside it so that a
     * content table cannot carry a class. The parse is cheap (PHP shares the
     * aliased arrays); only flattening them is not. It also persists: the
     * expansion is stored, served, and redone by every re-render.
     *
     * The budget is spent per node visited, not per character emitted, because
     * a bomb seeded with `[]` produces no text at all — charging only the
     * scalars would let its whole expanded tree be walked for free.
     *
     * 64 KiB leaves 14x headroom over a deliberately extreme block (40 keys of
     * 20 words plus 50 tags costs 4 566) and ~116x over a rich Hugo-style page.
     * Guarded, both bomb shapes stop tabulating at the same level and render in
     * under 0.07 s with memory flat as the level rises.
     */
    private const int FRONT_MATTER_TEXT_BUDGET = 65_536;

    /** Depth ceiling, for a block that nests deeply rather than broadly. */
    private const int FRONT_MATTER_MAX_DEPTH = 16;

    public function __construct(
        private LoggerInterface $logger,
    ) {
        $nonce = bin2hex(random_bytes(8));
        $this->noteBlockOpen = sprintf('[loupe-note-%s-block]', $nonce);
        $this->noteInlineOpen = sprintf('[loupe-note-%s-inline]', $nonce);
        $this->noteClose = sprintf('[/loupe-note-%s]', $nonce);
        // Hex digits and the literal parts are all regex-inert, so the markers
        // go in unquoted; `s` lets a multi-line comment match.
        $this->notePattern = sprintf('~\[loupe-note-%s-(block|inline)\](.*?)\[/loupe-note-%s\]~s', $nonce, $nonce);

        $this->converter = new MarkdownConverter($this->buildEnvironment(withFrontMatter: true));
        $this->plainConverter = new MarkdownConverter($this->buildEnvironment(withFrontMatter: false));

        // Every element is listed with exactly the attributes it needs, and never
        // layered over allowSafeElements(): allowElement() REPLACES an element's
        // attribute set rather than merging into it, so a bare allowElement('h2')
        // after that call silently revoked everything it had just granted.
        $config = new HtmlSanitizerConfig()
            // The sanitizer truncates silently past this length rather than
            // refusing, so any finite value drops a long document's tail with no
            // error. A finite value also cuts through the markers that carry an
            // HTML comment's text across (see withDocumentNotes), printing one on
            // the page and making the same source render differently each time.
            // The source is already capped by DocumentCreateTool::MAX_MARKDOWN_BYTES.
            ->withMaxInputLength(-1)
            // Headings are attribute-free on purpose: their ids are computed from
            // their own text after sanitization, see withHeadingIds().
            ->allowElement('h1')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('h4')
            ->allowElement('h5')
            ->allowElement('h6')
            ->allowElement('p')
            ->allowElement('blockquote')
            ->allowElement('pre')
            ->allowElement('hr')
            ->allowElement('br')
            ->allowElement('div')
            ->allowElement('span')
            ->allowElement('ul')
            // `start` is emitted by CommonMark for an ordered list that does not
            // begin at 1; without it the list silently renumbers from 1.
            ->allowElement('ol', ['start'])
            ->allowElement('li')
            ->allowElement('dl')
            ->allowElement('dt')
            ->allowElement('dd')
            ->allowElement('table')
            ->allowElement('thead')
            ->allowElement('tbody')
            ->allowElement('tfoot')
            ->allowElement('tr')
            // `align` is how the Table extension renders a column alignment marker
            // (`|:--|--:|`); colspan/rowspan/scope come from hand-written tables.
            ->allowElement('th', ['align', 'colspan', 'rowspan', 'scope'])
            ->allowElement('td', ['align', 'colspan', 'rowspan'])
            ->allowElement('a', ['href', 'title'])
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height'])
            // `class` carries the fenced-code info string as `language-<name>`,
            // the standard hook a syntax highlighter reads. Scoped to <code> so
            // document content cannot put the app's own classes on a container.
            ->allowElement('code', ['class'])
            ->allowElement('em')
            ->allowElement('strong')
            ->allowElement('i')
            ->allowElement('b')
            ->allowElement('s')
            ->allowElement('u')
            ->allowElement('del', ['datetime'])
            ->allowElement('ins', ['datetime'])
            ->allowElement('sub')
            ->allowElement('sup')
            ->allowElement('mark')
            ->allowElement('small')
            ->allowElement('abbr', ['title'])
            ->allowElement('kbd')
            ->allowElement('samp')
            ->allowElement('var')
            ->allowElement('q', ['cite'])
            ->allowElement('cite')
            // A checkbox is the one form control documents use, and it renders
            // nothing without these three. `name`, `value` and `form` stay out, so
            // a document cannot smuggle a field into any form on the page.
            ->allowElement('input', ['type', 'checked', 'disabled']);

        // Every remaining W3C-safe element is blocked rather than dropped: the tag
        // and its attributes go, its text stays. Text is the basis
        // DocumentVersion::plainText() measures every comment anchor against, so an
        // element this list did not anticipate must not take a paragraph with it.
        $rendered = $config->getAllowedElements();
        foreach (array_keys(array_filter(W3CReference::BODY_ELEMENTS)) as $element) {
            if (!isset($rendered[$element])) {
                $config = $config->blockElement($element);
            }
        }

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function render(string $markdown): string
    {
        $table = null;
        $rendered = null;
        $reason = null;

        try {
            $rendered = $this->converter->convert($markdown);
            if ($rendered instanceof RenderedContentWithFrontMatter) {
                $data = $rendered->getFrontMatter();
                // array_is_list() rejects a top-level sequence (`---\n- one\n- two\n---`),
                // which is an array but has no keys — tabulating it invents `0`
                // and `1` as if the document had written them.
                if (!\is_array($data) || [] === $data || array_is_list($data)) {
                    $reason = 'not a key/value map';
                } else {
                    $table = self::frontMatterTable($data);
                    $reason = null === $table ? 'expands past the size budget' : null;
                }
            }
        } catch (InvalidFrontMatterException $e) {
            $reason = 'unparseable YAML: '.$e->getMessage();
        }

        // Three ways an opening `---` block fails to become a table, and in all
        // of them the extension has already lifted it out of the body — so
        // rendering again without the extension is what keeps the text on the
        // page. Logged because a document that silently takes this path renders
        // fine and looks fine, and would otherwise be invisible in a batch run.
        if (null !== $reason) {
            $this->logger->warning('review.markdown.front_matter_not_tabulated', [
                'reason' => $reason,
                'markdown_bytes' => \strlen($markdown),
            ]);
        }

        if (null === $rendered || (null === $table && $rendered instanceof RenderedContentWithFrontMatter)) {
            $rendered = $this->plainConverter->convert($markdown);
        }

        $html = $this->withHeadingIds(
            $this->withDocumentNotes($this->sanitizer->sanitize($rendered->getContent())),
        );

        return ($table ?? '').$html;
    }

    private function buildEnvironment(bool $withFrontMatter): Environment
    {
        $environment = new Environment(['html_input' => 'allow', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());

        if ($withFrontMatter) {
            $environment->addExtension(new FrontMatterExtension(new FrontMatterYamlParser()));
        }

        // Outranks CommonMark's own HTML renderers, which stay registered
        // underneath and take over for anything that is not exactly a comment.
        $noteRenderer = new HtmlCommentNodeRenderer($this->noteBlockOpen, $this->noteInlineOpen, $this->noteClose);
        $environment->addRenderer(HtmlBlock::class, $noteRenderer, 10);
        $environment->addRenderer(HtmlInline::class, $noteRenderer, 10);

        return $environment;
    }

    /**
     * Replaces each sanitized comment marker with its visible annotation.
     *
     * Runs after sanitization for the same reason heading ids do: no element may
     * carry `class` except `<code>`, so every class on a container in the output
     * is one this class put there. It runs before withHeadingIds() so a comment
     * inside a heading counts as that heading's text when its id is computed.
     * The captured text went into the sanitizer escaped and comes back escaped.
     */
    private function withDocumentNotes(string $html): string
    {
        return preg_replace_callback(
            $this->notePattern,
            /** @param array<int, string> $matches */
            // role="note" rather than <aside>'s implicit "complementary": a
            // document with six markers would otherwise add six unnamed
            // landmarks to the page's landmark list.
            static fn (array $matches): string => 'block' === $matches[1]
                ? sprintf('<aside role="note" class="lp-doc-note">%s</aside>', $matches[2])
                : sprintf('<span class="lp-doc-note lp-doc-note--inline">%s</span>', $matches[2]),
            $html,
        ) ?? $html;
    }

    /**
     * Renders a parsed YAML front-matter block as a key/value table.
     *
     * Built outside the sanitizer, which would strip the class and leave this
     * indistinguishable from a table the document wrote. The distinction holds
     * both ways: `table` is allowed no attributes at all, so a content table can
     * never carry a class and can never be mistaken for this one.
     *
     * Returns null when the block expands past FRONT_MATTER_TEXT_BUDGET, which
     * is the caller's signal to render the document without the extension.
     *
     * @param array<array-key, mixed> $frontMatter
     */
    private static function frontMatterTable(array $frontMatter): ?string
    {
        $budget = self::FRONT_MATTER_TEXT_BUDGET;
        $rows = '';
        foreach ($frontMatter as $key => $value) {
            // One tag per line, matching how CommonMark lays a content table
            // out. strip_tags() inserts nothing of its own, so without the
            // newlines every cell would run into the next one in plainText() —
            // the string every comment anchor is measured against.
            // Keys are charged to the budget too: a `<<:` merge key can multiply
            // them the same way an alias multiplies values.
            $budget -= \strlen((string) $key) + 1;
            $formatted = self::formatFrontMatterValue($value, $budget);
            if ($budget < 0 || null === $formatted) {
                return null;
            }

            $rows .= sprintf(
                "<tr>\n<th scope=\"row\">%s</th>\n<td>%s</td>\n</tr>\n",
                self::escape((string) $key),
                self::escape($formatted),
            );
        }

        return sprintf("<table class=\"lp-front-matter\">\n<tbody>\n%s</tbody>\n</table>\n", $rows);
    }

    /**
     * Flattens one front-matter value to a single line of display text — a tag
     * list becomes one comma-separated row rather than a nested table a reviewer
     * would have to select across.
     *
     * Returns null once $budget is exhausted. The budget is decremented as the
     * value is walked rather than checked against the finished string, so an
     * aliased structure costs the budget and not what it would have expanded to.
     *
     * Every visit is charged, containers included. Charging only the scalars
     * leaves the same hole one level down: a bomb whose leaves are empty arrays
     * produces no text at all, so it would traverse all nine-to-the-nth expanded
     * nodes for free.
     */
    private static function formatFrontMatterValue(mixed $value, int &$budget, int $depth = 0): ?string
    {
        if (--$budget < 0 || $depth > self::FRONT_MATTER_MAX_DEPTH) {
            return null;
        }

        if (\is_array($value)) {
            $parts = [];
            foreach ($value as $key => $item) {
                $formatted = self::formatFrontMatterValue($item, $budget, $depth + 1);
                if (null === $formatted) {
                    return null;
                }
                $parts[] = \is_int($key) ? $formatted : $key.': '.$formatted;
            }

            return implode(', ', $parts);
        }

        if (\is_bool($value)) {
            return self::charge($budget, $value ? 'true' : 'false'); // @translation-check-ignore
        }

        // Printed back the way front matter is normally written: a bare date
        // stays a date, and only a value that carried a time keeps one.
        if ($value instanceof \DateTimeInterface) {
            return self::charge($budget, '00:00:00' === $value->format('H:i:s')
                ? $value->format('Y-m-d')
                : $value->format('Y-m-d H:i:s'));
        }

        return self::charge($budget, \is_scalar($value) ? (string) $value : '');
    }

    /**
     * Charges a flattened scalar's length to the budget, returning null once it
     * is spent. The visit itself was already charged by the caller, so this adds
     * only the text — which is what keeps a structure of empty strings finite.
     */
    private static function charge(int &$budget, string $text): ?string
    {
        $budget -= \strlen($text);

        return $budget < 0 ? null : $text;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Gives every heading a stable, unique id so a table of contents can link to it.
     *
     * Ids are added after sanitization and no element is allowed to carry `id`, so
     * every id on the page is one this method computed. Attributes never survive
     * strip_tags(), so DocumentVersion::plainText() — the basis every comment
     * anchor is measured against — is unchanged by this.
     */
    private function withHeadingIds(string $html): string
    {
        /** @var array<string, true> $used */
        $used = [];

        // Headings are allowed with no attributes, so a sanitized opening tag is
        // always exactly `<hN>`.
        return preg_replace_callback(
            '~<h([1-6])>(.*?)</h\1>~s',
            /** @param array<int, string> $matches */
            static function (array $matches) use (&$used): string {
                $slug = self::slug($matches[2]);
                $base = $slug;
                $suffix = 1;
                while (isset($used[$slug])) {
                    $slug = $base.'-'.++$suffix;
                }
                $used[$slug] = true;

                return sprintf(
                    '<h%1$s id="%2$s">%3$s</h%1$s>',
                    $matches[1],
                    self::HEADING_ID_PREFIX.$slug,
                    $matches[2],
                );
            },
            $html,
        ) ?? $html;
    }

    private static function slug(string $headingHtml): string
    {
        $text = html_entity_decode(strip_tags($headingHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $slug = trim((string) preg_replace('~[^\p{L}\p{N}]+~u', '-', $text), '-');

        return '' === $slug ? 'section' : mb_strtolower($slug);
    }
}
