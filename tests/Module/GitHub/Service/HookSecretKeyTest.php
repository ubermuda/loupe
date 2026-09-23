<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\GitHub\Service\HookSecretKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ubermuda\DoctrineExtra\Encryption\EncryptionKeyProvider;

final class HookSecretKeyTest extends TestCase
{
    public function test_a_base64_key_of_32_bytes_is_readable(): void
    {
        self::assertTrue(new HookSecretKey(new EncryptionKeyProvider(base64_encode(random_bytes(32))))->isReadable());
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'not base64' => ['not a key!'];
        yield 'too short' => [base64_encode(random_bytes(16))];
    }

    #[DataProvider('unreadableKeys')]
    public function test_any_other_key_is_not_readable(string $key): void
    {
        self::assertFalse(new HookSecretKey(new EncryptionKeyProvider($key))->isReadable());
    }
}
