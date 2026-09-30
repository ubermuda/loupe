<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\ShowInstallScriptCommand;
use App\Module\Bridge\Command\ShowInstallScriptHandler;
use PHPUnit\Framework\TestCase;

final class ShowInstallScriptHandlerTest extends TestCase
{
    private string $path;

    #[\Override]
    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'install-script-') ?: throw new \RuntimeException('No temporary file.');
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_it_sets_the_instance_and_the_major_after_the_first_line(): void
    {
        file_put_contents($this->path, "#!/bin/sh\nset -eu\necho hi\n");

        $script = new ShowInstallScriptHandler($this->path)(new ShowInstallScriptCommand('https://loupe.example.com'));

        self::assertSame("#!/bin/sh\nLOUPE_URL='https://loupe.example.com'\nLOUPE_CLI_MAJOR='1'\nset -eu\necho hi\n", $script);
    }

    public function test_a_quote_in_the_url_cannot_end_the_string(): void
    {
        file_put_contents($this->path, "#!/bin/sh\necho hi\n");

        $script = new ShowInstallScriptHandler($this->path)(new ShowInstallScriptCommand("https://x.example/a'b"));

        self::assertStringContainsString("\nLOUPE_URL='https://x.example/a'\\''b'\n", $script);
    }

    public function test_a_missing_script_fails_loudly(): void
    {
        unlink($this->path);

        $this->expectException(\RuntimeException::class);

        new ShowInstallScriptHandler($this->path)(new ShowInstallScriptCommand('https://loupe.example.com'));
    }
}
