<?php

declare(strict_types=1);

namespace App\Tests\Module\DesignSystem\Twig;

use App\Module\DesignSystem\Twig\FormExtension;
use PHPUnit\Framework\TestCase;

final class FormExtensionTest extends TestCase
{
    public function test_a_plain_input_has_the_root_class_alone(): void
    {
        self::assertSame('lp-input', new FormExtension()->inputClasses());
    }

    public function test_a_select_and_a_textarea_add_their_modifier(): void
    {
        self::assertSame('lp-input lp-select', new FormExtension()->inputClasses('select'));
        self::assertSame('lp-input lp-textarea', new FormExtension()->inputClasses('textarea'));
    }

    public function test_mono_is_added_last(): void
    {
        self::assertSame('lp-input lp-input--mono', new FormExtension()->inputClasses(null, true));
    }

    public function test_an_unknown_kind_is_a_plain_input(): void
    {
        self::assertSame('lp-input', new FormExtension()->inputClasses('range'));
    }

    public function test_the_label_has_one_class(): void
    {
        self::assertSame('lp-label', new FormExtension()->labelClasses());
    }
}
