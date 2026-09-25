<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use Composer\Semver\Semver;

/** The CLI versions this server works with. The heartbeat hands the range to the bridge, which updates within it. */
final readonly class CliCompatibility
{
    public const string RANGE = '^1.0';

    /** A version that is not semver, such as the commit sha of a dev build, is outside every range. */
    public function isCompatible(string $version): bool
    {
        try {
            return Semver::satisfies($version, self::RANGE);
        } catch (\UnexpectedValueException) {
            return false;
        }
    }
}
