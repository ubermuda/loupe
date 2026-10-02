<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Module\Workflow\Template\Rule;

/** Reads a parameter of the action of a rule. The template parser already checked its type. */
final class ActionParams
{
    public static function string(Rule $rule, string $name): string
    {
        $value = $rule->then->params[$name] ?? null;

        return \is_string($value) ? $value : throw new \LogicException(\sprintf('The rule "%s" has no string parameter "%s".', $rule->id, $name));
    }

    public static function optionalString(Rule $rule, string $name): ?string
    {
        return \array_key_exists($name, $rule->then->params) ? self::string($rule, $name) : null;
    }

    public static function optionalInt(Rule $rule, string $name): ?int
    {
        $value = $rule->then->params[$name] ?? null;
        if (null !== $value && !\is_int($value)) {
            throw new \LogicException(\sprintf('The rule "%s" has no integer parameter "%s".', $rule->id, $name));
        }

        return $value;
    }
}
