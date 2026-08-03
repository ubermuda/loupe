<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use League\CommonMark\Extension\FrontMatter\Data\FrontMatterDataParserInterface;
use League\CommonMark\Extension\FrontMatter\Exception\InvalidFrontMatterException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses a document's YAML front matter for {@see MarkdownRenderer}.
 *
 * Stands in for CommonMark's SymfonyYamlFrontMatterParser to pass
 * PARSE_DATETIME: without it `date: 2026-08-02` comes back as the integer
 * 1785628800 and the reviewer is shown that. Naming a parser at all also pins
 * the YAML implementation — the extension's default prefers ext-yaml whenever
 * it happens to be loaded, and ext-yaml is not a declared dependency, so the
 * same document could parse differently on two hosts running the same code.
 */
final readonly class FrontMatterYamlParser implements FrontMatterDataParserInterface
{
    /**
     * Ceiling on the raw block. Front matter is metadata; 16 KiB is several
     * times the largest realistic block, and it bounds parse cost, which grows
     * with source length.
     */
    private const int MAX_BYTES = 16_384;

    /**
     * A YAML anchor, alias or merge key in node position — the only constructs
     * that let one node be reused many times: `&a` labels a node, `*a` copies
     * it, `<<:` copies a whole mapping.
     *
     * Refusing them removes the reuse primitive outright, so nothing downstream
     * can be handed a structure that is small in memory but enormous when
     * walked. It is defence in depth rather than a fix for a live overflow:
     * measured, Yaml::parse() does not itself expand these — PHP arrays are
     * copy-on-write, so nine aliases of one node share its storage, and a
     * 12-level alias bomb parses in milliseconds with allocation growing
     * linearly (~400 bytes per level). The blow-up only happens when something
     * *traverses* that sharing, which is what BoundedHtmlBuilder bounds. This
     * check means a future traverser cannot reintroduce the problem.
     *
     * Deliberately narrow, since `&` and `*` are ordinary characters in prose.
     * A match needs the sigil in a value position (straight after `:`, `-`, `[`,
     * `{` or `,`), a name, and a delimiter after it. So `title: Rock & Roll`,
     * `R&D`, `?a=1&b=2` and a `*bold*` inside a block scalar all pass through;
     * a construction like `- *starred phrase*` does not, and takes the same
     * fallback malformed YAML takes — rendered as text, with a logged warning,
     * losing nothing.
     */
    private const string EXPANSION = '~^[ \t]*<<[ \t]*:|[:\-\[\{,][ \t]*[&*][\w.\-]+(?=[\s,\]\}]|$)~m';

    #[\Override]
    public function parse(string $frontMatter): mixed
    {
        // Both checks run on the raw text, before the parser is handed anything.
        if (\strlen($frontMatter) > self::MAX_BYTES) {
            throw new InvalidFrontMatterException(sprintf('Front matter is %d bytes, over the %d-byte limit.', \strlen($frontMatter), self::MAX_BYTES));
        }

        if (1 === preg_match(self::EXPANSION, $frontMatter)) {
            throw new InvalidFrontMatterException('Front matter uses a YAML anchor, alias or merge key.');
        }

        try {
            return Yaml::parse($frontMatter, Yaml::PARSE_DATETIME);
        } catch (ParseException $e) {
            throw InvalidFrontMatterException::wrap($e);
        }
    }
}
