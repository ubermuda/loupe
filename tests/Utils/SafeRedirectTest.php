<?php

declare(strict_types=1);

namespace App\Tests\Utils;

use App\Utils\SafeRedirect;
use PHPUnit\Framework\TestCase;

final class SafeRedirectTest extends TestCase
{
    public function test_accepts_a_local_absolute_path(): void
    {
        self::assertTrue(SafeRedirect::isLocalPath('/projects/some-project/connect'));
    }

    public function test_rejects_a_path_with_no_leading_slash(): void
    {
        self::assertFalse(SafeRedirect::isLocalPath('projects/some-project'));
    }

    public function test_rejects_a_protocol_relative_target(): void
    {
        self::assertFalse(SafeRedirect::isLocalPath('//evil.example/projects/some-project'));
    }

    public function test_rejects_a_backslash_host_target(): void
    {
        self::assertFalse(SafeRedirect::isLocalPath('/\\evil.example/projects/some-project'));
    }
}
