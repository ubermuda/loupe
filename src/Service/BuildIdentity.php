<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\HealthCheckBundle\HealthMetadataProvider;

/**
 * Which build this instance is running, read from a file the production image
 * writes at build time. A missing file is the answer rather than a failure —
 * the instance was built outside the release pipeline — which an environment
 * variable could not express, because unset and deliberately-set look alike.
 *
 * The file is read once at construction, so contributing it to the health
 * endpoint costs the probe no I/O.
 */
final readonly class BuildIdentity implements HealthMetadataProvider
{
    /** Null when no release build produced this instance. */
    public ?string $version;

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        string $projectDir,
    ) {
        $path = $projectDir.'/var/build-version';
        $contents = is_readable($path) ? trim((string) file_get_contents($path)) : '';

        $this->version = '' !== $contents ? $contents : null;
    }

    #[\Override]
    public function fields(): array
    {
        return ['version' => $this->version];
    }

    /** Which build is live tells an attacker which advisories apply to it. */
    #[\Override]
    public function sensitive(): bool
    {
        return true;
    }
}
