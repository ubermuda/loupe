import { expect, it } from 'vitest';
import { COMPACT_QUERY, PHONE_QUERY } from '../../assets/lib/breakpoints.js';

it('puts the phone edge at the Tailwind sm width', () => {
    expect(PHONE_QUERY).toBe('(width < 40rem)');
});

it('puts the compact edge at the Tailwind lg width', () => {
    expect(COMPACT_QUERY).toBe('(width < 64rem)');
});
