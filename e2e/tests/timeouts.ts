// Wrap every inline `{ timeout: N }` that waits for something to appear. Coverage
// takes one page render from about 0.5s to 4.7s, and playwright.config.ts cannot
// reach an inline bound. COVERAGE unset returns N unchanged, so the gate keeps
// its exact bounds. Never wrap a bound asserting something is *fast*, such as the
// 1000ms in review-loop.spec.ts: scaling it makes it pass for the wrong reason.
const MULTIPLIER = process.env.COVERAGE ? 4 : 1;

export function coverageScaled(ms: number): number {
    return ms * MULTIPLIER;
}
