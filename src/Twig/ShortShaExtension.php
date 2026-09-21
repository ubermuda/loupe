<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class ShortShaExtension extends AbstractExtension
{
    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('short_sha', $this->shortSha(...)),
        ];
    }

    /** A bridge reports a released version or the full commit sha of a dev build. */
    public function shortSha(string $value): string
    {
        return 1 === preg_match('/^[0-9a-f]{40}$/i', $value) ? substr($value, 0, 8) : $value;
    }
}
