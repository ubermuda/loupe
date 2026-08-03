<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * `TSMATCH(vector, terms)` — Postgres' `@@` against a websearch-parsed query.
 *
 * DQL has no operator syntax, so the result is a boolean the caller compares:
 * `TSMATCH(d.searchVector, :terms) = true`.
 *
 * `websearch_to_tsquery` rather than `to_tsquery` because the terms come
 * straight from a search box: it accepts quoted phrases, OR and leading `-`,
 * and — unlike `to_tsquery` — never raises a syntax error on whatever else a
 * reader types.
 */
final class TsMatchFunction extends FunctionNode
{
    private Node $vector;

    private Node $terms;

    #[\Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->vector = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->terms = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    #[\Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        return \sprintf(
            "(%s @@ websearch_to_tsquery('%s', %s))",
            $this->vector->dispatch($sqlWalker),
            FullTextSearch::CONFIGURATION,
            $this->terms->dispatch($sqlWalker),
        );
    }
}
