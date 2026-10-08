<?php

declare(strict_types=1);

namespace App\Tests\Agents;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class DesignSystemChangedScriptTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        $this->repo = sys_get_temp_dir().'/loupe-design-changed-'.bin2hex(random_bytes(6));
        mkdir($this->repo, 0o777, true);

        $this->git('init', '-q', '-b', 'main');
        $this->git('config', 'user.email', 'test@loupe.test');
        $this->git('config', 'user.name', 'Test');
        $this->git('config', 'commit.gpgsign', 'false');
        $this->write('README.md');
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'base');
        $this->git('checkout', '-q', '-b', 'feature');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->repo);
    }

    public function test_it_prints_only_design_system_paths(): void
    {
        foreach ([
            'assets/styles/tokens.css',
            'assets/styles/components/button.css',
            'templates/components/Ds/Button.html.twig',
            'src/Module/DesignSystem/Catalog.php',
            'assets/styles/app.css',
            'assets/styles/tokens.css.bak',
            'templates/components/Other/Thing.html.twig',
            'src/Module/DesignSystem/Other.php',
        ] as $path) {
            $this->write($path);
        }
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'change');

        $result = $this->script('main');

        self::assertSame(0, $result->getExitCode());
        self::assertSame(
            [
                'assets/styles/components/button.css',
                'assets/styles/tokens.css',
                'src/Module/DesignSystem/Catalog.php',
                'templates/components/Ds/Button.html.twig',
            ],
            $this->lines($result->getOutput()),
        );
    }

    public function test_it_prints_nothing_and_exits_zero_without_a_design_system_change(): void
    {
        $this->write('src/Other.php');
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'change');

        $result = $this->script('main');

        self::assertSame(0, $result->getExitCode());
        self::assertSame('', $result->getOutput());
    }

    public function test_it_ignores_a_change_that_is_already_on_the_base(): void
    {
        $this->git('checkout', '-q', 'main');
        $this->write('assets/styles/tokens.css');
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'main change');
        $this->git('checkout', '-q', 'feature');
        $this->write('src/Other.php');
        $this->git('add', '.');
        $this->git('commit', '-q', '-m', 'change');

        self::assertSame('', $this->script('main')->getOutput());
    }

    private function script(string $base): Process
    {
        $process = new Process([\dirname(__DIR__, 2).'/bin/agents/design-system-changed', $base], $this->repo);
        $process->run();

        return $process;
    }

    private function git(string ...$args): void
    {
        $process = new Process(['git', ...$args], $this->repo);
        $process->mustRun();
    }

    private function write(string $path): void
    {
        $file = $this->repo.'/'.$path;
        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0o777, true);
        }
        file_put_contents($file, $path."\n");
    }

    /**
     * @return list<string>
     */
    private function lines(string $output): array
    {
        $lines = array_filter(explode("\n", $output), static fn (string $line): bool => '' !== $line);
        sort($lines);

        return $lines;
    }
}
