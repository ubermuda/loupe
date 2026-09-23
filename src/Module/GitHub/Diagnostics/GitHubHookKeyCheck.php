<?php

declare(strict_types=1);

namespace App\Module\GitHub\Diagnostics;

use App\Module\GitHub\Service\HookSecretKey;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticInterface;
use Ubermuda\HealthCheckBundle\DiagnosticState;

/** A per-project webhook stores its secret encrypted, so no key turns the method off. */
final readonly class GitHubHookKeyCheck implements DiagnosticInterface
{
    public function __construct(
        private HookSecretKey $hookSecretKey,
    ) {
    }

    #[\Override]
    public static function priority(): int
    {
        return 10;
    }

    #[\Override]
    public function __invoke(): Diagnostic
    {
        return $this->hookSecretKey->isReadable()
            ? new Diagnostic('github_hook_key', DiagnosticState::Ok, 'github.system_status.hook_key.readable')
            : new Diagnostic('github_hook_key', DiagnosticState::Warning, 'github.system_status.hook_key.unreadable');
    }
}
