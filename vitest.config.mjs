import { defineConfig } from 'vitest/config';

// `include` is explicit rather than left to the default `**/*.spec.ts`, which
// would collect every Playwright spec under e2e/ and run it with no browser.
export default defineConfig({
    test: {
        include: ['tests/js/**/*.test.js'],
        environment: 'node',
    },
});
