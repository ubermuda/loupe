import { describe, expect, it } from 'vitest';
import { format, summarise } from '../../bin/e2e-flake-report.mjs';

const run = (status) => ({
    projectName: 'chromium',
    results: [{ status }],
});

const report = {
    suites: [
        {
            title: 'a.spec.ts',
            file: 'a.spec.ts',
            specs: [
                {
                    title: 'steady',
                    file: 'a.spec.ts',
                    tests: [
                        run('passed'),
                        run('passed'),
                        run('passed'),
                        run('passed'),
                    ],
                },
                {
                    title: 'flaky',
                    file: 'a.spec.ts',
                    tests: [
                        run('passed'),
                        run('failed'),
                        run('timedOut'),
                        run('passed'),
                    ],
                },
                {
                    title: 'skipped',
                    file: 'a.spec.ts',
                    tests: [run('skipped')],
                },
            ],
            suites: [
                {
                    title: 'group',
                    file: 'a.spec.ts',
                    specs: [
                        {
                            title: 'steady',
                            file: 'a.spec.ts',
                            tests: [run('failed')],
                        },
                    ],
                },
            ],
        },
    ],
};

describe('e2e flake report', () => {
    it('counts runs and failures per test, worst first', () => {
        const rows = summarise(report);

        expect(rows.map((t) => [t.name, t.runs, t.failures])).toEqual([
            ['a.spec.ts > group > steady [chromium]', 1, 1],
            ['a.spec.ts > flaky [chromium]', 4, 2],
            ['a.spec.ts > steady [chromium]', 4, 0],
        ]);
        expect(rows[1].rate).toBe(0.5);
    });

    it('prints a rate and a total', () => {
        const text = format(summarise(report));

        expect(text).toContain('50.0%');
        expect(text).toContain('3 tests, 9 runs, 3 failures');
    });
});
