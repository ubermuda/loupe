import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const RULE =
    'The board must never reload as a whole. Update only the element that changed.';
const HINT =
    'A legitimate reload of another frame belongs outside the board files, or needs a narrower pattern here.';

function filesIn(directory, accept) {
    return readdirSync(join(ROOT, directory))
        .filter(accept)
        .map((name) => join(directory, name));
}

function twigFilesUnder(directory) {
    return readdirSync(join(ROOT, directory)).flatMap((name) => {
        const path = join(directory, name);
        if (statSync(join(ROOT, path)).isDirectory()) {
            return twigFilesUnder(path);
        }

        return name.endsWith('.twig') ? [path] : [];
    });
}

const GROUPS = [
    {
        name: 'board scripts',
        files: () => [
            ...filesIn('assets/controllers', (name) =>
                /^board_.*\.js$/.test(name),
            ),
            ...filesIn('assets/lib', (name) => /^board_.*\.js$/.test(name)),
        ],
        forbidden: [
            '.reload(',
            "dispatch('reload')",
            'dispatch("reload")',
            'Turbo.visit',
            'Turbo?.visit',
            "setAttribute('src'",
            'location.reload',
            'board-frame',
            'board-refresh',
        ],
        // The list view loads its own frame while a person shows it.
        allowed: ["this.listTarget.setAttribute('src'"],
    },
    {
        name: 'the card drawer',
        files: () => ['assets/controllers/card_drawer_controller.js'],
        forbidden: ['board-frame', 'board-refresh', 'board-live:reload'],
    },
    {
        name: 'board templates',
        files: () => twigFilesUnder('templates/Module/Board'),
        forbidden: [
            'id="board-frame"',
            'refresh="morph"',
            'board-refresh',
            'board-live:reload',
            'action="replace" target="board"',
        ],
    },
];

describe.each(GROUPS)('$name', ({ files, forbidden, allowed = [] }) => {
    it('scans at least one file', () => {
        expect(files().length).toBeGreaterThan(0);
    });

    it('holds no whole board reload', () => {
        const findings = files().flatMap((file) =>
            readFileSync(join(ROOT, file), 'utf8')
                .split('\n')
                .flatMap((line, index) =>
                    forbidden
                        .filter(
                            (token) =>
                                line.includes(token) &&
                                !allowed.some((exception) =>
                                    line.includes(exception),
                                ),
                        )
                        .map((token) => `${file}:${index + 1} holds ${token}`),
                ),
        );

        expect(findings, `${RULE}\n${HINT}`).toEqual([]);
    });
});
