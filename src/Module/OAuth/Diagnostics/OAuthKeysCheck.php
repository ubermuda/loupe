<?php

declare(strict_types=1);

namespace App\Module\OAuth\Diagnostics;

use League\OAuth2\Server\CryptKey;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticInterface;
use Ubermuda\HealthCheckBundle\DiagnosticState;

/**
 * The OAuth server is always on, and a missing key shows up only when an app
 * tries to connect. This check reads the keys the way league does.
 */
final readonly class OAuthKeysCheck implements DiagnosticInterface
{
    public function __construct(
        #[Autowire(env: 'default::resolve:OAUTH_PRIVATE_KEY')]
        private ?string $privateKey,

        #[Autowire(env: 'default::OAUTH_PRIVATE_KEY_PASSPHRASE')]
        private ?string $passphrase,

        #[Autowire(env: 'default::resolve:OAUTH_PUBLIC_KEY')]
        private ?string $publicKey,

        #[Autowire(env: 'default::OAUTH_ENCRYPTION_KEY')]
        private ?string $encryptionKey,
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
        $readable = null !== $this->privateKey && '' !== $this->privateKey
            && null !== $this->publicKey && '' !== $this->publicKey
            && null !== $this->encryptionKey && '' !== $this->encryptionKey;

        if ($readable) {
            try {
                new CryptKey($this->privateKey, $this->passphrase, false);
                new CryptKey($this->publicKey, null, false);
            } catch (\LogicException) {
                $readable = false;
            }
        }

        return $readable
            ? new Diagnostic('oauth_keys', DiagnosticState::Ok, 'oauth.system_status.keys.ok')
            : new Diagnostic('oauth_keys', DiagnosticState::Failed, 'oauth.system_status.keys.missing');
    }
}
