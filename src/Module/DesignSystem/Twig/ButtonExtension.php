<?php

declare(strict_types=1);

namespace App\Module\DesignSystem\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The button's class list, in one place. The Button component calls it, and so
 * does a template that renders a Symfony form button, which draws its own tag.
 */
final class ButtonExtension extends AbstractExtension
{
    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('ds_button_class', $this->classes(...))];
    }

    /** A variant can name several modifiers, such as `ghost icon`. */
    public function classes(?string $variant = null, ?string $size = null): string
    {
        $classes = ['lp-btn'];
        foreach ([$variant, $size] as $modifiers) {
            foreach (preg_split('/\s+/', trim((string) $modifiers), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $modifier) {
                $classes[] = 'lp-btn--'.$modifier;
            }
        }

        return implode(' ', $classes);
    }
}
