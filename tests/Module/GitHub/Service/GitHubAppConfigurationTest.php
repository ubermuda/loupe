<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\GitHub\Service\GitHubAppConfiguration;
use PHPUnit\Framework\TestCase;

final class GitHubAppConfigurationTest extends TestCase
{
    public function test_all_four_values_configure_the_app(): void
    {
        self::assertTrue(new GitHubAppConfiguration('loupe', 'client', 'secret', 'hook')->isConfigured());
    }

    public function test_a_missing_or_empty_value_leaves_the_app_off(): void
    {
        self::assertFalse(new GitHubAppConfiguration(null, 'client', 'secret', 'hook')->isConfigured());
        self::assertFalse(new GitHubAppConfiguration('loupe', '', 'secret', 'hook')->isConfigured());
        self::assertFalse(new GitHubAppConfiguration('loupe', 'client', null, 'hook')->isConfigured());
        self::assertFalse(new GitHubAppConfiguration('loupe', 'client', 'secret', '')->isConfigured());
    }
}
