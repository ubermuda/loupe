#!/usr/bin/env node
// Counts, per test, how often it ran and how often it failed. Reads the
// Playwright JSON report of a `--repeat-each` run, where every repeat is its
// own entry. Usage: node bin/e2e-flake-report.mjs results.json [results.json ...]
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const FAILED = new Set(['failed', 'timedOut', 'interrupted']);

function collect(suites, file, titles, out) {
    for (const suite of suites ?? []) {
        const suiteFile = suite.file ?? file;
        // A file suite is titled with its path. A describe suite keeps its title.
        const path =
            !suite.title || suite.title === suite.file
                ? titles
                : [...titles, suite.title];
        for (const spec of suite.specs ?? []) {
            for (const test of spec.tests ?? []) {
                const last = test.results?.at(-1);
                if (!last || last.status === 'skipped') continue;
                out.push({
                    name: `${spec.file ?? suiteFile} > ${[...path, spec.title].join(' > ')} [${test.projectName}]`,
                    failed: FAILED.has(last.status),
                });
            }
        }
        collect(suite.suites, suiteFile, path, out);
    }

    return out;
}

export function summarise(report) {
    const byTest = new Map();
    for (const r of collect(report.suites, undefined, [], [])) {
        const t = byTest.get(r.name) ?? { name: r.name, runs: 0, failures: 0 };
        t.runs += 1;
        t.failures += r.failed ? 1 : 0;
        byTest.set(r.name, t);
    }

    return [...byTest.values()]
        .map((t) => ({ ...t, rate: t.failures / t.runs }))
        .sort((a, b) => b.rate - a.rate || a.name.localeCompare(b.name));
}

export function format(rows) {
    const lines = ['runs  failures    rate  test'];
    for (const t of rows) {
        lines.push(
            [
                String(t.runs).padStart(4),
                String(t.failures).padStart(9),
                `${(t.rate * 100).toFixed(1)}%`.padStart(7),
                ` ${t.name}`,
            ].join(' '),
        );
    }
    const runs = rows.reduce((n, t) => n + t.runs, 0);
    const failures = rows.reduce((n, t) => n + t.failures, 0);
    lines.push('', `${rows.length} tests, ${runs} runs, ${failures} failures`);

    return lines.join('\n');
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
    const paths = process.argv.slice(2);
    if (paths.length === 0) {
        console.error(
            'Usage: node bin/e2e-flake-report.mjs <playwright-results.json> [...]',
        );
        process.exit(2);
    }
    for (const path of paths) {
        console.log(format(summarise(JSON.parse(readFileSync(path, 'utf8')))));
    }
}
