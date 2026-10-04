<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

/** Reads a typed parameter value. The template parser checks the types, so a wrong type here is a bug. */
final class ParameterValue
{
    /** @param array<string, mixed> $params */
    public static function string(array $params, string $name): string
    {
        $value = $params[$name] ?? null;

        return \is_string($value) ? $value : throw new \LogicException(\sprintf('Parameter "%s" must be a string.', $name));
    }

    /** @param array<string, mixed> $params */
    public static function optionalString(array $params, string $name): ?string
    {
        return null === ($params[$name] ?? null) ? null : self::string($params, $name);
    }

    /** @param array<string, mixed> $params */
    public static function int(array $params, string $name): int
    {
        $value = $params[$name] ?? null;

        return \is_int($value) ? $value : throw new \LogicException(\sprintf('Parameter "%s" must be an integer.', $name));
    }
}
