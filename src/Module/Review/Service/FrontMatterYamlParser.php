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
 *
 * Deliberately does NOT reject YAML anchors, aliases or `<<:` merge keys, which
 * looks like an obvious hardening and is not. Measured, Yaml::parse() does not
 * expand them: PHP arrays are copy-on-write, so nine aliases of one node share
 * its storage, and a 12-level alias bomb parses in milliseconds with allocation
 * growing linearly. The blow-up needs something to *traverse* that sharing, and
 * the only thing that traverses a parsed block is the front-matter table, which
 * BoundedHtmlBuilder already bounds. Refusing the sigils would prevent nothing
 * while degrading legitimate prose — `- *starred phrase*` inside a block scalar
 * is not an alias.
 */
final readonly class FrontMatterYamlParser implements FrontMatterDataParserInterface
{
    /**
     * Ceiling on the raw block. Front matter is metadata; 16 KiB is several
     * times the largest realistic block, and it bounds parse cost, which grows
     * with source length.
     */
    private const int MAX_BYTES = 16_384;

    #[\Override]
    public function parse(string $frontMatter): mixed
    {
        if (\strlen($frontMatter) > self::MAX_BYTES) {
            throw new InvalidFrontMatterException(sprintf('Front matter is %d bytes, over the %d-byte limit.', \strlen($frontMatter), self::MAX_BYTES));
        }

        try {
            return Yaml::parse($frontMatter, Yaml::PARSE_DATETIME);
        } catch (ParseException $e) {
            throw InvalidFrontMatterException::wrap($e);
        }
    }
}
