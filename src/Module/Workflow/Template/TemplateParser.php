<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Condition\Conditions;
use App\Module\Workflow\Condition\ParameterType;
use App\Module\Workflow\Expression\AllOf;
use App\Module\Workflow\Expression\AnyOf;
use App\Module\Workflow\Expression\ConditionLeaf;
use App\Module\Workflow\Expression\Expression;
use App\Module\Workflow\Expression\Not;

/**
 * Turns a template array, from a shipped YAML file or from a stored JSON copy, into a Template.
 * It collects every error before it throws, so one run reports all of them.
 */
final readonly class TemplateParser
{
    private const array COLUMN_FLAGS = ['@backlog', '@terminal'];
    private const string ANY_COLUMN = '*';
    private const array RULE_KEYS = ['id', 'slot', 'when', 'then'];

    public function __construct(
        private Conditions $conditions,
    ) {
    }

    /**
     * @param array<mixed> $source
     *
     * @throws InvalidTemplate
     */
    public function parse(array $source): Template
    {
        $errors = [];

        $key = $source['key'] ?? null;
        if (!\is_string($key) || '' === $key) {
            $errors[] = self::topLevelError($source, 'key', 'must be a non-empty string');
            $key = '';
        }

        $version = $source['version'] ?? null;
        if (!\is_int($version)) {
            $errors[] = self::topLevelError($source, 'version', 'must be an integer');
            $version = 0;
        }

        $workTimeoutMinutes = $source['workTimeoutMinutes'] ?? null;
        if (!\is_int($workTimeoutMinutes) || $workTimeoutMinutes < 1) {
            $errors[] = self::topLevelError($source, 'workTimeoutMinutes', 'must be a positive integer');
            $workTimeoutMinutes = 0;
        }

        $backoffMinutes = self::backoffMinutes($source['backoffMinutes'] ?? null);
        if (null === $backoffMinutes) {
            $errors[] = self::topLevelError($source, 'backoffMinutes', 'must be a list of positive integers');
            $backoffMinutes = [];
        }

        $slots = $this->slots(self::topLevelList($source, 'slots', $errors), $errors);
        $slotKeys = array_map(static fn (Slot $slot): string => $slot->key, $slots);
        $manualMoves = $this->manualMoves(self::topLevelList($source, 'manualMoves', $errors), $slotKeys, $errors);
        $rules = $this->rules(self::topLevelList($source, 'rules', $errors), $slotKeys, $errors);

        if ([] !== $errors) {
            throw new InvalidTemplate($errors);
        }

        return new Template($key, $version, $slots, $rules, $manualMoves, $backoffMinutes, $workTimeoutMinutes);
    }

    /**
     * @param list<mixed>  $source
     * @param list<string> $errors
     *
     * @return list<Slot>
     */
    private function slots(array $source, array &$errors): array
    {
        $slots = [];
        $seen = [];
        foreach ($source as $index => $entry) {
            $where = \sprintf('slots[%d]', $index);
            $key = \is_array($entry) ? ($entry['key'] ?? null) : null;
            $label = \is_array($entry) ? ($entry['label'] ?? null) : null;
            if (!\is_string($key) || '' === $key || !\is_string($label) || '' === $label) {
                $errors[] = $where.': must be a map with a string "key" and a string "label"';
                continue;
            }
            if (str_starts_with($key, '@')) {
                $errors[] = \sprintf('%s: slot key "%s" must not start with "@"', $where, $key);
                continue;
            }
            if (self::ANY_COLUMN === $key) {
                $errors[] = \sprintf('%s: slot key "%s" is reserved', $where, $key);
                continue;
            }
            if (isset($seen[$key])) {
                $errors[] = \sprintf('%s: duplicate slot key "%s"', $where, $key);
                continue;
            }
            $seen[$key] = true;
            $slots[] = new Slot($key, $label);
        }

        return $slots;
    }

    /**
     * @param list<mixed>  $source
     * @param list<string> $slotKeys
     * @param list<string> $errors
     *
     * @return list<ManualMove>
     */
    private function manualMoves(array $source, array $slotKeys, array &$errors): array
    {
        $moves = [];
        foreach ($source as $index => $entry) {
            $where = \sprintf('manualMoves[%d]', $index);
            $from = \is_array($entry) ? ($entry['from'] ?? null) : null;
            $to = \is_array($entry) ? ($entry['to'] ?? null) : null;
            if (!\is_string($from) || !\is_string($to)) {
                $errors[] = $where.': must be a map with a string "from" and a string "to"';
                continue;
            }
            $valid = true;
            foreach (['from' => $from, 'to' => $to] as $end => $value) {
                if (!self::isColumn($value, $slotKeys, true)) {
                    $errors[] = \sprintf('%s.%s: unknown slot "%s"', $where, $end, $value);
                    $valid = false;
                }
            }
            if ($valid) {
                $moves[] = new ManualMove($from, $to);
            }
        }

        return $moves;
    }

    /**
     * @param list<mixed>  $source
     * @param list<string> $slotKeys
     * @param list<string> $errors
     *
     * @return list<Rule>
     */
    private function rules(array $source, array $slotKeys, array &$errors): array
    {
        $rules = [];
        $seen = [];
        foreach ($source as $index => $entry) {
            $where = \sprintf('rules[%d]', $index);
            if (!self::isMap($entry)) {
                $errors[] = $where.': must be a map';
                continue;
            }
            $errorCount = \count($errors);

            $id = $entry['id'] ?? null;
            if (!\is_string($id) || '' === $id) {
                $errors[] = $where.' id: must be a non-empty string';
                $id = '';
            } else {
                $where .= \sprintf(' (%s)', $id);
                if (isset($seen[$id])) {
                    $errors[] = \sprintf('%s: duplicate rule id "%s"', $where, $id);
                }
                $seen[$id] = true;
            }

            foreach (array_keys($entry) as $name) {
                if (!\in_array($name, self::RULE_KEYS, true)) {
                    $errors[] = \sprintf('%s: unknown key "%s"', $where, $name);
                }
            }

            $slot = $entry['slot'] ?? null;
            if (\array_key_exists('slot', $entry)) {
                if (!\is_string($slot)) {
                    $errors[] = $where.' slot: must be a string';
                } elseif (!self::isColumn($slot, $slotKeys, false)) {
                    $errors[] = \sprintf('%s slot: unknown slot "%s"', $where, $slot);
                }
            }

            $when = null;
            if (\array_key_exists('when', $entry)) {
                $when = $this->expression($entry['when'], $where.' when', $slotKeys, $errors);
            } else {
                $errors[] = $where.' when: is missing';
            }

            $then = null;
            if (\array_key_exists('then', $entry)) {
                $then = $this->action($entry['then'], $where.' then', $slotKeys, $errors);
            } else {
                $errors[] = $where.' then: is missing';
            }

            if (\count($errors) === $errorCount && null !== $when && null !== $then && (null === $slot || \is_string($slot))) {
                $rules[] = new Rule($id, $slot, $when, $then);
            }
        }

        return $rules;
    }

    /**
     * @param list<string> $slotKeys
     * @param list<string> $errors
     */
    private function expression(mixed $node, string $where, array $slotKeys, array &$errors): ?Expression
    {
        if (!\is_array($node) || 1 !== \count($node) || !\is_string(array_key_first($node))) {
            $errors[] = $where.': a node must have exactly one key';

            return null;
        }
        $key = array_key_first($node);
        $value = $node[$key];

        if ('not' === $key) {
            $inner = $this->expression($value, $where.'.not', $slotKeys, $errors);

            return null === $inner ? null : new Not($inner);
        }

        if ('all' === $key || 'any' === $key) {
            if (!\is_array($value) || !array_is_list($value) || ('any' === $key && [] === $value)) {
                $errors[] = \sprintf('%s.%s: must be a %slist', $where, $key, 'any' === $key ? 'non-empty ' : '');

                return null;
            }
            $children = [];
            foreach ($value as $index => $child) {
                $children[] = $this->expression($child, \sprintf('%s.%s[%d]', $where, $key, $index), $slotKeys, $errors);
            }
            $children = array_values(array_filter($children));
            if (\count($children) !== \count($value)) {
                return null;
            }

            if ('all' === $key || [] === $children) {
                return new AllOf($children);
            }

            return new AnyOf($children);
        }

        return $this->conditionLeaf($key, $value, $where, $slotKeys, $errors);
    }

    /**
     * @param list<string> $slotKeys
     * @param list<string> $errors
     */
    private function conditionLeaf(string $key, mixed $value, string $where, array $slotKeys, array &$errors): ?ConditionLeaf
    {
        if (!$this->conditions->has($key)) {
            $errors[] = \sprintf('%s: unknown condition "%s"', $where, $key);

            return null;
        }
        $where = \sprintf('%s: %s', $where, $key);
        if (!self::isMap($value)) {
            $errors[] = $where.': parameters must be a map';

            return null;
        }

        $condition = $this->conditions->get($key);
        $errorCount = \count($errors);
        $declared = [];
        $params = [];
        foreach ($condition::parameters() as $parameter) {
            $declared[] = $parameter->name;
            if (!\array_key_exists($parameter->name, $value)) {
                if ($parameter->required) {
                    $errors[] = \sprintf('%s: missing parameter "%s"', $where, $parameter->name);
                }
                continue;
            }
            $param = $value[$parameter->name];
            $error = match ($parameter->type) {
                ParameterType::Int => \is_int($param) ? null : \sprintf('parameter "%s" must be an integer', $parameter->name),
                ParameterType::String => \is_string($param) && '' !== $param ? null : \sprintf('parameter "%s" must be a non-empty string', $parameter->name),
                ParameterType::Slot => match (true) {
                    !\is_string($param) => \sprintf('parameter "%s" must be a non-empty string', $parameter->name),
                    !self::isColumn($param, $slotKeys, false) => \sprintf('unknown slot "%s"', $param),
                    default => null,
                },
            };
            if (null !== $error) {
                $errors[] = $where.': '.$error;
            }
            $params[$parameter->name] = $param;
        }
        foreach (array_keys($value) as $name) {
            if (!\in_array($name, $declared, true)) {
                $errors[] = \sprintf('%s: unknown parameter "%s"', $where, $name);
            }
        }

        return \count($errors) === $errorCount ? new ConditionLeaf($condition, $params) : null;
    }

    /**
     * @param list<string> $slotKeys
     * @param list<string> $errors
     */
    private function action(mixed $node, string $where, array $slotKeys, array &$errors): ?ActionCall
    {
        if (!\is_array($node) || 1 !== \count($node) || !\is_string(array_key_first($node))) {
            $errors[] = $where.': an action must have exactly one key';

            return null;
        }
        $name = array_key_first($node);
        $value = $node[$name];
        $type = ActionType::tryFrom($name);
        if (null === $type) {
            $errors[] = \sprintf('%s: unknown action "%s"', $where, $name);

            return null;
        }
        $where .= '.'.$name;
        if (!self::isMap($value)) {
            $errors[] = $where.': parameters must be a map';

            return null;
        }

        $errorCount = \count($errors);
        $params = [];
        $until = null;
        $declared = self::actionParameters($type);
        foreach ($declared as $param => $required) {
            if (!\array_key_exists($param, $value)) {
                if (ActionType::Pause === $type && 'until' === $param) {
                    $errors[] = $where.': a pause must carry an "until" expression';
                } elseif ($required) {
                    $errors[] = \sprintf('%s: missing parameter "%s"', $where, $param);
                }
                continue;
            }
            $given = $value[$param];
            if ('until' === $param) {
                $until = $this->expression($given, $where.'.until', $slotKeys, $errors);
                continue;
            }
            $error = match ($param) {
                'limit' => \is_int($given) ? null : 'parameter "limit" must be an integer',
                'write' => \is_string($given) && null !== ForgeWriteKind::tryFrom($given) ? null : \sprintf(
                    'parameter "write" must be one of %s',
                    implode(', ', array_map(static fn (ForgeWriteKind $kind): string => $kind->value, ForgeWriteKind::cases())),
                ),
                default => \is_string($given) && '' !== $given ? null : \sprintf('parameter "%s" must be a non-empty string', $param),
            };
            if (null !== $error) {
                $errors[] = $where.': '.$error;
            } elseif ('to' === $param && \is_string($given) && !self::isColumn($given, $slotKeys, false)) {
                $errors[] = \sprintf('%s.to: unknown slot "%s"', $where, $given);
            } elseif (\is_int($given) || \is_string($given)) {
                $params[$param] = $given;
            }
        }
        foreach (array_keys($value) as $param) {
            if (!\array_key_exists($param, $declared)) {
                $errors[] = \sprintf('%s: unknown parameter "%s"', $where, $param);
            }
        }

        return \count($errors) === $errorCount ? new ActionCall($type, $params, $until) : null;
    }

    /** @return array<string, bool> each parameter name, mapped to whether it is required */
    private static function actionParameters(ActionType $type): array
    {
        return match ($type) {
            ActionType::Move => ['to' => true],
            ActionType::Request => ['kind' => true, 'capability' => false, 'limit' => false],
            ActionType::ForgeWrite => ['write' => true, 'fallback' => true],
            ActionType::Pause => ['reason' => true, 'until' => true],
            ActionType::Release => ['reason' => true],
        };
    }

    /** @param list<string> $slotKeys */
    private static function isColumn(string $value, array $slotKeys, bool $acceptAnyColumn): bool
    {
        return \in_array($value, $slotKeys, true)
            || \in_array($value, self::COLUMN_FLAGS, true)
            || ($acceptAnyColumn && self::ANY_COLUMN === $value);
    }

    /** @phpstan-assert-if-true array<mixed> $value */
    private static function isMap(mixed $value): bool
    {
        return \is_array($value) && ([] === $value || !array_is_list($value));
    }

    /**
     * @param array<mixed> $source
     * @param list<string> $errors
     *
     * @return list<mixed>
     */
    private static function topLevelList(array $source, string $key, array &$errors): array
    {
        $value = $source[$key] ?? null;
        if (\is_array($value) && array_is_list($value)) {
            return $value;
        }
        $errors[] = self::topLevelError($source, $key, 'must be a list');

        return [];
    }

    /** @return ?list<int> */
    private static function backoffMinutes(mixed $value): ?array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return null;
        }
        $minutes = [];
        foreach ($value as $entry) {
            if (!\is_int($entry) || $entry < 1) {
                return null;
            }
            $minutes[] = $entry;
        }

        return $minutes;
    }

    /** @param array<mixed> $source */
    private static function topLevelError(array $source, string $key, string $expectation): string
    {
        return $key.': '.(\array_key_exists($key, $source) ? $expectation : 'is missing');
    }
}
