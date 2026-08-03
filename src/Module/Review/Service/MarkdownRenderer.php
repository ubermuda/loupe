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

    public function __construct()
    {
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
            // The sanitizer's default max input length is 20 000 bytes, beyond which it
            // silently truncates — long documents lost their tail at render time.
            ->withMaxInputLength(1_000_000)
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
        $frontMatter = null;
        $rendered = null;

        try {
            $rendered = $this->converter->convert($markdown);
            if ($rendered instanceof RenderedContentWithFrontMatter) {
                $data = $rendered->getFrontMatter();
                if (\is_array($data) && [] !== $data) {
                    $frontMatter = $data;
                }
            }
        } catch (InvalidFrontMatterException) {
        }

        // An opening `---` block fails to become a table when its YAML does not
        // parse, or parses to something other than a key/value map. The
        // extension has already lifted it out of the body by then, so rendering
        // again without it is what keeps the text on the page.
        if (null === $rendered || (null === $frontMatter && $rendered instanceof RenderedContentWithFrontMatter)) {
            $rendered = $this->plainConverter->convert($markdown);
        }

        $html = $this->withHeadingIds(
            $this->withDocumentNotes($this->sanitizer->sanitize($rendered->getContent())),
        );

        return null === $frontMatter ? $html : $this->frontMatterTable($frontMatter).$html;
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
            static fn (array $matches): string => 'block' === $matches[1]
                ? sprintf('<aside class="lp-doc-note">%s</aside>', $matches[2])
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
     * @param array<array-key, mixed> $frontMatter
     */
    private function frontMatterTable(array $frontMatter): string
    {
        $rows = '';
        foreach ($frontMatter as $key => $value) {
            // One tag per line, matching how CommonMark lays a content table
            // out. strip_tags() inserts nothing of its own, so without the
            // newlines every cell would run into the next one in plainText() —
            // the string every comment anchor is measured against.
            $rows .= sprintf(
                "<tr>\n<th scope=\"row\">%s</th>\n<td>%s</td>\n</tr>\n",
                self::escape((string) $key),
                self::escape(self::formatFrontMatterValue($value)),
            );
        }

        return sprintf("<table class=\"lp-front-matter\">\n<tbody>\n%s</tbody>\n</table>\n", $rows);
    }

    /**
     * Flattens one front-matter value to a single line of display text — a tag
     * list becomes one comma-separated row rather than a nested table a reviewer
     * would have to select across.
     */
    private static function formatFrontMatterValue(mixed $value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false'; // @translation-check-ignore
        }

        // Printed back the way front matter is normally written: a bare date
        // stays a date, and only a value that carried a time keeps one.
        if ($value instanceof \DateTimeInterface) {
            return '00:00:00' === $value->format('H:i:s')
                ? $value->format('Y-m-d')
                : $value->format('Y-m-d H:i:s');
        }

        if (\is_array($value)) {
            $parts = [];
            foreach ($value as $key => $item) {
                $formatted = self::formatFrontMatterValue($item);
                $parts[] = \is_int($key) ? $formatted : $key.': '.$formatted;
            }

            return implode(', ', $parts);
        }

        return \is_scalar($value) ? (string) $value : '';
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
