import {
    defineConfig,
    devices,
    type PlaywrightTestConfig,
} from '@playwright/test';

// No default, deliberately. The `install-reset` project truncates every table,
// so the target is a destructive choice and guessing it wrongly costs a
// database. This used to fall back to the dev host, which meant running
// Playwright directly — a single spec, an IDE extension, `npx playwright test`
// — silently wiped the development data while `just e2e` looked fine, because
// only the recipe supplied the variable. Both recipes still do; anything else
// now has to say where it is aiming.
const baseURL = process.env.E2E_BASE_URL;

if (!baseURL) {
    throw new Error(
        'E2E_BASE_URL is not set, so there is no target to run against.\n' +
            'The suite truncates every table, so it will not pick one for you.\n' +
            'Use `just e2e` (the dedicated e2e target), or set E2E_BASE_URL explicitly.',
    );
}

// Per-request coverage collection takes one page render from about 0.5s to
// about 4.7s, measured with and without the X-Coverage header. Playwright's
// expect timeout is an absolute 5 seconds, so a coverage run sits on the bound
// and fails on timeouts rather than on anything a spec asserts. 20s is about
// four times the measured cost. The per-pull-request gate keeps 5s.
const collectingCoverage = !!process.env.COVERAGE;

// CI splits the suite over several jobs, each with its own stack, and
// E2E_SHARD names the part this process runs. Unset runs every project, which
// is what `just e2e` does on a workstation.
//
// Playwright's own `--shard` cannot split the whole suite. It filters top-level
// projects only, and it re-adds the dependency projects afterwards. It does
// split `chromium` per test when that project runs alone.
const shard = process.env.E2E_SHARD;
const shards = ['chromium', 'rest', 'global-flags'];

if (shard !== undefined && !shards.includes(shard)) {
    throw new Error(
        `E2E_SHARD must be 'chromium', 'rest' or 'global-flags', not '${shard}'.`,
    );
}

type Projects = NonNullable<PlaywrightTestConfig['projects']>;

// The chain orders the destructive projects within one run, and it never reads
// anything chromium or global-flags leaves behind. So a shard drops the projects
// it does not run from the chain, and waitlist still waits for admin.
function forShard(projects: Projects): Projects {
    if (shard === 'chromium' || shard === 'global-flags') {
        return projects
            .filter((p) => p.name === shard)
            .map((p) => ({ ...p, dependencies: undefined }));
    }

    if (shard !== 'rest') {
        return projects;
    }

    const elsewhere = ['chromium', 'global-flags'];

    return projects
        .filter((p) => !elsewhere.includes(p.name ?? ''))
        .map((p) => ({
            ...p,
            dependencies:
                p.name === 'waitlist'
                    ? ['admin']
                    : p.dependencies?.filter((d) => !elsewhere.includes(d)),
        }));
}

export default defineConfig({
    globalSetup: './global-setup.ts',
    testDir: './tests',
    fullyParallel: false,
    timeout: collectingCoverage ? 120_000 : 30_000,
    expect: { timeout: collectingCoverage ? 20_000 : 5_000 },
    // A spec that flips a global flag or shares a fixed account goes in a
    // `workers: 1` project below, never in `chromium`.
    workers: 4,
    forbidOnly: !!process.env.CI,
    retries: 0,
    // Stop at the first failure. Playwright skips a dependent project when its
    // dependency fails, so without this the run continues and reports "N did
    // not run" beside the failure, which reads as a deliberate skip: one red
    // test withheld three suites for hours and nobody noticed. The bound is per
    // process, so a sharded CI run reports at most one failure per shard.
    maxFailures: 1,
    reporter: [
        ['html', { open: 'never' }],
        // `just ci-report e2e-timing` reads this file from CI.
        ...(process.env.PLAYWRIGHT_JSON_OUTPUT_FILE ? [['json'] as const] : []),
    ],
    use: {
        baseURL,
        ignoreHTTPSErrors: true,
        trace: 'retain-on-failure',
        extraHTTPHeaders: {
            'X-Playwright': '1',
            // COVERAGE=1 makes the app collect per-request PHP coverage
            // (CoverageSubscriber from ubermuda/symfony-extra keys on this).
            ...(process.env.COVERAGE ? { 'X-Coverage': '1' } : {}),
        },
    },
    projects: forShard([
        {
            name: 'chromium',
            // Each test is its own unit, for workers and for `--shard`. A file
            // that relies on its test order opts out with
            // `test.describe.configure({ mode: 'default' })`.
            fullyParallel: true,
            // Specs that mutate state other files read run in the projects
            // below. Adding a spec here asserts that it is safe beside all of them.
            testIgnore: [
                /account\/waitlist\.spec\.ts/,
                /billing\/beta-invite\.spec\.ts/,
                /billing\/trial-end-lifecycle\.spec\.ts/,
                /install\/.*\.spec\.ts/,
                /board\/workshop\.spec\.ts/,
                /inbox\/.*\.spec\.ts/,
                /admin\/.*\.spec\.ts/,
                /billing\/paywall\.spec\.ts/,
                /account\/social-login\.spec\.ts/,
                /project\/search\.spec\.ts/,
                /project\/project-switcher\.spec\.ts/,
                /review\/mermaid-diagrams\.spec\.ts/,
                /review\/decision-live\.spec\.ts/,
            ],
            use: {
                ...devices['Desktop Chrome'],
            },
        },
        {
            name: 'admin',
            // Both specs register the one ADMIN_EMAIL account on first use.
            testMatch: /admin\/.*\.spec\.ts/,
            workers: 1,
            use: {
                ...devices['Desktop Chrome'],
            },
        },
        {
            name: 'global-flags',
            // billing.enabled, inbox.enabled, search.topbar.enabled,
            // review.mermaid.enabled, live_updates.enabled and the OAuth
            // provider flags change what signed-in pages and the login form
            // render, so nothing else runs beside these.
            testMatch: [
                /billing\/paywall\.spec\.ts/,
                /board\/workshop\.spec\.ts/,
                /account\/social-login\.spec\.ts/,
                /inbox\/.*\.spec\.ts/,
                /project\/search\.spec\.ts/,
                /project\/project-switcher\.spec\.ts/,
                /review\/mermaid-diagrams\.spec\.ts/,
                /review\/decision-live\.spec\.ts/,
            ],
            workers: 1,
            use: {
                ...devices['Desktop Chrome'],
            },
            dependencies: ['chromium', 'admin'],
        },
        {
            name: 'waitlist',
            testMatch: /account\/waitlist\.spec\.ts/,
            use: {
                ...devices['Desktop Chrome'],
            },
            dependencies: ['global-flags'],
        },
        {
            name: 'beta-invite',
            // Closes registration.cap, as the waitlist spec does.
            testMatch: /billing\/beta-invite\.spec\.ts/,
            workers: 1,
            use: {
                ...devices['Desktop Chrome'],
            },
            dependencies: ['waitlist'],
        },
        {
            name: 'trial-end-lifecycle',
            testMatch: /billing\/trial-end-lifecycle\.spec\.ts/,
            use: {
                ...devices['Desktop Chrome'],
            },
            // Serialized after beta-invite: mutates registration.cap AND
            // billing.enabled, and its sweep trigger disables every
            // expired-trial account in the database — nothing else may be
            // registering users or relying on billing being off while it
            // runs. For a targeted run of this spec alone, pass --no-deps to
            // skip the dependency chain.
            dependencies: ['beta-invite'],
        },
        {
            name: 'install-reset',
            testMatch: /install\/install\.spec\.ts/,
            // Strictly last in the chain: this project truncates every table,
            // so it must run after every other project — including
            // trial-end-lifecycle, whose fixture users and flag rows it would
            // otherwise destroy mid-run.
            dependencies: [
                'chromium',
                'admin',
                'global-flags',
                'waitlist',
                'beta-invite',
                'trial-end-lifecycle',
            ],
            use: {
                ...devices['Desktop Chrome'],
            },
        },
    ]),
});
