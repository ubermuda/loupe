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
    #[\Override]
    public function parse(string $frontMatter): mixed
    {
        try {
            return Yaml::parse($frontMatter, Yaml::PARSE_DATETIME);
        } catch (ParseException $e) {
            throw InvalidFrontMatterException::wrap($e);
        }
    }
}
