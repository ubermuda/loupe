<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem\Command;

use App\Module\DesignSystem\Catalog;
use App\Module\DesignSystem\Command\ExportDesignSystemCommand;
use App\Module\DesignSystem\Command\ExportDesignSystemHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ExportDesignSystemHandlerTest extends KernelTestCase
{
    private string $directory;

    /** @var list<string> */
    private array $written;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->directory = sys_get_temp_dir().'/ds-export-'.bin2hex(random_bytes(4));
        $handler = static::getContainer()->get(ExportDesignSystemHandler::class);
        self::assertInstanceOf(ExportDesignSystemHandler::class, $handler);
        $this->written = $handler(new ExportDesignSystemCommand($this->directory));
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }

    public function test_it_writes_the_project_layout(): void
    {
        foreach ([
            'SKILL.md',
            'readme.md',
            'styles.css',
            'tokens/colors.css',
            'tokens/typography.css',
            'tokens/spacing.css',
            'tokens/elevation.css',
            'tokens/motion.css',
            'tokens/fonts.css',
            'tokens/base.css',
            'components/core/Button.jsx',
            'components/core/Button.d.ts',
            'components/core/Button.prompt.md',
            'components/core/core.card.html',
            'guidelines/colour.html',
            'assets/fonts/Geist-Variable.woff2',
            'assets/fonts/LICENSE.txt',
        ] as $path) {
            self::assertContains($path, $this->written);
            self::assertFileExists($this->directory.'/'.$path);
        }
    }

    public function test_it_reports_exactly_the_files_on_disk(): void
    {
        $onDisk = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $onDisk[] = substr((string) $file, \strlen($this->directory) + 1);
        }
        sort($onDisk);

        self::assertSame($onDisk, $this->written);
    }

    public function test_it_writes_no_platform_file(): void
    {
        foreach (['_ds_bundle.js', '_ds_manifest.json', '_adherence.oxlintrc.json', '.thumbnail', 'ds-mount.js'] as $file) {
            self::assertFileDoesNotExist($this->directory.'/'.$file);
        }
    }

    public function test_button_lists_every_catalog_variant_and_the_real_classes(): void
    {
        $jsx = (string) file_get_contents($this->directory.'/components/core/Button.jsx');
        $types = (string) file_get_contents($this->directory.'/components/core/Button.d.ts');
        $entry = new Catalog()->entries()[0];

        self::assertNotEmpty($entry->variants);
        foreach ($entry->variants as $variant) {
            self::assertStringContainsString("'".$variant."'", $jsx);
            self::assertStringContainsString("| '".$variant."'", $types);
        }
        self::assertStringContainsString("'lp-btn--' + modifier", $jsx);
        self::assertStringContainsString('@startingPoint section="Core"', $types);
    }

    public function test_the_stylesheet_holds_the_real_button_rules(): void
    {
        $css = (string) file_get_contents($this->directory.'/styles.css');

        self::assertStringStartsWith("@import './tokens/fonts.css';", $css);
        self::assertStringContainsString('.lp-btn--primary', $css);
        self::assertStringContainsString('--accent:', $css);
        foreach (glob($this->directory.'/tokens/*.css') ?: [] as $file) {
            self::assertStringContainsString("@import './tokens/".basename($file)."';", $css);
        }
    }

    public function test_the_second_tailwind_input_imports_every_component_stylesheet(): void
    {
        $root = \dirname(__DIR__, 4).'/assets/styles';
        $input = (string) file_get_contents($root.'/design-system.css');

        foreach (glob($root.'/components/*.css') ?: [] as $file) {
            self::assertStringContainsString("@import './components/".basename($file)."';", $input);
        }
    }

    public function test_every_wrapper_forwards_attributes_and_merges_a_caller_class(): void
    {
        foreach (['Button', 'Flash'] as $name) {
            $jsx = (string) file_get_contents($this->directory.'/components/core/'.$name.'.jsx');

            self::assertStringContainsString('className: extra', $jsx);
            self::assertStringContainsString('classes.push(extra)', $jsx);
            self::assertStringContainsString('{...rest}', $jsx);
        }
    }

    public function test_tokens_are_plain_root_blocks(): void
    {
        $css = (string) file_get_contents($this->directory.'/tokens/elevation.css');

        self::assertStringContainsString('--radius-lg', $css);
        self::assertStringContainsString('--shadow-card', $css);
        self::assertStringNotContainsString('@theme', $css);
        self::assertStringNotContainsString('@group', $css);
    }

    public function test_the_card_uses_the_platform_mount(): void
    {
        $card = (string) file_get_contents($this->directory.'/components/core/core.card.html');

        self::assertStringStartsWith('<!-- @dsCard group="Components" viewport="700x300"', $card);
        self::assertStringContainsString("dsFind('Button', 'Flash'", $card);
        self::assertStringContainsString('<script src="../../ds-mount.js">', $card);
    }

    public function test_a_non_button_component_is_not_a_button(): void
    {
        $jsx = (string) file_get_contents($this->directory.'/components/core/Flash.jsx');
        $types = (string) file_get_contents($this->directory.'/components/core/Badge.d.ts');
        $prompt = (string) file_get_contents($this->directory.'/components/core/Flash.prompt.md');
        $card = (string) file_get_contents($this->directory.'/components/core/core.card.html');

        self::assertStringContainsString('<div {...rest} className={className}>', $jsx);
        self::assertStringNotContainsString('<button', $jsx);
        self::assertStringNotContainsString('disabled', $jsx);
        self::assertStringNotContainsString('href', $types);
        self::assertStringNotContainsString('disabled', $prompt);
        self::assertStringContainsString('<Flash variant="success">Example</Flash>', $prompt);
        self::assertStringContainsString('<EmptyState>Example</EmptyState>', $card);
    }

    public function test_a_component_with_a_dot_renders_its_child_elements(): void
    {
        $flash = (string) file_get_contents($this->directory.'/components/core/Flash.jsx');
        $chip = (string) file_get_contents($this->directory.'/components/core/StatusChip.jsx');
        $badge = (string) file_get_contents($this->directory.'/components/core/Badge.jsx');

        self::assertStringContainsString('<span className="lp-flash__dot"></span>', $flash);
        self::assertStringContainsString('<span className="lp-flash__message">{children}</span>', $flash);
        self::assertStringContainsString('<span className="lp-status-chip__dot"></span>', $chip);
        self::assertStringNotContainsString('__dot', $badge);
    }
}
