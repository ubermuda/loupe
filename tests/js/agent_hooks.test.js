import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const hooks = fileURLToPath(new URL('../../.agents/hooks/', import.meta.url));

function decide(hook, command) {
    const run = spawnSync('bash', [hooks + hook], {
        input: JSON.stringify({ tool_input: { command } }),
        encoding: 'utf8',
    });
    expect(run.status).toBe(0);
    if (run.stdout === '') {
        return 'allow';
    }

    return JSON.parse(run.stdout).hookSpecificOutput.permissionDecision;
}

describe('no-full-ci.sh', () => {
    it.each([
        ['just ci', 'deny'],
        ['just cs ci', 'deny'],
        ['just cs && just ci', 'deny'],
        ['just cs\njust ci', 'deny'],
        ['just --justfile justfile ci', 'deny'],
        ['just -f justfile ci', 'deny'],
        ['just --set x y ci', 'deny'],
        ['FOO=1 just ci', 'deny'],
        ['just ci-report mutation', 'allow'],
        ['just cs', 'allow'],
        ['just lint', 'allow'],
        ['just phpstan', 'allow'],
        ['grep "just ci" AGENTS.md', 'allow'],
        ['echo just ci', 'allow'],
        ['just phpunit', 'deny'],
        ['just phpunit --testsuite tests', 'deny'],
        ['just phpunit tests/Module/Board', 'allow'],
        ['just phpunit tests/', 'deny'],
        ['just phpunit ./tests', 'deny'],
        ['just phpunit-coverage tests', 'deny'],
        ['just exec vendor/bin/phpunit', 'deny'],
        ['just exec env XDEBUG_MODE=off php vendor/bin/phpunit', 'deny'],
        ['just exec vendor/bin/phpunit tests/Module/Board', 'allow'],
        ['just exec bin/console cache:clear', 'allow'],
        ['env XDEBUG_MODE=off vendor/bin/phpunit', 'deny'],
        ['time just ci', 'deny'],
        ['env -u XDEBUG_MODE php vendor/bin/phpunit', 'deny'],
        ['time just phpunit --filter FooTest', 'allow'],
        ['nice -n 10 just ci', 'deny'],
        ['time -p just ci', 'deny'],
        ['timeout 1800 just ci', 'deny'],
        ['( just phpunit )', 'deny'],
        ['( cd .worktrees/x && just ci )', 'deny'],
        ['just phpunit --log-junit tests/results.xml', 'deny'],
        ['just phpunit --log-junit=var/junit.xml tests/Module/Board', 'allow'],
        ['just phpunit --testdox-summary tests/Module/Board', 'allow'],
        ['just phpunit --log-otr tests/report.xml', 'deny'],
        ['just phpunit tests/.', 'deny'],
        ['just phpunit-coverage tests/Module/..', 'deny'],
        ['just phpunit tests/../tests', 'deny'],
        ['just phpunit --coverage-openclover tests/report.xml', 'deny'],
        ['just phpunit --coverage-text tests/Module/Board', 'allow'],
        ['just phpunit --filter FooTest', 'allow'],
        ['just phpunit --filter=FooTest', 'allow'],
        ['just phpunit --group slow', 'allow'],
        ['just cs phpunit', 'deny'],
        ['vendor/bin/phpunit', 'deny'],
        ['./vendor/bin/phpunit', 'deny'],
        ['vendor/bin/phpunit tests/Module/Board/FooTest.php', 'allow'],
        ['php vendor/bin/phpunit', 'deny'],
        ['php vendor/bin/phpunit --filter X', 'allow'],
        [
            'bin/worktrees/compose-exec.sh env XDEBUG_MODE=off vendor/bin/phpunit',
            'deny',
        ],
        [
            'bin/worktrees/compose-exec.sh env XDEBUG_MODE=off vendor/bin/phpunit tests/Foo/BarTest.php',
            'allow',
        ],
        ['./bin/worktrees/compose-exec.sh php vendor/bin/phpunit', 'deny'],
        ['vendor/bin/paratest', 'deny'],
        ['./vendor/bin/paratest --processes=4', 'deny'],
        ['vendor/bin/paratest --processes=4 tests/Module/Board', 'allow'],
        [
            'bin/worktrees/compose-exec.sh env XDEBUG_MODE=off vendor/bin/paratest --processes=4',
            'deny',
        ],
        ['php vendor/bin/paratest --filter FooTest', 'allow'],
        ['just lint && just phpstan && just phpunit', 'deny'],
        ['just phpunit-coverage', 'deny'],
        ['just phpunit-coverage tests/X', 'allow'],
        ['just js-test', 'allow'],
        ['just js-test tests/js/agent_hooks.test.js', 'allow'],
    ])('%j is %s', (command, expected) => {
        expect(decide('no-full-ci.sh', command)).toBe(expected);
    });
});

describe('no-full-e2e.sh', () => {
    it.each([
        ['just e2e', 'deny'],
        ['just e2e tests/board/x.spec.ts', 'allow'],
        ['npx playwright test', 'deny'],
        ['npx playwright test tests/board/x.spec.ts', 'allow'],
        ['just e2e-up', 'allow'],
        ['just e2e-up && just e2e', 'deny'],
        ['just e2e-coverage', 'deny'],
        ['grep "just e2e" AGENTS.md', 'allow'],
    ])('%j is %s', (command, expected) => {
        expect(decide('no-full-e2e.sh', command)).toBe(expected);
    });
});
