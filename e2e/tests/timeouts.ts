// Wrap every inline `{ timeout: ... }` that waits for something to appear.
//
// Per-request coverage collection takes one page render from about 0.5s to about
// 4.7s. An inline timeout is an absolute wall-clock bound, and the `expect`
// timeout in playwright.config.ts cannot reach it, so a coverage run fails on
// timeouts rather than on anything a spec asserts. A weekly coverage run lost 26
// specs that way. With COVERAGE unset this returns its argument unchanged, so the
// per-pull-request gate keeps its exact bounds.
//
// One kind of bound must stay a literal. A bound that asserts something is *fast*
// is not a bound that waits for something to appear, and scaling the first makes
// it pass for the wrong reason. `review-loop.spec.ts` holds a response for 1500ms
// and asserts the button disables within 1000ms, which proves the button does not
// wait for the server. At 4000ms the response arrives first and the assertion
// passes while testing nothing. Leave that shape alone.
const MULTIPLIER = process.env.COVERAGE ? 4 : 1;

export function coverageScaled(ms: number): number {
    return ms * MULTIPLIER;
}
