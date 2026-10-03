<?php

declare(strict_types=1);

namespace App\Observability;

use Sentry\Dsn;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * The `sentry_origin` env prefix gives the origin the browser SDK posts to, for CSP
 * connect-src. It is null for an off or malformed DSN, so wrap it in `default:`.
 */
final class SentryOriginEnvVarProcessor implements EnvVarProcessorInterface
{
    #[\Override]
    public function getEnv(string $prefix, string $name, \Closure $getEnv): ?string
    {
        $dsn = $getEnv($name);
        if (!\is_string($dsn) || SentryDsnStatus::Valid !== SentryDsnStatus::of($dsn)) {
            return null;
        }

        $parsed = Dsn::createFromString($dsn);
        $origin = $parsed->getScheme().'://'.$parsed->getHost();
        $defaultPort = 'https' === $parsed->getScheme() ? 443 : 80;

        return $parsed->getPort() === $defaultPort ? $origin : $origin.':'.$parsed->getPort();
    }

    #[\Override]
    public static function getProvidedTypes(): array
    {
        return ['sentry_origin' => 'string'];
    }
}
