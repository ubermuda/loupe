<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Diagnostics;

use App\Module\GitHub\Diagnostics\GitHubHookKeyCheck;
use App\Module\GitHub\Service\HookSecretKey;
use PHPUnit\Framework\TestCase;
use Ubermuda\DoctrineExtra\Encryption\EncryptionKeyProvider;
use Ubermuda\HealthCheckBundle\DiagnosticState;

final class GitHubHookKeyCheckTest extends TestCase
{
    public function test_a_readable_key_passes(): void
    {
        $diagnostic = new GitHubHookKeyCheck(new HookSecretKey(new EncryptionKeyProvider(base64_encode(random_bytes(32)))))();

        self::assertSame(DiagnosticState::Ok, $diagnostic->state);
        self::assertSame('github.system_status.hook_key.readable', $diagnostic->detail);
    }

    public function test_an_unreadable_key_warns_that_the_webhook_is_off(): void
    {
        $diagnostic = new GitHubHookKeyCheck(new HookSecretKey(new EncryptionKeyProvider('')))();

        self::assertSame(DiagnosticState::Warning, $diagnostic->state);
        self::assertSame('github.system_status.hook_key.unreadable', $diagnostic->detail);
    }
}
