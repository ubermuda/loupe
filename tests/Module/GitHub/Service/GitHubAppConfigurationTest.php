<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\GitHub\Service\GitHubAppConfiguration;
use PHPUnit\Framework\TestCase;

final class GitHubAppConfigurationTest extends TestCase
{
    public function test_all_four_values_configure_the_app(): void
    {
        self::assertTrue(new GitHubAppConfiguration('loupe', 'client', 'secret', 'hook', null, null)->isConfigured());
    }

    public function test_a_missing_or_empty_value_leaves_the_app_off(): void
    {
        self::assertFalse(new GitHubAppConfiguration(null, 'client', 'secret', 'hook', null, null)->isConfigured());
        self::assertFalse(new GitHubAppConfiguration('loupe', '', 'secret', 'hook', null, null)->isConfigured());
        self::assertFalse(new GitHubAppConfiguration('loupe', 'client', null, 'hook', null, null)->isConfigured());
        self::assertFalse(new GitHubAppConfiguration('loupe', 'client', 'secret', '', null, null)->isConfigured());
    }

    public function test_unset_means_no_value_at_all(): void
    {
        self::assertTrue(new GitHubAppConfiguration(null, '', null, '', null, null)->isUnset());
        self::assertFalse(new GitHubAppConfiguration(null, null, null, 'hook', null, null)->isUnset());
        self::assertFalse(new GitHubAppConfiguration('loupe', 'client', 'secret', 'hook', null, null)->isUnset());
    }

    public function test_missing_variables_names_each_unset_variable(): void
    {
        self::assertSame([], new GitHubAppConfiguration('loupe', 'client', 'secret', 'hook', null, null)->missingVariables());
        self::assertSame(
            ['GITHUB_APP_CLIENT_ID', 'GITHUB_APP_WEBHOOK_SECRET'],
            new GitHubAppConfiguration('loupe', '', 'secret', null, null, null)->missingVariables(),
        );
        self::assertSame(
            ['GITHUB_APP_SLUG', 'GITHUB_APP_CLIENT_ID', 'GITHUB_APP_CLIENT_SECRET', 'GITHUB_APP_WEBHOOK_SECRET'],
            new GitHubAppConfiguration(null, null, null, null, null, null)->missingVariables(),
        );
    }

    public function test_the_api_pair_does_not_decide_whether_the_install_is_configured(): void
    {
        self::assertTrue(new GitHubAppConfiguration('loupe', 'client', 'secret', 'hook', null, '')->isConfigured());
        self::assertTrue(new GitHubAppConfiguration(null, null, null, null, '123', 'key')->isUnset());
    }

    public function test_the_forge_is_readable_only_with_both_api_values(): void
    {
        self::assertTrue(new GitHubAppConfiguration(null, null, null, null, '123', 'key')->canReadForge());
        self::assertFalse(new GitHubAppConfiguration(null, null, null, null, '123', '')->canReadForge());
        self::assertFalse(new GitHubAppConfiguration(null, null, null, null, null, 'key')->canReadForge());
    }

    public function test_missing_api_variables_names_each_unset_api_variable(): void
    {
        self::assertSame([], new GitHubAppConfiguration(null, null, null, null, '123', 'key')->missingApiVariables());
        self::assertSame(['GITHUB_APP_PRIVATE_KEY'], new GitHubAppConfiguration(null, null, null, null, '123', null)->missingApiVariables());
        self::assertSame(
            ['GITHUB_APP_ID', 'GITHUB_APP_PRIVATE_KEY'],
            new GitHubAppConfiguration('loupe', 'client', 'secret', 'hook', '', null)->missingApiVariables(),
        );
    }
}
