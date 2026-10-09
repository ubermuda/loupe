<?php

declare(strict_types=1);

namespace App\Module\DesignSystem\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfonycasts\TailwindBundle\TailwindBuilder;

/**
 * Builds one stylesheet with the Tailwind binary the app already uses. The
 * bundle's own builder only builds its configured input files.
 */
final readonly class TailwindCompiler
{
    public function __construct(
        #[Autowire(service: '.tailwind.builder')]
        private TailwindBuilder $builder,
    ) {
    }

    public function compile(string $inputPath): string
    {
        $outputPath = tempnam(sys_get_temp_dir(), 'ds-tailwind');
        if (false === $outputPath) {
            throw new \RuntimeException('Cannot create a temporary file for the Tailwind build.');
        }

        try {
            $process = $this->builder->createBinary()->createProcess(['-i', $inputPath, '-o', $outputPath, '--minify']);
            $process->setTimeout(120);
            $process->mustRun();
            $css = file_get_contents($outputPath);
        } finally {
            unlink($outputPath);
        }

        if (false === $css || '' === $css) {
            throw new \RuntimeException(sprintf('Tailwind built nothing from %s.', $inputPath));
        }

        return $css;
    }
}
