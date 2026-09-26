<?php

declare(strict_types=1);

namespace App\Observability;

use Sentry\Dsn;

/** Mirrors Sentry\Options::normalizeDsnOption(), which drops a malformed DSN with a debug log only. */
enum SentryDsnStatus
{
    case Off;
    case Malformed;
    case Valid;

    private const array OFF_VALUES = ['', 'false', '(false)', 'empty', '(empty)', 'null', '(null)'];

    public static function of(?string $dsn): self
    {
        if (null === $dsn || \in_array(strtolower($dsn), self::OFF_VALUES, true)) {
            return self::Off;
        }

        try {
            Dsn::createFromString($dsn);
        } catch (\InvalidArgumentException) {
            return self::Malformed;
        }

        return self::Valid;
    }
}
