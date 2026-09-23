<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use Ubermuda\DoctrineExtra\Encryption\EncryptionKeyProvider;

/** Tells whether a hook secret can be stored, because the column is encrypted. */
final readonly class HookSecretKey
{
    public function __construct(
        private EncryptionKeyProvider $keyProvider,
    ) {
    }

    public function isReadable(): bool
    {
        try {
            $this->keyProvider->key();
        } catch (\RuntimeException) {
            return false;
        }

        return true;
    }
}
