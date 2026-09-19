<?php

declare(strict_types=1);

namespace App\Module\OAuth\Device;

/**
 * The code a person types to approve a device. League issues eight letters with
 * no vowel and no look-alike character, so case, spaces and dashes carry no
 * meaning and a typed code is compared without them.
 */
final class UserCode
{
    public const string DEVICE_GRANT = 'urn:ietf:params:oauth:grant-type:device_code';

    public static function normalize(string $typed): string
    {
        return (string) preg_replace('/[^A-Z]/', '', strtoupper($typed));
    }

    /** Eight letters read as two groups of four, the way the CLI prints them. */
    public static function display(string $code): string
    {
        return 8 === \strlen($code) ? substr($code, 0, 4).'-'.substr($code, 4) : $code;
    }
}
