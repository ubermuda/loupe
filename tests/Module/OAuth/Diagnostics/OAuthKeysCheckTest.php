<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Diagnostics;

use App\Module\OAuth\Diagnostics\OAuthKeysCheck;
use PHPUnit\Framework\TestCase;
use Ubermuda\HealthCheckBundle\DiagnosticState;

final class OAuthKeysCheckTest extends TestCase
{
    private const string KEYS = __DIR__.'/../../../Support/oauth/';

    public function test_readable_keys_pass(): void
    {
        $check = new OAuthKeysCheck(self::KEYS.'private.pem', '', self::KEYS.'public.pem', 'secret');

        self::assertSame(DiagnosticState::Ok, $check()->state);
    }

    public function test_a_missing_key_fails(): void
    {
        self::assertSame(DiagnosticState::Failed, new OAuthKeysCheck(null, null, self::KEYS.'public.pem', 'secret')()->state);
        self::assertSame(DiagnosticState::Failed, new OAuthKeysCheck(self::KEYS.'private.pem', '', self::KEYS.'public.pem', '')()->state);
    }

    public function test_an_unreadable_key_fails(): void
    {
        $check = new OAuthKeysCheck('/nonexistent/private.pem', '', self::KEYS.'public.pem', 'secret');

        self::assertSame(DiagnosticState::Failed, $check()->state);
    }

    public function test_key_contents_pass_as_well_as_paths(): void
    {
        $check = new OAuthKeysCheck((string) file_get_contents(self::KEYS.'private.pem'), '', (string) file_get_contents(self::KEYS.'public.pem'), 'secret');

        self::assertSame(DiagnosticState::Ok, $check()->state);
    }
}
