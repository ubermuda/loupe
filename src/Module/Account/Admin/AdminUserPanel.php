<?php

declare(strict_types=1);

namespace App\Module\Account\Admin;

/**
 * One module's contribution to the admin user detail page: a template name and
 * the context it renders with. Never markup — an HTML string would put the
 * module's output outside the Twig layer, where the template rules cannot see
 * it.
 */
final readonly class AdminUserPanel
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $template,
        public array $context = [],
    ) {
    }
}
