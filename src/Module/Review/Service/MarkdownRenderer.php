<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
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
     * Ceiling on the HTML one front-matter block may produce.
     *
     * YAML aliases expand without a budget, so `a: &a [*b, *b, *b, …]` repeated
     * per level multiplies by nine each time. Measured unguarded: 409 bytes of
     * source became 27 MB of HTML in 9.5 s, and each further level costs another
     * 9x. Neither pre-existing guard helps — DocumentCreateTool::MAX_MARKDOWN_BYTES
     * caps the source and the growth is exponential in it, while the sanitizer's
     * own limit never sees this table, which is built outside it so that a
     * content table cannot carry a class. The parse is cheap (PHP shares the
     * aliased arrays); only flattening them is not. It also persists: the
     * expansion is stored, served, and redone by every re-render.
     *
     * 64 KiB leaves ~14x headroom over a deliberately extreme block (40 keys of
     * 20 words plus 50 tags) and ~116x over a rich Hugo-style page.
     */
    private const int FRONT_MATTER_MAX_OUTPUT = 65_536;

    /**
     * Ceiling on nodes visited while flattening, bounding traversal cost the way
     * FRONT_MATTER_MAX_OUTPUT bounds what a reader is served.
     *
     * Measured, either ceiling alone stops every bomb shape tried, because
     * whichever one latches first discards the whole table. This one is kept
     * anyway: without it, traversal is bounded only *indirectly*, by the
     * observation that sibling nodes emit a separator and only-child chains are
     * capped by FRONT_MATTER_MAX_DEPTH — which leaves traversal at up to depth x
     * output. Depending on that argument is what went wrong three times already;
     * a direct count costs an increment.
     */
    private const int FRONT_MATTER_MAX_VISITS = 65_536;

    /**
     * Ceiling on nesting depth, for a block that nests deeply rather than
     * broadly. Enforced inside BoundedHtmlBuilder::visit() with the other two:
     * checked here in the caller it once returned without latching, which stored
     * a table whose deep value silently rendered empty.
     */
    private const int FRONT_MATTER_MAX_DEPTH = 16;

    /**
     * `$decisions` is defaulted rather than required only so the many unit tests
     * that build a renderer directly keep working; the container injects the
     * shared service, so the app never runs two instances with two different
     * nonces.
     */
    public function __construct(
        private LoggerInterface $logger,
        private DecisionBlockService $decisions = new DecisionBlockService(),
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
            // The sanitizer truncates silently past this length, so any finite
            // value drops a long document's tail with no error — and can cut the
            // markers carrying an HTML comment's text, making the same source
            // render differently each time. The source is capped upstream.
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
            // Rendered for the anchor basis, not for looks: blocking it would leave
            // a bare text node inside <table>, which the HTML5 parser foster-parents
            // out to BEFORE the table. strip_tags() does not reorder, so PHP and the
            // browser would then read the document's text in different orders.
            ->allowElement('caption')
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
            // `class` carries the fenced-code info string as `language-<name>`, the
            // standard hook a syntax highlighter reads. Scoping it to <code> is not
            // enough on its own — CodeLanguageClassSanitizer constrains the value.
            ->allowElement('code', ['class'])
            ->withAttributeSanitizer(new CodeLanguageClassSanitizer())
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
            ->allowElement('cite');

        // Blocked, not dropped: the tag goes, its text stays. That text is the
        // basis plainText() measures every comment anchor against, so an element
        // this list did not anticipate must not take a paragraph with it.
        // `details`/`summary` are blocked deliberately, so a collapsed section
        // cannot hide comments anchored inside it.
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
                    $table = $this->frontMatterTable($data);
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

        // toControls() innermost, so the decision markup exists before notes and
        // heading ids are computed over it: an annotation written inside an
        // option is then converted within the label rather than left as a raw
        // marker, and a heading inside one is idded like any other.
        $html = $this->withHeadingIds(
            $this->withDocumentNotes(
                $this->decisions->toControls($this->sanitizer->sanitize($rendered->getContent())),
            ),
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

        // On both environments: the plain converter is the fallback for a
        // document whose front matter failed to tabulate. Parsing precedes
        // rendering, so a paired fence's markers are already sentinels by the
        // time the comment renderer sees them; an unpaired one surfaces as an
        // annotation, showing the author the marker they mistyped.
        $environment->addEventListener(DocumentParsedEvent::class, $this->decisions->markParsedDocument(...));

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
     *
     * Throws rather than returning $html unchanged, for the reason withHeadingIds()
     * throws and then some: the un-substituted string still holds the markers, so
     * a silent fallback would print a raw nonce on the page and make the same
     * source render differently on each attempt — the non-determinism
     * withMaxInputLength(-1) exists to prevent.
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
        ) ?? throw new \RuntimeException('Comment annotation pass failed: '.preg_last_error_msg().'.');
    }

    /**
     * Renders a parsed YAML front-matter block as a key/value table.
     *
     * Built outside the sanitizer, which would strip the class and leave this
     * indistinguishable from a table the document wrote. The distinction holds
     * both ways: `table` is allowed no attributes at all, so a content table can
     * never carry a class and can never be mistaken for this one.
     *
     * Returns null when the block crosses either ceiling, which is the caller's
     * signal to render the document without the extension.
     *
     * @param array<array-key, mixed> $frontMatter
     */
    private function frontMatterTable(array $frontMatter): ?string
    {
        $out = new BoundedHtmlBuilder(
            self::FRONT_MATTER_MAX_OUTPUT,
            self::FRONT_MATTER_MAX_VISITS,
            self::FRONT_MATTER_MAX_DEPTH,
        );

        // One tag per line, matching how CommonMark lays a content table out.
        // strip_tags() inserts nothing of its own, so without the newlines every
        // cell would run into the next one in plainText() — the string every
        // comment anchor is measured against.
        $out->append("<table class=\"lp-front-matter\">\n<tbody>\n");
        $out->each($frontMatter, function (int|string $key, mixed $value) use ($out): void {
            $out->visit(0);
            $out->append("<tr>\n<th scope=\"row\">");
            $out->appendText((string) $key);
            $out->append("</th>\n<td>");
            $this->appendFrontMatterValue($value, $out, 0);
            $out->append("</td>\n</tr>\n");
        });
        $out->append("</tbody>\n</table>\n");

        return $out->result();
    }

    /**
     * Flattens one front-matter value into $out — a tag list becomes one
     * comma-separated row rather than a nested table a reviewer would have to
     * select across.
     *
     * Writes straight into the builder rather than returning a string, so the
     * ceilings apply to the text as it is produced instead of to a finished
     * string that has already been built. Return values are not checked at every
     * call: the builder latches once a ceiling is crossed, so the remaining
     * traversal appends nothing and frontMatterTable() sees null either way.
     */
    private function appendFrontMatterValue(mixed $value, BoundedHtmlBuilder $out, int $depth): void
    {
        if (!$out->visit($depth)) {
            return;
        }

        if (\is_array($value)) {
            $first = true;
            $out->each($value, function (int|string $key, mixed $item) use ($out, $depth, &$first): void {
                if (!$first) {
                    $out->append(', ');
                }
                $first = false;
                if (!\is_int($key)) {
                    $out->appendText((string) $key);
                    $out->append(': ');
                }
                $this->appendFrontMatterValue($item, $out, $depth + 1);
            });

            return;
        }

        $out->appendText($this->scalarToText($value));
    }

    /** Renders one YAML scalar the way front matter is normally written. */
    private function scalarToText(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false'; // @translation-check-ignore
        }

        // A bare date stays a date; only a value that carried a time keeps one.
        if ($value instanceof \DateTimeInterface) {
            return '00:00:00' === $value->format('H:i:s')
                ? $value->format('Y-m-d')
                : $value->format('Y-m-d H:i:s');
        }

        return \is_scalar($value) ? (string) $value : '';
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
        // Where to resume suffixing a slug that has been seen before. Without it,
        // N headings sharing one slug rescan every earlier suffix — quadratic, and
        // a document may hold tens of thousands of headings.
        /** @var array<string, int> $nextSuffix */
        $nextSuffix = [];

        // Headings are allowed with no attributes, so a sanitized opening tag is
        // always exactly `<hN>`.
        $withIds = preg_replace_callback(
            '~<h([1-6])>(.*?)</h\1>~s',
            /**
             * @param array<int, string> $matches
             */
            function (array $matches) use (&$used, &$nextSuffix): string {
                $base = $this->slug($matches[2]);
                $slug = $base;
                $suffix = $nextSuffix[$base] ?? 1;
                // Still a scan, because a slug can also be taken by a DIFFERENT
                // heading whose own text slugged to `<base>-2`. Every step raises
                // the resume point permanently, so the total stays linear.
                while (isset($used[$slug])) {
                    $slug = $base.'-'.++$suffix;
                }
                $nextSuffix[$base] = $suffix;
                $used[$slug] = true;

                return sprintf(
                    '<h%1$s id="%2$s">%3$s</h%1$s>',
                    $matches[1],
                    self::HEADING_ID_PREFIX.$slug,
                    $matches[2],
                );
            },
            $html,
        );

        // Falling back to the un-idded HTML would store a version indistinguishable
        // from one rendered before ids existed: no table of contents, no error.
        return $withIds ?? throw new \RuntimeException('Heading id injection failed: '.preg_last_error_msg().'.');
    }

    private function slug(string $headingHtml): string
    {
        $slug = trim((string) preg_replace('~[^\p{L}\p{N}]+~u', '-', DisplayLabel::fromHtml($headingHtml)), '-');

        // A heading with no derivable label still needs an id, so in-page links and
        // structural positions keep working; the suffixing makes them distinct.
        return '' === $slug ? 'section' : mb_strtolower($slug);
    }
}
