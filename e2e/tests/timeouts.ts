// Scale inline waiting bounds with coverage, which cannot change them through config.
// Scale injected delays too when they must exceed the configured assertion timeout.
// Never scale a performance bound: that weakens the speed requirement being tested.
const MULTIPLIER = process.env.COVERAGE ? 4 : 1;

export function coverageScaled(ms: number): number {
    return ms * MULTIPLIER;
}
