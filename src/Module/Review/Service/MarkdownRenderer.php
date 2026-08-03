<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
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
    private HtmlSanitizer $sanitizer;

    /**
     * Defaulted rather than required only so the many unit tests that build a
     * renderer directly keep working; the container injects the shared service,
     * so the app never runs two instances with two different nonces.
     */
    public function __construct(private DecisionBlockService $decisions = new DecisionBlockService())
    {
        $environment = new Environment(['html_input' => 'allow', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());
        $environment->addEventListener(DocumentParsedEvent::class, $this->decisions->markParsedDocument(...));
        $this->converter = new MarkdownConverter($environment);

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
            ->allowElement('cite')
            // A checkbox is the one form control documents use. `type` is forced
            // rather than allowed, because the sanitizer cannot constrain an
            // attribute's value and a document would otherwise render a password or
            // file field. `name`, `value` and `form` stay out, so a document cannot
            // smuggle a field into any form on the page.
            ->allowElement('input', ['type', 'checked', 'disabled'])
            ->forceAttribute('input', 'type', 'checkbox');

        // Every remaining W3C-safe element is blocked rather than dropped: the tag
        // and its attributes go, its text stays. Text is the basis
        // DocumentVersion::plainText() measures every comment anchor against, so an
        // element this list did not anticipate must not take a paragraph with it.
        //
        // This covers only names W3CReference knows. A tag outside it — `<foobar>`,
        // or an element a future Symfony release reclassifies as unsafe — is still
        // dropped with its text; MarkdownRendererTextBasisTest is what notices.
        //
        // `details`/`summary` are deliberately among the blocked, so a collapsible
        // section renders permanently open. A reviewer can anchor a comment to any
        // text in the document, and text that is collapsed by default would carry
        // comments nobody can see without knowing to expand it.
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
        return $this->withHeadingIds(
            $this->decisions->toControls(
                $this->sanitizer->sanitize($this->converter->convert($markdown)->getContent()),
            ),
        );
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
            static function (array $matches) use (&$used, &$nextSuffix): string {
                $base = self::slug($matches[2]);
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

    private static function slug(string $headingHtml): string
    {
        $text = html_entity_decode(strip_tags($headingHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $slug = trim((string) preg_replace('~[^\p{L}\p{N}]+~u', '-', $text), '-');

        return '' === $slug ? 'section' : mb_strtolower($slug);
    }
}
