<?php

declare(strict_types=1);

namespace App\Audit;

use App\Module\Audit\AuditLoggerRegistryInterface;
use App\Module\Audit\Auditor;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Binds the package's two categories to this application's Monolog channels.
 * `monolog.logger` is the default `app` channel, so the domain logger needs no
 * service id; `app_security` is a declared channel and has one.
 */
#[AsAlias(AuditLoggerRegistryInterface::class)]
final readonly class LoupeAuditLoggerRegistry implements AuditLoggerRegistryInterface
{
    public function __construct(
        private LoggerInterface $domainLogger,

        #[Autowire(service: 'monolog.logger.app_security')]
        private LoggerInterface $securityLogger,
    ) {
    }

    #[\Override]
    public function loggerFor(string $category): ?LoggerInterface
    {
        return match ($category) {
            Auditor::CATEGORY_DOMAIN => $this->domainLogger,
            Auditor::CATEGORY_SECURITY => $this->securityLogger,
            default => null,
        };
    }
}
