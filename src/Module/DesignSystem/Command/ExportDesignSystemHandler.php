<?php

declare(strict_types=1);

namespace App\Module\DesignSystem\Command;

use App\Module\DesignSystem\Catalog;
use App\Module\DesignSystem\Service\TailwindCompiler;
use App\Module\DesignSystem\Token\TokenReader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;

/**
 * Writes the Claude Design project layout. The platform writes its own
 * bundle, manifest, lint config, thumbnail and mount script, so this handler
 * never writes them.
 */
final readonly class ExportDesignSystemHandler
{
    private const array PLATFORM_FILES = [
        '_ds_bundle.js',
        '_ds_manifest.json',
        '_adherence.oxlintrc.json',
        '.thumbnail',
        'ds-mount.js',
    ];

    private const array TOKEN_FILES = [
        'colour' => 'colors',
        'type' => 'typography',
        'spacing' => 'spacing',
        'radius' => 'elevation',
        'shadow' => 'elevation',
        'motion' => 'motion',
    ];

    public function __construct(
        private Catalog $catalog,
        private TokenReader $tokenReader,
        private TailwindCompiler $tailwind,
        private Environment $twig,

        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    /** @return list<string> the paths written, relative to the directory */
    public function __invoke(ExportDesignSystemCommand $command): array
    {
        $root = rtrim($command->directory, '/');
        $filesystem = new Filesystem();
        $written = [];
        $write = function (string $path, string $content) use ($root, $filesystem, &$written): void {
            if (\in_array(basename($path), self::PLATFORM_FILES, true)) {
                throw new \LogicException(sprintf('%s belongs to the platform.', $path));
            }

            $filesystem->dumpFile($root.'/'.$path, $content);
            $written[] = $path;
        };

        $styles = $this->projectDir.'/assets/styles';
        $entries = $this->catalog->entries();

        $imports = ["@import './tokens/fonts.css';"];
        foreach ($this->tokenFiles($this->read($styles.'/tokens.css')) as $name => $css) {
            $write('tokens/'.$name.'.css', $css);
            $imports[] = sprintf("@import './tokens/%s.css';", $name);
        }
        $write('tokens/fonts.css', $this->fontsCss($this->read($styles.'/app.css')));
        $write('tokens/base.css', $this->render('base.css.twig'));
        $imports[] = "@import './tokens/base.css';";
        $write('styles.css', implode("\n", $imports)."\n".$this->tailwind->compile($styles.'/design-system.css'));

        $groups = [];
        foreach ($entries as $entry) {
            $groups[$entry->group][] = $entry;
            $base = sprintf('components/%s/%s', $entry->group, $entry->name);
            $context = ['entry' => $entry, 'section' => ucfirst($entry->group)];
            $write($base.'.jsx', $this->render('component.jsx.twig', $context));
            $write($base.'.d.ts', $this->render('component.d.ts.twig', $context));
            $write($base.'.prompt.md', $this->render('component.prompt.md.twig', $context));
        }
        foreach ($groups as $group => $members) {
            $write(sprintf('components/%s/%s.card.html', $group, $group), $this->render('group.card.html.twig', [
                'group' => $group,
                'name' => ucfirst($group),
                'entries' => $members,
            ]));
        }

        foreach ($this->tokenReader->groups() as $tokenGroup) {
            $write(sprintf('guidelines/%s.html', $tokenGroup->name), $this->render('token_card.html.twig', ['group' => $tokenGroup]));
        }

        $docs = $this->read($this->projectDir.'/docs/contributing/design-system.md');
        $write('readme.md', $this->render('readme.md.twig', [
            'docs' => ltrim(preg_replace('/\A---\n.*?\n---\n/s', '', $docs) ?? $docs),
        ]));
        $write('SKILL.md', $this->render('skill.md.twig'));

        $fonts = $this->projectDir.'/assets/fonts';
        foreach (glob($fonts.'/*.{woff2,txt}', \GLOB_BRACE) ?: [] as $font) {
            $write('assets/fonts/'.basename($font), $this->read($font));
        }

        sort($written);

        return $written;
    }

    /** @param array<string, mixed> $context */
    private function render(string $template, array $context = []): string
    {
        return $this->twig->render('@DesignSystem/export/'.$template, $context);
    }

    private function read(string $path): string
    {
        $content = file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Cannot read %s.', $path));
        }

        return $content;
    }

    /**
     * Splits the token stylesheet at its `@group` markers. A Tailwind `@theme`
     * block becomes a plain `:root` block, which a browser reads.
     *
     * @return array<string, string> the file name without extension, then its CSS
     */
    private function tokenFiles(string $source): array
    {
        $parts = preg_split('~/\*\s*@group\s+(\w+)\s*\*/~', $source, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $files = [];
        for ($i = 1; $i < \count($parts); $i += 2) {
            $file = self::TOKEN_FILES[$parts[$i]] ?? $parts[$i];
            $css = preg_replace('~@theme[^{]*\{~', ':root {', $parts[$i + 1]) ?? $parts[$i + 1];
            $files[$file] = ($files[$file] ?? "/* Copied from assets/styles/tokens.css by app:design-system:export. Edit that file instead. */\n").trim($css)."\n\n";
        }

        return array_map(static fn (string $css): string => rtrim($css)."\n", $files);
    }

    /** Reuses the app's `@font-face` rules, pointed at the copied files. */
    private function fontsCss(string $appCss): string
    {
        preg_match_all('~@font-face\s*\{[^}]*\}~', $appCss, $matches);

        return "/* Self-hosted fonts, copied from assets/fonts/. */\n\n"
            .str_replace('../fonts/', '../assets/fonts/', implode("\n\n", $matches[0]))."\n";
    }
}
