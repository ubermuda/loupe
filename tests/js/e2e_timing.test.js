import { describe, expect, it } from 'vitest';
import { format, summarise } from '../../bin/e2e-timing.mjs';

const T0 = Date.parse('2026-01-01T00:00:00.000Z');

function result(slot, startS, durationS) {
    return {
        parallelIndex: slot,
        startTime: new Date(T0 + startS * 1000).toISOString(),
        duration: durationS * 1000,
    };
}

function spec(file, projectName, results) {
    return { file, tests: [{ projectName, results }] };
}

// Two chromium slots run 0-10s, board runs 2-6s beside them, then a serial
// project runs alone 10-16s. The run ends at 20s, so 16-20s is idle.
const report = {
    config: { workers: 3 },
    stats: { startTime: new Date(T0).toISOString(), duration: 20_000 },
    suites: [
        {
            file: 'a.spec.ts',
            specs: [spec('a.spec.ts', 'chromium', [result(0, 0, 10)])],
            suites: [
                {
                    specs: [spec('a.spec.ts', 'chromium', [result(1, 0, 5)])],
                },
            ],
        },
        {
            file: 'b.spec.ts',
            specs: [
                spec('b.spec.ts', 'chromium', [result(1, 5, 5)]),
                spec('b.spec.ts', 'board', [result(2, 2, 4)]),
                spec('b.spec.ts', 'serial', [result(0, 10, 6)]),
            ],
        },
    ],
};

describe('summarise', () => {
    const summary = summarise(report);

    it('reports each project span and summed test time, in start order', () => {
        expect(summary.projects).toEqual([
            {
                name: 'chromium',
                tests: 3,
                files: 2,
                startOffset: 0,
                endOffset: 10_000,
                span: 10_000,
                testTime: 20_000,
            },
            {
                name: 'board',
                tests: 1,
                files: 1,
                startOffset: 2_000,
                endOffset: 6_000,
                span: 4_000,
                testTime: 4_000,
            },
            {
                name: 'serial',
                tests: 1,
                files: 1,
                startOffset: 10_000,
                endOffset: 16_000,
                span: 6_000,
                testTime: 6_000,
            },
        ]);
    });

    it('reports how long each number of workers was busy', () => {
        expect(summary.busy).toEqual([
            { workers: 0, ms: 4_000 },
            { workers: 1, ms: 6_000 },
            { workers: 2, ms: 6_000 },
            { workers: 3, ms: 4_000 },
        ]);
    });

    it('accounts for the whole wall time', () => {
        const total = summary.busy.reduce((sum, b) => sum + b.ms, 0);
        expect(total).toBe(report.stats.duration);
    });

    it('formats every project and busy level', () => {
        const text = format(summary);
        expect(text).toContain('5 test results, wall 0m20s, workers 3');
        expect(text).toMatch(/serial\s+1\s+1\s+0m10s\s+0m16s\s+0m06s\s+0m06s/);
        expect(text).toMatch(/3\s+0m04s\s+20\.0%/);
    });
});
