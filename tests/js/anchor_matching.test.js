import { describe, expect, it } from 'vitest';
import {
    after,
    before,
    bestQuoteStart,
    FINGERPRINT,
} from '../../assets/lib/anchor_matching.js';

// One emoji is two UTF-16 code units, so a plain slice of n units returns n/2
// of these. Every window here is sized in codepoints instead.
const EMOJI = '😀';

describe('before', () => {
    it('returns the characters ending at the offset', () => {
        expect(before('abcdefgh', 6, 3)).toBe('def');
    });

    it('stops at the start of the text', () => {
        expect(before('abc', 2, 8)).toBe('ab');
    });

    it('returns nothing at offset zero', () => {
        expect(before('abc', 0, 8)).toBe('');
    });

    it('counts codepoints, not code units', () => {
        const text = EMOJI.repeat(10);
        expect(before(text, text.length, 8)).toBe(EMOJI.repeat(8));
    });

    it('never splits a surrogate pair', () => {
        const text = `${EMOJI.repeat(4)}tail`;
        expect(before(text, text.length, 6)).toBe(`${EMOJI.repeat(2)}tail`);
    });
});

describe('after', () => {
    it('returns the characters starting at the offset', () => {
        expect(after('abcdefgh', 2, 3)).toBe('cde');
    });

    it('stops at the end of the text', () => {
        expect(after('abc', 1, 8)).toBe('bc');
    });

    it('counts codepoints, not code units', () => {
        expect(after(EMOJI.repeat(10), 0, 8)).toBe(EMOJI.repeat(8));
    });

    it('never splits a surrogate pair', () => {
        expect(after(`head${EMOJI.repeat(4)}`, 0, 6)).toBe(
            `head${EMOJI.repeat(2)}`,
        );
    });
});

describe('bestQuoteStart', () => {
    it('refuses an empty quote', () => {
        expect(bestQuoteStart('a haystack', '', '', '')).toBeNull();
    });

    it('returns null when the quote is absent', () => {
        expect(bestQuoteStart('a haystack', 'needle', '', '')).toBeNull();
    });

    it('finds the only occurrence', () => {
        expect(bestQuoteStart('a needle here', 'needle', '', '')).toBe(2);
    });

    it('takes the earliest occurrence with no context to rank by', () => {
        expect(bestQuoteStart('cat and cat', 'cat', '', '')).toBe(0);
    });

    it('picks the occurrence the prefix points at', () => {
        const text = 'the black cat and the ginger cat';
        expect(bestQuoteStart(text, 'cat', 'the ginger ', '')).toBe(29);
    });

    it('picks the occurrence the suffix points at', () => {
        const text = 'cat sat, cat ran';
        expect(bestQuoteStart(text, 'cat', '', ' ran')).toBe(9);
    });

    it('prefers two matching sides over one', () => {
        const text = 'red fox jumps. blue fox jumps.';
        expect(bestQuoteStart(text, 'fox', 'blue ', ' jumps')).toBe(20);
    });

    it('keeps the earliest occurrence when the scores tie', () => {
        const text = 'a fox here, a fox here';
        expect(bestQuoteStart(text, 'fox', 'a ', ' here')).toBe(2);
    });

    it('ignores an empty prefix or suffix', () => {
        const text = 'one hit and one hit';
        expect(bestQuoteStart(text, 'hit', '', '')).toBe(4);
    });

    // The fingerprint is the tail of the prefix, so a prefix longer than
    // FINGERPRINT still matches on its last characters.
    it('matches on the tail of a long prefix', () => {
        const lead = 'x'.repeat(40);
        const text = `first cat. ${lead}second cat.`;
        expect(bestQuoteStart(text, 'cat', `${lead}second `, '')).toBe(
            text.lastIndexOf('cat'),
        );
    });

    // The prefix has to sit against the quote, not merely somewhere in the
    // window. The earlier occurrence here carries the same words further back,
    // so a containment test would score it and win the earliest-position tie.
    it('requires the prefix to touch the quote', () => {
        const text = 'marker zzz cat and marker cat';
        expect(bestQuoteStart(text, 'cat', 'marker ', '')).toBe(26);
    });

    // The same for the suffix, which has to start where the quote ends.
    it('requires the suffix to touch the quote', () => {
        const text = 'cat zzz marker. cat marker.';
        expect(bestQuoteStart(text, 'cat', '', ' marker')).toBe(16);
    });

    it('scores an astral prefix by codepoints', () => {
        const lead = EMOJI.repeat(FINGERPRINT);
        const text = `plain quote. ${lead}quote.`;
        expect(bestQuoteStart(text, 'quote', lead, '')).toBe(
            text.lastIndexOf('quote'),
        );
    });
});
