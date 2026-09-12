<?php

declare(strict_types=1);

namespace App\Tests\Changelog;

use PHPUnit\Framework\TestCase;

final class ChangelogScriptTest extends TestCase
{
    private const string BASELINE = <<<'MARKDOWN'
        ---
        title: "Changelog"
        ---

        ## [Unreleased]

        - (#418) — **Fixed:** an entry that was folded in earlier.

        MARKDOWN;

    private string $root;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir().'/loupe-changelog-'.bin2hex(random_bytes(6));
        mkdir($root.'/docs', 0o777, true);
        mkdir($root.'/changelog.d', 0o777, true);
        file_put_contents($root.'/docs/CHANGELOG.md', self::BASELINE);

        $this->root = $root;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/{docs,changelog.d}/*', \GLOB_BRACE) ?: [] as $path) {
            unlink($path);
        }

        rmdir($this->root.'/docs');
        rmdir($this->root.'/changelog.d');
        rmdir($this->root);
    }

    public function test_it_folds_fragments_in_with_the_newest_pull_request_first(): void
    {
        $this->writeFragment(429, '- (#429) — **Added:** the older pull request.');
        $this->writeFragment(430, '- (#430) — **Changed:** the newer pull request.');

        $result = $this->runScript();

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('#430, #429', $result['output']);
        self::assertSame(<<<'MARKDOWN'
            ---
            title: "Changelog"
            ---

            ## [Unreleased]

            - (#430) — **Changed:** the newer pull request.

            - (#429) — **Added:** the older pull request.

            - (#418) — **Fixed:** an entry that was folded in earlier.

            MARKDOWN, $this->changelog());
    }

    public function test_it_keeps_the_continuation_lines_of_an_entry(): void
    {
        $this->writeFragment(429, "- (#429) — **Added:** a first line.\n  A second line that belongs to it.");

        self::assertSame(0, $this->runScript()['status']);
        self::assertStringContainsString(
            "- (#429) — **Added:** a first line.\n  A second line that belongs to it.\n",
            $this->changelog(),
        );
    }

    public function test_it_deletes_only_the_fragments_it_folded_in(): void
    {
        $this->writeFragment(429, '- (#429) — **Added:** something.');
        file_put_contents($this->root.'/changelog.d/README.md', '# Changelog fragments');

        self::assertSame(0, $this->runScript()['status']);
        self::assertFileDoesNotExist($this->root.'/changelog.d/429.md');
        self::assertFileExists($this->root.'/changelog.d/README.md');
    }

    public function test_it_refuses_a_fragment_whose_anchor_is_not_its_file_name(): void
    {
        $this->writeFragment(429, '- (#428) — **Added:** an anchor from another pull request.');

        $result = $this->runScript();

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('anchors elsewhere than (#429)', $result['output']);
        self::assertSame(self::BASELINE, $this->changelog());
        self::assertFileExists($this->root.'/changelog.d/429.md');
    }

    public function test_it_refuses_an_entry_that_carries_no_tag(): void
    {
        $this->writeFragment(429, '- (#429) arbitrary text with no tag.');

        $result = $this->runScript();

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('an entry reads "- (#429) — **Added|Changed', $result['output']);
        self::assertSame(self::BASELINE, $this->changelog());
    }

    public function test_it_refuses_a_tag_that_keep_a_changelog_does_not_define(): void
    {
        $this->writeFragment(429, '- (#429) — **Typo:** a tag nobody defined.');

        self::assertSame(1, $this->runScript('--check')['status']);
    }

    public function test_it_accepts_every_tag_keep_a_changelog_defines(): void
    {
        $tags = ['Added', 'Changed', 'Deprecated', 'Removed', 'Fixed', 'Security'];

        foreach ($tags as $index => $tag) {
            $this->writeFragment(500 + $index, sprintf('- (#%d) — **%s:** an entry.', 500 + $index, $tag));
        }

        $result = $this->runScript('--check');

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('6 changelog fragment(s) read', $result['output']);
    }

    public function test_it_refuses_a_second_bullet_that_carries_no_anchor(): void
    {
        $this->writeFragment(429, "- (#429) — **Added:** a first entry.\n- a second bullet with no anchor.");

        self::assertSame(1, $this->runScript('--check')['status']);
    }

    public function test_it_refuses_prose_that_is_not_an_indented_continuation(): void
    {
        $this->writeFragment(429, "- (#429) — **Added:** a first entry.\n\nA paragraph at the left margin.");

        $result = $this->runScript('--check');

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('A paragraph at the left margin.', $result['output']);
    }

    public function test_it_refuses_a_fragment_that_does_not_start_with_an_entry(): void
    {
        $this->writeFragment(429, 'Added a thing.');

        $result = $this->runScript();

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('the first line must start "- (#429) "', $result['output']);
        self::assertSame(self::BASELINE, $this->changelog());
    }

    public function test_it_refuses_an_anchor_the_unreleased_section_already_carries(): void
    {
        $this->writeFragment(418, '- (#418) — **Fixed:** the same pull request twice.');

        $result = $this->runScript();

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('already appears under [Unreleased]', $result['output']);
        self::assertSame(self::BASELINE, $this->changelog());
        self::assertFileExists($this->root.'/changelog.d/418.md');
    }

    public function test_it_refuses_a_markdown_file_that_is_not_named_after_a_pull_request(): void
    {
        file_put_contents($this->root.'/changelog.d/43O.md', "- (#430) — **Added:** a mistyped file name.\n");

        $result = $this->runScript('--check');

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('name a fragment after its pull request', $result['output']);
    }

    public function test_it_refuses_a_file_name_that_carries_a_leading_zero(): void
    {
        file_put_contents($this->root.'/changelog.d/0429.md', "- (#429) — **Added:** something.\n");

        $result = $this->runScript();

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('name the fragment 429.md', $result['output']);
        self::assertSame(self::BASELINE, $this->changelog());
        self::assertFileExists($this->root.'/changelog.d/0429.md');
    }

    public function test_it_changes_nothing_when_there_is_no_fragment(): void
    {
        $result = $this->runScript();

        self::assertSame(0, $result['status']);
        self::assertStringContainsString('No changelog fragments to fold in.', $result['output']);
        self::assertSame(self::BASELINE, $this->changelog());
    }

    public function test_keep_writes_the_changelog_and_leaves_the_fragments(): void
    {
        $this->writeFragment(429, '- (#429) — **Added:** something.');
        file_put_contents($this->root.'/changelog.d/README.md', '# Changelog fragments');

        $result = $this->runScript('--keep');

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('and kept them: #429', $result['output']);
        self::assertStringContainsString('- (#429) — **Added:** something.', $this->changelog());
        self::assertFileExists($this->root.'/changelog.d/429.md');
        self::assertFileExists($this->root.'/changelog.d/README.md');
    }

    public function test_check_reads_the_fragments_and_writes_nothing(): void
    {
        $this->writeFragment(429, '- (#429) — **Added:** something.');

        $result = $this->runScript('--check');

        self::assertSame(0, $result['status'], $result['output']);
        self::assertStringContainsString('1 changelog fragment(s) read', $result['output']);
        self::assertSame(self::BASELINE, $this->changelog());
        self::assertFileExists($this->root.'/changelog.d/429.md');
    }

    public function test_check_reports_a_malformed_fragment(): void
    {
        $this->writeFragment(429, '- (#1) — **Added:** the wrong anchor.');

        self::assertSame(1, $this->runScript('--check')['status']);
    }

    private function writeFragment(int $number, string $text): void
    {
        file_put_contents(sprintf('%s/changelog.d/%d.md', $this->root, $number), $text."\n");
    }

    private function changelog(): string
    {
        $contents = file_get_contents($this->root.'/docs/CHANGELOG.md');
        self::assertIsString($contents);

        return $contents;
    }

    /**
     * @return array{status: int, output: string}
     */
    private function runScript(string ...$arguments): array
    {
        $command = sprintf(
            '%s %s --root=%s %s 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(\dirname(__DIR__, 2).'/bin/changelog.php'),
            escapeshellarg($this->root),
            implode(' ', array_map(escapeshellarg(...), $arguments)),
        );

        $lines = [];
        $status = 0;
        exec($command, $lines, $status);

        return ['status' => $status, 'output' => implode("\n", $lines)];
    }
}
