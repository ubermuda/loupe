<?php

declare(strict_types=1);

namespace App\Module\Workflow\Engine;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class EngineSwitch
{
    public function __construct(
        #[Autowire(param: 'workflow.engine_enabled')]
        private bool $enabled,
    ) {
    }

    public function isOn(): bool
    {
        return $this->enabled;
    }
}
