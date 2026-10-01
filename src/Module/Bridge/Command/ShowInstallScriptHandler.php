<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Service\CliCompatibility;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Returns the CLI install script, with the instance URL and the CLI major version set under its shebang. */
final readonly class ShowInstallScriptHandler
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/cli/install.sh')]
        private string $path,
    ) {
    }

    public function __invoke(ShowInstallScriptCommand $command): string
    {
        $script = is_file($this->path) ? file_get_contents($this->path) : false;
        if (false === $script) {
            throw new \RuntimeException(sprintf('The install script "%s" cannot be read.', $this->path));
        }

        $shebang = strpos($script, "\n");
        if (false === $shebang) {
            throw new \RuntimeException(sprintf('The install script "%s" has no line after its shebang.', $this->path));
        }

        return substr($script, 0, $shebang + 1)
            .'LOUPE_URL='.self::quote($command->loupeUrl)."\n"
            .'LOUPE_CLI_MAJOR='.self::quote((string) CliCompatibility::major())."\n"
            .substr($script, $shebang + 1);
    }

    private static function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
