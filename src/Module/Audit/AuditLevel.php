<?php

declare(strict_types=1);

namespace App\Module\Audit;

use Psr\Log\LogLevel;

enum AuditLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';

    public function psrLogLevel(): string
    {
        return match ($this) {
            self::Debug => LogLevel::DEBUG,
            self::Info => LogLevel::INFO,
            self::Warning => LogLevel::WARNING,
            self::Error => LogLevel::ERROR,
        };
    }
}
