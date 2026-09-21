<?php

declare(strict_types=1);

namespace App\Module\OAuth\Service;

/**
 * Reads the RFC 8707 resource parameter from a raw query or form body. PHP
 * keeps only the last of repeated keys, and a request that names two
 * resources must be refused, so the raw string is parsed here.
 */
final class ResourceParameter
{
    /** @return list<string> every resource value, in order */
    public static function values(string $raw): array
    {
        $values = [];
        foreach (explode('&', $raw) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name);
            if ('resource' === $name) {
                $values[] = urldecode($value);
            } elseif (str_starts_with($name, 'resource[')) {
                $values[] = '';
            }
        }

        return $values;
    }
}
