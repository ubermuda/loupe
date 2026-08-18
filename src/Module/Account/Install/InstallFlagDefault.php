<?php

declare(strict_types=1);

namespace App\Module\Account\Install;

use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

/** One feature flag as the install wizard should first create it. */
final readonly class InstallFlagDefault
{
    /** @param list<string>|null $options the choices a Select flag offers */
    public function __construct(
        public string $name,
        public FeatureFlagType $type,
        public mixed $value,
        public ?array $options = null,
    ) {
    }
}
