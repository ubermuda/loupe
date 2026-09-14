#!/usr/bin/env node
// Summarises a Playwright JSON report: wall time per project, and how many
// workers were busy over the run. One report per e2e shard, so it takes
// several. Usage: node bin/e2e-timing.mjs results.json [results.json ...]
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

function collectResults(suites, file, out) {
    for (const suite of suites ?? []) {
        const suiteFile = suite.file ?? file;
        for (const spec of suite.specs ?? []) {
            for (const test of spec.tests ?? []) {
                for (const result of test.results ?? []) {
                    const start = Date.parse(result.startTime);
                    out.push({
                        project: test.projectName,
                        file: spec.file ?? suiteFile,
                        slot: result.parallelIndex,
                        start,
                        end: start + result.duration,
                    });
                }
            }
        }
        collectResults(suite.suites, suiteFile, out);
    }

    return out;
}

export function summarise(report) {
    const runStart = Date.parse(report.stats.startTime);
    const wall = report.stats.duration;
    const results = collectResults(report.suites, undefined, []);

    const byProject = new Map();
    for (const r of results) {
        const p = byProject.get(r.project) ?? {
            name: r.project,
            tests: 0,
            files: new Set(),
            first: Infinity,
            last: -Infinity,
            testTime: 0,
        };
        p.tests += 1;
        p.files.add(r.file);
        p.first = Math.min(p.first, r.start);
        p.last = Math.max(p.last, r.end);
        p.testTime += r.end - r.start;
        byProject.set(r.project, p);
    }

    const projects = [...byProject.values()]
        .map((p) => ({
            name: p.name,
            tests: p.tests,
            files: p.files.size,
            startOffset: p.first - runStart,
            endOffset: p.last - runStart,
            span: p.last - p.first,
            testTime: p.testTime,
        }))
        .sort((a, b) => a.startOffset - b.startOffset);

    const events = results
        .flatMap((r) => [
            [r.start, 1],
            [r.end, -1],
        ])
        .sort((a, b) => a[0] - b[0]);
    const busy = new Map();
    let level = 0;
    let at = runStart;
    for (const [time, delta] of events) {
        busy.set(level, (busy.get(level) ?? 0) + Math.max(0, time - at));
        at = Math.max(at, time);
        level += delta;
    }
    busy.set(level, (busy.get(level) ?? 0) + Math.max(0, runStart + wall - at));

    return {
        wall,
        tests: results.length,
        workers: report.config?.workers,
        projects,
        busy: [...busy.entries()]
            .filter(([, ms]) => ms > 0)
            .sort((a, b) => a[0] - b[0])
            .map(([workers, ms]) => ({ workers, ms })),
    };
}

function clock(ms) {
    const s = Math.round(ms / 1000);

    return `${Math.floor(s / 60)}m${String(s % 60).padStart(2, '0')}s`;
}

export function format(summary) {
    const lines = [
        `${summary.tests} test results, wall ${clock(summary.wall)}, workers ${summary.workers}`,
        '',
        'project               tests  files   start     end    span  test time',
    ];
    for (const p of summary.projects) {
        lines.push(
            [
                p.name.padEnd(20),
                String(p.tests).padStart(6),
                String(p.files).padStart(6),
                clock(p.startOffset).padStart(7),
                clock(p.endOffset).padStart(7),
                clock(p.span).padStart(7),
                clock(p.testTime).padStart(10),
            ].join(' '),
        );
    }
    lines.push('', 'busy workers  time      share of wall');
    for (const b of summary.busy) {
        const share = ((100 * b.ms) / summary.wall).toFixed(1);
        lines.push(
            `${String(b.workers).padStart(12)}  ${clock(b.ms).padStart(7)}  ${share.padStart(6)}%`,
        );
    }
    lines.push(
        '',
        'Time between two tests on one worker, such as worker start-up, counts as idle.',
    );

    return lines.join('\n');
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
    const paths = process.argv.slice(2);
    if (paths.length === 0) {
        console.error('Usage: node bin/e2e-timing.mjs <playwright-results.json> [...]');
        process.exit(2);
    }
    // Each shard ran on its own runner against its own clock, so the reports
    // are summarised side by side rather than merged into one timeline.
    for (const [i, path] of paths.entries()) {
        if (paths.length > 1) {
            console.log(`${i > 0 ? '\n' : ''}=== ${path}\n`);
        }
        console.log(format(summarise(JSON.parse(readFileSync(path, 'utf8')))));
    }
}
