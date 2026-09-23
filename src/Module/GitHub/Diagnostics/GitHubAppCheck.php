<?php

declare(strict_types=1);

namespace App\Module\GitHub\Diagnostics;

use App\Module\GitHub\Service\GitHubAppConfiguration;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticInterface;
use Ubermuda\HealthCheckBundle\DiagnosticState;

/** A partial App registration hides the App from every project with no error. */
final readonly class GitHubAppCheck implements DiagnosticInterface
{
    public function __construct(
        private GitHubAppConfiguration $configuration,
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
        if ($this->configuration->isConfigured()) {
            return new Diagnostic('github_app', DiagnosticState::Ok, 'github.system_status.app.configured');
        }

        if ($this->configuration->isUnset()) {
            return new Diagnostic('github_app', DiagnosticState::Ok, 'github.system_status.app.not_offered');
        }

        return new Diagnostic(
            'github_app',
            DiagnosticState::Failed,
            'github.system_status.app.incomplete',
            ['%variables%' => implode(', ', $this->configuration->missingVariables())],
        );
    }
}
