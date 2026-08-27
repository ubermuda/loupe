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

        self::assertInstanceOf(Anchor::class, $anchor);
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

        self::assertInstanceOf(Anchor::class, $anchor);
        self::assertSame($secondStart, $anchor->offsetHint);
    }

    public function test_from_selection_returns_null_when_quote_is_not_found(): void
    {
        // Simulates the document having been revised in another tab between the
        // client capturing the selection and the comment being submitted: the
        // captured quote no longer appears anywhere in the current text.
        $text = 'Totally different text.';

        self::assertNull($this->service->fromSelection($text, 'short-lived JWTs', 'issue ', ' signed'));
    }

    public function test_from_quote_slices_the_context_the_caller_could_not_supply(): void
    {
        // An agent naming a passage has no selection and therefore no context. If
        // the empty strings it passes were stored as the anchor's context, both
        // resolvers would lose their only fingerprint for a repeated quote.
        $text = 'We will issue short-lived JWTs signed with a rotating key.';

        $anchor = $this->service->fromQuote($text, 'short-lived JWTs');

        self::assertNotNull($anchor);
        self::assertSame('short-lived JWTs', $anchor->quote);
        self::assertStringEndsWith('issue ', $anchor->prefix);
        self::assertStringStartsWith(' signed', $anchor->suffix);
        self::assertSame(mb_strpos($text, 'short-lived JWTs'), $anchor->offsetHint);
    }

    public function test_from_quote_produces_the_anchor_a_human_selection_would_have(): void
    {
        $text = 'We will issue short-lived JWTs signed with a rotating key.';
        $start = mb_strpos($text, 'short-lived JWTs');
        self::assertIsInt($start);

        $selected = $this->service->create($text, $start, mb_strlen('short-lived JWTs'));
        $quoted = $this->service->fromQuote($text, 'short-lived JWTs');

        self::assertEquals($selected, $quoted);
    }

    public function test_from_quote_lands_on_the_first_occurrence_of_a_repeated_passage(): void
    {
        // The caller has no context to disambiguate with, so the search falls back
        // to earliest position. Pinned because it is silent: a caller meaning the
        // second occurrence gets the first one and is told the call succeeded.
        $text = 'Rotate the key. Some other sentence. Rotate the key.';

        $anchor = $this->service->fromQuote($text, 'Rotate the key.');

        self::assertNotNull($anchor);
        self::assertSame(0, $anchor->offsetHint);
        // Context is still sliced from the document, so the anchor the browser
        // re-locates and the one stored here agree on which occurrence it is.
        self::assertSame('', $anchor->prefix);
        self::assertStringStartsWith(' Some other', $anchor->suffix);
    }

    public function test_from_quote_returns_null_when_the_passage_is_not_in_the_text(): void
    {
        // What an agent quoting its Markdown source hits: the rendered plain text
        // has no asterisks, so the quote is nowhere to be found.
        self::assertNull($this->service->fromQuote('We will rotate the key.', '**rotate**'));
    }

    public function test_from_quote_matches_a_passage_the_source_wrapped_across_lines(): void
    {
        // Markdown here is written at 80 columns and CommonMark keeps those
        // newlines inside the paragraph, so a caller quoting what it read on the
        // rendered page sends a space wherever the author's source wrapped.
        $text = "Until the server says which of\nthree things went wrong, nothing moves.";

        $anchor = $this->service->fromQuote($text, 'Until the server says which of three things went wrong');

        self::assertNotNull($anchor);
        // The stored quote is the verbatim span, newline included — the browser
        // searches its own textContent for exactly this string.
        self::assertSame("Until the server says which of\nthree things went wrong", $anchor->quote);
        self::assertSame(0, $anchor->offsetHint);
    }

    public function test_from_quote_tolerates_whitespace_the_caller_introduced_too(): void
    {
        $anchor = $this->service->fromQuote('We rotate the key hourly.', "rotate\n   the  key");

        self::assertNotNull($anchor);
        self::assertSame('rotate the key', $anchor->quote);
        self::assertSame(3, $anchor->offsetHint);
    }

    public function test_from_quote_offset_hint_counts_characters_not_bytes_across_a_wrap(): void
    {
        // An emoji costs four bytes and one character, so a hint left in byte space
        // would point past the span the browser walks to.
        $text = "Ship it 🚀 — until the server says which of\nthree things went wrong.";
        $expected = mb_strpos($text, 'until the server');
        self::assertIsInt($expected);

        $anchor = $this->service->fromQuote($text, 'until the server says which of three things');

        self::assertNotNull($anchor);
        self::assertSame($expected, $anchor->offsetHint);
        self::assertSame("until the server says which of\nthree things", $anchor->quote);
    }

    public function test_from_quote_prefers_the_earliest_span_however_it_was_wrapped(): void
    {
        // The wrapped occurrence comes first, so it wins over the later one that
        // happens to match the quote character for character.
        $text = "rotate\nthe key later; rotate the key.";

        $anchor = $this->service->fromQuote($text, 'rotate the key');

        self::assertNotNull($anchor);
        self::assertSame(0, $anchor->offsetHint);
        self::assertSame("rotate\nthe key", $anchor->quote);
    }

    public function test_from_quote_does_not_bridge_words_the_text_never_ran_together(): void
    {
        // Collapsing whitespace runs must not become "ignore whatever sits between
        // the words" — the segments still have to be adjacent in the text.
        self::assertNull($this->service->fromQuote('rotate the master key', 'rotate the key'));
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
        $start = mb_strpos($text, $quote);
        self::assertIsInt($start);

        $anchor = $this->service->fromSelection($text, $quote, 'Goal: ', '.');

        self::assertInstanceOf(Anchor::class, $anchor);
        self::assertSame($quote, $anchor->quote);
        self::assertSame($start, $anchor->offsetHint);
        self::assertSame($start, $this->service->resolve($text, $anchor));
    }

    public function test_create_keeps_prefix_valid_utf8_over_multibyte_context(): void
    {
        // The em dash (3 bytes) sits inside the prefix window. A byte-counting
        // window would end mid-character and keep a dangling 0x80 continuation
        // byte, which fails to persist into a UTF8 column (Postgres SQLSTATE[22021]).
        $text = str_repeat('a', 7).'—'.str_repeat('b', 30).'TARGET'.str_repeat('c', 5);
        $start = mb_strpos($text, 'TARGET');
        self::assertIsInt($start);

        $anchor = $this->service->create($text, $start, 6);

        self::assertSame('TARGET', $anchor->quote);
        self::assertTrue(mb_check_encoding($anchor->prefix, 'UTF-8'), 'prefix must be valid UTF-8');
        self::assertSame($start, $this->service->resolve($text, $anchor));
    }

    public function test_create_keeps_suffix_valid_utf8_over_multibyte_context(): void
    {
        // The em dash sits at the far edge of the suffix window.
        $text = 'TARGET'.str_repeat('c', 31).'—'.str_repeat('d', 5);

        $anchor = $this->service->create($text, 0, 6);

        self::assertSame('TARGET', $anchor->quote);
        self::assertTrue(mb_check_encoding($anchor->suffix, 'UTF-8'), 'suffix must be valid UTF-8');
    }

    public function test_locate_ranks_repeated_multibyte_quote_like_the_browser(): void
    {
        // "設計" appears twice with identical 3-character context on either side,
        // so a fingerprint measured in BYTES matches both and ties to the earlier.
        // Measured in CHARACTERS it reaches far enough out for them to differ.
        $text = '甲乙丙丁戊ののの設計ををを己庚辛壬癸。子丑寅卯辰ののの設計ををを午未申酉戌';
        $secondStart = mb_strrpos($text, '設計');
        self::assertSame(27, $secondStart);

        // What #extractAnchor would capture for the second occurrence: 32 characters
        // of context on each side, sliced out of the same text.
        $prefix = mb_substr($text, max(0, $secondStart - 32), min(32, $secondStart));
        $suffix = mb_substr($text, $secondStart + 2, 32);

        $anchor = $this->service->fromSelection($text, '設計', $prefix, $suffix);

        self::assertInstanceOf(Anchor::class, $anchor);
        self::assertSame(27, $anchor->offsetHint);
        self::assertSame(
            self::browserRangeStart($text, '設計', $prefix, $suffix),
            $anchor->offsetHint,
            'server and browser must agree on which occurrence the anchor points at',
        );
    }

    public function test_create_captures_the_same_context_window_the_browser_sends(): void
    {
        // Forty multibyte characters on each side of the quote, so a 32-BYTE window
        // would cover only ten of them while the browser always sends 32.
        $text = str_repeat('あ', 40).'TARGET'.str_repeat('い', 40);
        $start = mb_strpos($text, 'TARGET');
        self::assertIsInt($start);

        // The add-comment path: the browser slices 32 characters either side.
        $captured = $this->service->fromSelection(
            $text,
            'TARGET',
            mb_substr($text, $start - 32, 32),
            mb_substr($text, $start + 6, 32),
        );
        // The reanchoring path: the server rebuilds the anchor for the same span.
        $rebuilt = $this->service->create($text, $start, 6);

        self::assertInstanceOf(Anchor::class, $captured);
        self::assertSame(32, mb_strlen($rebuilt->prefix));
        self::assertSame(32, mb_strlen($rebuilt->suffix));
        self::assertSame($captured->prefix, $rebuilt->prefix);
        self::assertSame($captured->suffix, $rebuilt->suffix);
    }

    /**
     * Transcription of comment_anchor_controller's #findRange ranking: collect every
     * occurrence of the quote (advancing one character at a time so overlaps count),
     * score each by whether the 32 characters before it end with the last 8 characters
     * of the prefix and the 32 characters after it start with the first 8 of the
     * suffix, then take the highest score, earliest position winning a tie.
     *
     * PHP counts codepoints where JS counts UTF-16 code units; the two agree for
     * every character in the Basic Multilingual Plane, which is what this covers.
     */
    private static function browserRangeStart(string $fullText, string $quote, string $prefix, string $suffix): ?int
    {
        $occurrences = [];
        $from = 0;
        while (false !== ($at = mb_strpos($fullText, $quote, $from))) {
            $occurrences[] = $at;
            $from = $at + 1;
        }
        if ([] === $occurrences) {
            return null;
        }

        $score = static function (int $start) use ($fullText, $quote, $prefix, $suffix): int {
            $value = 0;
            $before = mb_substr($fullText, max(0, $start - 32), min(32, $start));
            $after = mb_substr($fullText, $start + mb_strlen($quote), 32);
            if ('' !== $prefix && str_ends_with($before, mb_substr($prefix, -8))) {
                ++$value;
            }
            if ('' !== $suffix && str_starts_with($after, mb_substr($suffix, 0, 8))) {
                ++$value;
            }

            return $value;
        };
        usort($occurrences, static fn (int $a, int $b): int => $score($b) <=> $score($a) ?: $a <=> $b);

        return $occurrences[0];
    }
}
