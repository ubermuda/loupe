<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Service;

use App\Module\Review\Service\AnchorService;
use App\Module\Review\ValueObject\Anchor;
use PHPUnit\Framework\TestCase;

final class AnchorServiceTest extends TestCase
{
    private AnchorService $service;

    protected function setUp(): void
    {
        $this->service = new AnchorService();
    }

    public function test_create_captures_quote_with_context(): void
    {
        $text = 'We will issue short-lived JWTs signed with a rotating key.';
        $start = strpos($text, 'short-lived JWTs');
        self::assertIsInt($start);
        $anchor = $this->service->create($text, $start, strlen('short-lived JWTs'));

        self::assertSame('short-lived JWTs', $anchor->quote);
        self::assertStringEndsWith('issue ', $anchor->prefix);
        self::assertStringStartsWith(' signed', $anchor->suffix);
        self::assertSame($start, $this->service->resolve($text, $anchor));
    }

    public function test_resolve_returns_null_when_quote_gone(): void
    {
        $text = 'We will issue short-lived JWTs.';
        $jwtsStart = strpos($text, 'JWTs');
        self::assertIsInt($jwtsStart);
        $anchor = $this->service->create($text, $jwtsStart, 4);

        self::assertNull($this->service->resolve('Totally different text.', $anchor));
    }

    public function test_resolve_prefers_match_nearest_offset_hint(): void
    {
        $text = 'token here ... token there';
        $secondStart = strrpos($text, 'token');
        self::assertIsInt($secondStart);
        $anchor = $this->service->create($text, $secondStart, 5);

        self::assertSame($secondStart, $this->service->resolve($text, $anchor));
    }

    public function test_resolve_prefers_context_match_over_closer_offset(): void
    {
        // Two "word" occurrences. The anchor carries the SECOND occurrence's context
        // ("a right " before, " too" after) but an offsetHint near the FIRST. Distance
        // alone would pick the first; the matching context must override it. If
        // contextScore were broken (always 0), this would resolve to the first match.
        $text = 'a wrong word here and a right word too';
        $secondStart = strrpos($text, 'word');
        self::assertIsInt($secondStart);

        $anchor = new Anchor('word', 'a right ', ' too', 8);

        self::assertSame($secondStart, $this->service->resolve($text, $anchor));
    }
}
