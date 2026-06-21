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

    public function test_from_selection_locates_exact_quote_and_sets_offset_hint(): void
    {
        $text = 'We will issue short-lived JWTs signed with a rotating key.';
        $start = strpos($text, 'short-lived JWTs');
        self::assertIsInt($start);

        $anchor = $this->service->fromSelection($text, 'short-lived JWTs', 'issue ', ' signed');

        self::assertSame('short-lived JWTs', $anchor->quote);
        self::assertSame($start, $anchor->offsetHint);
        self::assertSame($start, $this->service->resolve($text, $anchor));
    }

    public function test_from_selection_uses_context_to_disambiguate_repeats(): void
    {
        // "word" appears twice; the captured context belongs to the second one.
        $text = 'a wrong word here and a right word too';
        $secondStart = strrpos($text, 'word');
        self::assertIsInt($secondStart);

        $anchor = $this->service->fromSelection($text, 'word', 'a right ', ' too');

        self::assertSame($secondStart, $anchor->offsetHint);
    }

    public function test_unanchored_yields_empty_anchor(): void
    {
        $anchor = Anchor::unanchored();

        self::assertSame('', $anchor->quote);
        self::assertSame('', $anchor->prefix);
        self::assertSame('', $anchor->suffix);
        self::assertSame(0, $anchor->offsetHint);
    }

    public function test_from_selection_stores_exact_quote_after_multibyte_char(): void
    {
        // Regression: the old offset-based path drifted after a multibyte char
        // (JS UTF-16 code units vs PHP byte offsets), storing a garbled quote.
        // fromSelection takes the verbatim string, so a quote sitting after an
        // em dash is stored and located exactly.
        $text = 'Redesign — Implementation Plan. Goal: replace the top-nav shell.';
        $quote = 'replace the top-nav shell';
        $start = strpos($text, $quote);
        self::assertIsInt($start);

        $anchor = $this->service->fromSelection($text, $quote, 'Goal: ', '.');

        self::assertSame($quote, $anchor->quote);
        self::assertSame($start, $anchor->offsetHint);
        self::assertSame($start, $this->service->resolve($text, $anchor));
    }

    public function test_create_keeps_prefix_valid_utf8_when_window_splits_multibyte_char(): void
    {
        // The em dash (3 bytes) sits so the 32-byte prefix window's left edge lands
        // mid-character. A raw substr would keep a dangling 0x80 continuation byte,
        // which then fails to persist into a UTF8 column (Postgres SQLSTATE[22021]).
        $text = str_repeat('a', 7).'—'.str_repeat('b', 30).'TARGET'.str_repeat('c', 5);
        $start = strpos($text, 'TARGET');
        self::assertIsInt($start);

        $anchor = $this->service->create($text, $start, 6);

        self::assertSame('TARGET', $anchor->quote);
        self::assertTrue(mb_check_encoding($anchor->prefix, 'UTF-8'), 'prefix must be valid UTF-8');
        self::assertSame($start, $this->service->resolve($text, $anchor));
    }

    public function test_create_keeps_suffix_valid_utf8_when_window_splits_multibyte_char(): void
    {
        // The em dash sits so the 32-byte suffix window's right edge cuts it.
        $text = 'TARGET'.str_repeat('c', 31).'—'.str_repeat('d', 5);

        $anchor = $this->service->create($text, 0, 6);

        self::assertSame('TARGET', $anchor->quote);
        self::assertTrue(mb_check_encoding($anchor->suffix, 'UTF-8'), 'suffix must be valid UTF-8');
    }
}
