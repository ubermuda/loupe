// e2e/eslint.config.js
const playwright = require('eslint-plugin-playwright');
const tsParser = require('@typescript-eslint/parser');

module.exports = [
    {
        ...playwright.configs['flat/recommended'],
        files: ['tests/**/*.spec.ts'],
        languageOptions: {
            ...playwright.configs['flat/recommended'].languageOptions,
            parser: tsParser,
        },
        rules: {
            ...playwright.configs['flat/recommended'].rules,
            'playwright/expect-expect': [
                'warn',
                {
                    // Custom assertion helpers whose expect() calls live
                    // inside the helper rather than inline in the test body.
                    assertFunctionNames: ['expectWaitlistStatus'],
                },
            ],
            'no-restricted-syntax': [
                'error',
                {
                    selector:
                        "CallExpression[callee.property.name='waitForURL']",
                    message:
                        'Use locator assertions or waitForLoadState() instead of page.waitForURL()',
                },
            ],
        },
    },
];
