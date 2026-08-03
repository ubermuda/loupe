<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * `TSRANK(vector, terms)` — Postgres' `ts_rank`, for ordering matches by how
 * well they match rather than by when they were created.
 *
 * The weight labels stored on the vector do the actual ranking; ts_rank's
 * default weight array already scores an `A` lexeme far above a `D` one.
 */
final class TsRankFunction extends FunctionNode
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
            "ts_rank(%s, websearch_to_tsquery('%s', %s))",
            $this->vector->dispatch($sqlWalker),
            FullTextSearch::CONFIGURATION,
            $this->terms->dispatch($sqlWalker),
        );
    }
}
