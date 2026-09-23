<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Diagnostics;

use App\Module\GitHub\Diagnostics\GitHubAppCheck;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use PHPUnit\Framework\TestCase;
use Ubermuda\HealthCheckBundle\DiagnosticState;

final class GitHubAppCheckTest extends TestCase
{
    public function test_all_four_variables_pass(): void
    {
        $diagnostic = new GitHubAppCheck(new GitHubAppConfiguration('loupe', 'client', 'secret', 'hook'))();

        self::assertSame(DiagnosticState::Ok, $diagnostic->state);
        self::assertSame('github.system_status.app.configured', $diagnostic->detail);
    }

    public function test_no_variable_leaves_the_app_off_without_a_failure(): void
    {
        $diagnostic = new GitHubAppCheck(new GitHubAppConfiguration(null, '', null, ''))();

        self::assertSame(DiagnosticState::Ok, $diagnostic->state);
        self::assertSame('github.system_status.app.not_offered', $diagnostic->detail);
    }

    public function test_a_partial_set_fails_and_names_the_missing_variables_alone(): void
    {
        $diagnostic = new GitHubAppCheck(new GitHubAppConfiguration('loupe', 'client', 'the-client-secret', null))();

        self::assertSame(DiagnosticState::Failed, $diagnostic->state);
        self::assertSame('github.system_status.app.incomplete', $diagnostic->detail);
        self::assertSame(['%variables%' => 'GITHUB_APP_WEBHOOK_SECRET'], $diagnostic->detailParameters);
    }
}
