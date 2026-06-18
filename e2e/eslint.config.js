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
