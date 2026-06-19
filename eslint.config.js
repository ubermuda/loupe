// Root ESLint flat config.
//
// The project's main JS (assets/) is checked with Prettier and the e2e suite has
// its own config under e2e/. This root config exists to lint hand-written static
// assets served from public/ — notably the site-review annotation widget, which
// is a committed source file rather than build output. It is wired into the
// `lint` recipe in the justfile so `just ci` covers it.
const js = require('@eslint/js');
const globals = require('globals');

module.exports = [
    {
        files: ['public/site-review/widget.js'],
        ...js.configs.recommended,
        languageOptions: {
            ecmaVersion: 2022,
            // The widget relies on document.currentScript, which is null in a
            // module context, so it must be loaded as a classic script.
            sourceType: 'script',
            globals: {
                ...globals.browser,
            },
        },
    },
];
