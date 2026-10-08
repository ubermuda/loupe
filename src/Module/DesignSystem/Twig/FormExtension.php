<?php

declare(strict_types=1);

namespace App\Module\DesignSystem\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The class lists of the form parts, in one place. The Input, Select and
 * Textarea components call them, and so does a template that renders a Symfony
 * form widget, which draws its own tag.
 */
final class FormExtension extends AbstractExtension
{
    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('ds_input_class', $this->inputClasses(...)),
            new TwigFunction('ds_label_class', $this->labelClasses(...)),
        ];
    }

    /** `$kind` is `select` or `textarea`; any other value is a plain input. */
    public function inputClasses(?string $kind = null, bool $mono = false): string
    {
        $classes = ['lp-input'];
        if ('select' === $kind || 'textarea' === $kind) {
            $classes[] = 'lp-'.$kind;
        }
        if ($mono) {
            $classes[] = 'lp-input--mono';
        }

        return implode(' ', $classes);
    }

    public function labelClasses(): string
    {
        return 'lp-label';
    }
}
