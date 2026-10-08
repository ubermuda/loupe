<?php

declare(strict_types=1);

namespace App\Module\DesignSystem;

/**
 * One building block of the design system. The styleguide renders it, the
 * adoption check counts its root class, and the Claude Design export lists it.
 */
final readonly class ComponentEntry
{
    /**
     * @param list<string> $variants
     * @param list<string> $states
     * @param list<string> $sizes
     */
    public function __construct(
        public string $name,
        public string $rootClass,
        public string $template,
        public array $variants,
        public array $states,
        public bool $enforced,
        public array $sizes = [],
        public string $group = 'core',
        public string $summary = '',
    ) {
    }
}
