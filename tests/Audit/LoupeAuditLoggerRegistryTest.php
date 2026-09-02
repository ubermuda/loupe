<?php

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Module\Audit\AuditLoggerRegistryInterface;
use App\Module\Audit\Auditor;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The categories a migrated call site passes are the whole of its channel
 * routing, so what each one resolves to in the wired container is worth
 * pinning: every per-handler channel assertion elsewhere reads the registry
 * rather than Monolog.
 */
final class LoupeAuditLoggerRegistryTest extends KernelTestCase
{
    public function test_the_security_category_resolves_to_the_app_security_channel(): void
    {
        self::assertSame('app_security', $this->channelFor(Auditor::CATEGORY_SECURITY));
    }

    public function test_the_domain_category_resolves_to_the_app_channel(): void
    {
        self::assertSame('app', $this->channelFor(Auditor::CATEGORY_DOMAIN));
    }

    public function test_an_unknown_category_resolves_to_no_logger(): void
    {
        self::bootKernel();
        $registry = static::getContainer()->get(AuditLoggerRegistryInterface::class);
        self::assertInstanceOf(AuditLoggerRegistryInterface::class, $registry);

        self::assertNull($registry->loggerFor('not-a-category'));
    }

    private function channelFor(string $category): string
    {
        self::bootKernel();
        $registry = static::getContainer()->get(AuditLoggerRegistryInterface::class);
        self::assertInstanceOf(AuditLoggerRegistryInterface::class, $registry);

        $logger = $registry->loggerFor($category);
        self::assertInstanceOf(Logger::class, $logger);

        return $logger->getName();
    }
}
