<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

/** One installation of the App, as the App itself sees it. */
final readonly class GitHubAppInstallationAccess
{
    /** @param array<string, string> $permissions the permission name and its grant, such as `read` or `write` */
    public function __construct(
        public int $id,
        public string $account,
        public array $permissions,
    ) {
    }

    /**
     * @param list<string> $names
     *
     * @return list<string> the names that grant neither read nor write access
     */
    public function missingReadAccess(array $names): array
    {
        return array_values(array_filter(
            $names,
            fn (string $name): bool => !\in_array($this->permissions[$name] ?? null, ['read', 'write'], true),
        ));
    }

    /**
     * @param list<string> $names
     *
     * @return list<string> the names that do not grant write access
     */
    public function missingWriteAccess(array $names): array
    {
        return array_values(array_filter(
            $names,
            fn (string $name): bool => 'write' !== ($this->permissions[$name] ?? null),
        ));
    }
}
