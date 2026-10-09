<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem\Twig;

use App\Module\DesignSystem\Twig\ButtonExtension;
use PHPUnit\Framework\TestCase;

final class ButtonExtensionTest extends TestCase
{
    public function test_the_root_class_alone_is_the_default(): void
    {
        self::assertSame('lp-btn', new ButtonExtension()->classes());
    }

    public function test_a_variant_and_a_size_are_appended_as_modifiers(): void
    {
        self::assertSame('lp-btn lp-btn--ghost lp-btn--sm', new ButtonExtension()->classes('ghost', 'sm'));
    }

    public function test_an_empty_modifier_is_skipped(): void
    {
        self::assertSame('lp-btn lp-btn--lg', new ButtonExtension()->classes('', 'lg'));
    }

    public function test_a_variant_can_name_several_modifiers(): void
    {
        self::assertSame('lp-btn lp-btn--ghost lp-btn--icon', new ButtonExtension()->classes('ghost icon'));
    }
}
