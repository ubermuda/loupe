<?php

declare(strict_types=1);

namespace App\Module\GitHub\Diagnostics;

use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppApiFailed;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticInterface;
use Ubermuda\HealthCheckBundle\DiagnosticState;

/** A refused key or a missing permission shows only as a pull request that Loupe cannot read. */
final readonly class GitHubAppApiCheck implements DiagnosticInterface
{
    private const array REQUIRED_PERMISSIONS = ['checks', 'contents', 'metadata', 'pull_requests', 'statuses'];

    public function __construct(
        private GitHubAppConfiguration $configuration,
        private GitHubAppApi $api,
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
        $missing = $this->configuration->missingApiVariables();
        if (2 === \count($missing)) {
            return new Diagnostic('github_app_api', DiagnosticState::Ok, 'github.system_status.app_api.not_offered');
        }

        if ([] !== $missing) {
            return new Diagnostic('github_app_api', DiagnosticState::Failed, 'github.system_status.app_api.incomplete', ['%variables%' => implode(', ', $missing)]);
        }

        try {
            $installations = $this->api->installations();
        } catch (GitHubAppApiFailed $failure) {
            return new Diagnostic(
                'github_app_api',
                DiagnosticState::Failed,
                'github.system_status.app_api.unreachable',
                ['%reason%' => trim($failure->reason.' '.$failure->status)],
            );
        }

        if ([] === $installations) {
            return new Diagnostic('github_app_api', DiagnosticState::Ok, 'github.system_status.app_api.none_installed');
        }

        $lacking = [];
        foreach ($installations as $installation) {
            $names = $installation->missingReadAccess(self::REQUIRED_PERMISSIONS);
            if ([] !== $names) {
                $lacking[] = $installation->account.' ('.implode(', ', $names).')';
            }
        }

        if ([] !== $lacking) {
            return new Diagnostic('github_app_api', DiagnosticState::Failed, 'github.system_status.app_api.missing_permissions', ['%installations%' => implode('; ', $lacking)]);
        }

        $silent = array_filter($installations, static fn ($installation): bool => [] !== $installation->missingWriteAccess(['pull_requests']));
        if ([] !== $silent) {
            return new Diagnostic(
                'github_app_api',
                DiagnosticState::Warning,
                'github.system_status.app_api.missing_comment_permission',
                ['%accounts%' => implode(', ', array_map(static fn ($installation): string => $installation->account, $silent))],
            );
        }

        $readOnly = array_filter($installations, static fn ($installation): bool => [] !== $installation->missingWriteAccess(['contents']));
        if ([] !== $readOnly) {
            return new Diagnostic(
                'github_app_api',
                DiagnosticState::Warning,
                'github.system_status.app_api.missing_contents_write',
                ['%accounts%' => implode(', ', array_map(static fn ($installation): string => $installation->account, $readOnly))],
            );
        }

        $noChecks = array_filter($installations, static fn ($installation): bool => [] !== $installation->missingWriteAccess(['checks']));
        if ([] !== $noChecks) {
            return new Diagnostic(
                'github_app_api',
                DiagnosticState::Warning,
                'github.system_status.app_api.missing_checks_write',
                ['%accounts%' => implode(', ', array_map(static fn ($installation): string => $installation->account, $noChecks))],
            );
        }

        return new Diagnostic('github_app_api', DiagnosticState::Ok, 'github.system_status.app_api.working', ['%count%' => \count($installations)]);
    }
}
