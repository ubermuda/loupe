<?php

declare(strict_types=1);

namespace App\Tests\Module\Audit;

use App\Module\Audit\AuditLevel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class AuditLevelTest extends TestCase
{
    public function test_every_case_maps_to_a_psr3_level(): void
    {
        self::assertSame(LogLevel::DEBUG, AuditLevel::Debug->psrLogLevel());
        self::assertSame(LogLevel::INFO, AuditLevel::Info->psrLogLevel());
        self::assertSame(LogLevel::WARNING, AuditLevel::Warning->psrLogLevel());
        self::assertSame(LogLevel::ERROR, AuditLevel::Error->psrLogLevel());
    }
}
