<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem;

use App\Tests\Support\ComponentSourceReader;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AppShellTopbarParityTest extends KernelTestCase
{
    use ComponentSourceReader;

    private static function translate(string $key): string
    {
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        return $translator->trans($key, [], null, 'en');
    }

    public function test_the_specimen_and_the_export_label_the_bell_and_new_work_as_the_app_does(): void
    {
        $jsx = self::componentJsx('AppShell');
        $specimen = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir').'/templates/Module/DesignSystem/specimen/appshell.html.twig');
        $bell = self::translate('nav.activity.open');
        $newWork = self::translate('nav.new_work');

        foreach (['export' => $jsx, 'specimen' => $specimen] as $name => $source) {
            self::assertStringContainsString('aria-label="'.$bell.'"', $source, $name);
            self::assertStringContainsString('aria-label="'.$newWork.'"', $source, $name);
        }
    }

    public function test_the_app_the_specimen_and_the_export_mark_the_new_work_label(): void
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir').'/templates/';
        $sources = [
            'app' => (string) file_get_contents($dir.'base.html.twig'),
            'specimen' => (string) file_get_contents($dir.'Module/DesignSystem/specimen/appshell.html.twig'),
            'export' => self::componentJsx('AppShell'),
        ];

        foreach ($sources as $name => $source) {
            self::assertMatchesRegularExpression('/<span class(Name)?="lp-topbar__new-work-label">/', $source, $name);
        }
    }
}
