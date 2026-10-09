<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Review\Entity\DocumentStatus;
use App\Module\Workflow\Action\ActionOutcome;
use App\Module\Workflow\Action\ActionParams;
use App\Module\Workflow\Condition\CardHasType;
use App\Module\Workflow\Condition\Conditions;
use App\Module\Workflow\Contract\FactKey;
use App\Module\Workflow\Contract\LabelTone;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Contract\ParameterValue;
use App\Module\Workflow\Contract\WorkKind;
use App\Module\Workflow\Expression\AllOf;
use App\Module\Workflow\Expression\AnyOf;
use App\Module\Workflow\Expression\ConditionLeaf;
use App\Module\Workflow\Expression\Expression;
use App\Module\Workflow\Expression\MissingConditionLeaf;
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
    private const array WRITES_WITHOUT_FALLBACK = ['draft', 'ready', 'close', 'open-epic'];
    private const array ON_TIMEOUT = ['pause', 'expire'];
    private const array EVALUATED_CARDS = ['children'];
    private const array OPTION_ACTIONS = [ActionType::LinkDocument, ActionType::Detach, ActionType::Move];
    private const string LINK_SOURCE = 'parent';
    private const array TYPE_KEYS = ['key', 'label', 'tone', 'capabilities'];
    private const int TYPE_KEY_MAX_LENGTH = 20;
    private const array TYPE_CAPABILITIES = ['children', 'lane'];

    /** The parameters that hold the tag and the status of a request's `document` map. A template cannot write them. */
    public const string DOCUMENT_TAG = 'document.tag';
    public const string DOCUMENT_STATUS = 'document.status';

    /** The parameter of an app request that names a prompt file. A template cannot write it. */
    public const string PROMPT = 'prompt';
    private const string PROMPT_PATTERN = '/^[a-z][a-z0-9-]{0,39}$/D';

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
        return $this->parseWith($source, lenient: false);
    }

    /**
     * Parses a stored copy. A condition this instance no longer has becomes a leaf that is never readable.
     *
     * @param array<mixed> $source
     *
     * @throws InvalidTemplate on every other error
     */
    public function parseStored(array $source): Template
    {
        return $this->parseWith($source, lenient: true);
    }

    /**
     * Parses the rules the app adds to every template. They act in the backlog, in a terminal column or in every column.
     *
     * @param array<mixed> $source
     *
     * @return list<Rule>
     *
     * @throws InvalidTemplate
     */
    public function parseAppRules(array $source): array
    {
        $errors = [];
        foreach (array_keys($source) as $key) {
            if (!\in_array($key, ['rules', 'requests'], true)) {
                $errors[] = \sprintf('%s: unknown key, app rules hold only "rules" and "requests"', $key);
            }
        }
        $rules = $this->rules(self::topLevelList($source, 'rules', $errors), [], null, $errors, lenient: false, app: true);

        if ([] !== $errors) {
            throw new InvalidTemplate($errors);
        }

        return $rules;
    }

    /**
     * Parses the work the app asks a bridge for outside the engine. The key is optional.
     *
     * @param array<mixed> $source
     *
     * @return list<AppRequest>
     *
     * @throws InvalidTemplate
     */
    public function parseAppRequests(array $source): array
    {
        $given = $source['requests'] ?? [];
        if (!\is_array($given) || !array_is_list($given)) {
            throw new InvalidTemplate(['requests: must be a list']);
        }

        $errors = [];
        $requests = [];
        $seen = [];
        foreach ($given as $index => $entry) {
            $where = \sprintf('requests[%d]', $index);
            if (!self::isMap($entry)) {
                $errors[] = $where.': must be a map';
                continue;
            }
            $errorCount = \count($errors);

            $id = $entry['id'] ?? null;
            if (!\is_string($id) || '' === $id) {
                $errors[] = $where.' id: must be a non-empty string';
            } else {
                $where .= \sprintf(' (%s)', $id);
                if (isset($seen[$id])) {
                    $errors[] = \sprintf('%s: duplicate request id "%s"', $where, $id);
                }
                $seen[$id] = true;
            }
            $kind = $entry['kind'] ?? null;
            if (!\is_string($kind) || '' === $kind) {
                $errors[] = $where.': parameter "kind" must be a non-empty string';
            }
            $prompt = $entry['prompt'] ?? null;
            if (!\is_string($prompt) || 1 !== preg_match(self::PROMPT_PATTERN, $prompt)) {
                $errors[] = $where.': parameter "prompt" must match [a-z][a-z0-9-], at most 40 characters';
            }
            $checks = self::checks($entry['checks'] ?? null, $where, $errors);
            foreach (array_keys($entry) as $name) {
                if (!\in_array($name, ['id', 'kind', 'prompt', 'checks'], true)) {
                    $errors[] = \sprintf('%s: unknown key "%s"', $where, $name);
                }
            }

            if (\count($errors) === $errorCount && \is_string($id) && \is_string($kind) && \is_string($prompt)) {
                $requests[] = new AppRequest($id, $kind, $prompt, $checks);
            }
        }

        if ([] !== $errors) {
            throw new InvalidTemplate($errors);
        }

        return $requests;
    }

    /** @param array<mixed> $source */
    private function parseWith(array $source, bool $lenient): Template
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

        $types = self::types($source, $errors);
        $defaultType = $source['defaultType'] ?? null;
        if (!\is_string($defaultType) || '' === $defaultType) {
            $errors[] = self::topLevelError($source, 'defaultType', 'must be a non-empty string');
            $defaultType = '';
        } elseif (null !== $types && !isset($types[$defaultType])) {
            $errors[] = \sprintf('defaultType: unknown type "%s"', $defaultType);
        } elseif (null !== $types && $types[$defaultType]->children) {
            // A widget note under a parent creates a card of the default type, and a parent-capable card cannot have a parent.
            $errors[] = \sprintf('defaultType: type "%s" may have children', $defaultType);
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

        $onWorkFailed = self::onWorkFailed($source['onWorkFailed'] ?? null, $errors);

        $slots = $this->slots(self::topLevelList($source, 'slots', $errors), $errors);
        $slotKeys = array_map(static fn (Slot $slot): string => $slot->key, $slots);
        $manualMoves = $this->manualMoves(self::topLevelList($source, 'manualMoves', $errors), $slotKeys, $errors);
        $rules = $this->rules(self::topLevelList($source, 'rules', $errors), $slotKeys, $types, $errors, $lenient, app: false);
        // The engine tells a repair request apart by its kind, so no rule may ask for that kind.
        foreach ($rules as $rule) {
            $kind = match ($rule->then->type) {
                ActionType::Request => ActionParams::optionalString($rule, 'kind'),
                ActionType::ForgeWrite => ActionParams::optionalString($rule, 'fallback'),
                default => null,
            };
            if (null !== $onWorkFailed?->repairKind && $onWorkFailed->repairKind === $kind) {
                $errors[] = \sprintf('onWorkFailed.repair.kind: the rule "%s" already asks for the kind "%s"', $rule->id, $onWorkFailed->repairKind);
            }
        }

        if ([] !== $errors) {
            throw new InvalidTemplate($errors);
        }

        return new Template($key, $version, $slots, $rules, $manualMoves, $backoffMinutes, $workTimeoutMinutes, array_values($types ?? []), $defaultType, $onWorkFailed);
    }

    /**
     * @param array<mixed> $source
     * @param list<string> $errors
     *
     * @return ?array<string, TemplateCardType> each type by its key, or null when the block holds an error
     */
    private static function types(array $source, array &$errors): ?array
    {
        $value = $source['types'] ?? null;
        if (!\is_array($value) || [] === $value || !array_is_list($value)) {
            $errors[] = self::topLevelError($source, 'types', 'must be a non-empty list');

            return null;
        }
        $errorCount = \count($errors);
        $types = [];
        foreach ($value as $index => $entry) {
            $where = \sprintf('types[%d]', $index);
            $key = self::isMap($entry) ? ($entry['key'] ?? null) : null;
            $label = self::isMap($entry) ? ($entry['label'] ?? null) : null;
            if (!self::isMap($entry) || !\is_string($key) || '' === $key || !\is_string($label) || '' === $label) {
                $errors[] = $where.': must be a map with a string "key" and a string "label"';
                continue;
            }
            $where .= \sprintf(' (%s)', $key);
            $entryErrorCount = \count($errors);
            if (mb_strlen($key) > self::TYPE_KEY_MAX_LENGTH) {
                $errors[] = \sprintf('%s: "key" must have at most %d characters', $where, self::TYPE_KEY_MAX_LENGTH);
            }
            foreach (array_keys($entry) as $name) {
                if (!\in_array($name, self::TYPE_KEYS, true)) {
                    $errors[] = \sprintf('%s: unknown key "%s"', $where, $name);
                }
            }
            $tone = $entry['tone'] ?? null;
            $tone = \is_string($tone) ? LabelTone::tryFrom($tone) : null;
            if (null === $tone) {
                $errors[] = \sprintf('%s: "tone" must be one of %s', $where, implode(', ', array_column(LabelTone::cases(), 'value')));
            }
            $capabilities = $entry['capabilities'] ?? [];
            if (!\is_array($capabilities) || !array_is_list($capabilities)
                || array_any($capabilities, static fn (mixed $capability): bool => !\in_array($capability, self::TYPE_CAPABILITIES, true))) {
                $errors[] = \sprintf('%s: "capabilities" must be a list of %s', $where, implode(', ', self::TYPE_CAPABILITIES));
                $capabilities = [];
            }
            if (isset($types[$key])) {
                $errors[] = \sprintf('%s: duplicate type key "%s"', $where, $key);
            }
            if (null !== $tone && \count($errors) === $entryErrorCount) {
                $types[$key] = new TemplateCardType($key, $label, $tone, \in_array('children', $capabilities, true), \in_array('lane', $capabilities, true));
            }
        }

        return \count($errors) === $errorCount ? $types : null;
    }

    /**
     * @param array<string, TemplateCardType> $types
     *
     * @return list<string> the keys of the types without children that the rule reads the children of
     */
    private static function childlessTypesReadingChildren(Rule $rule, array $types): array
    {
        $evaluatesChildren = ActionType::Evaluate === $rule->then->type && 'children' === ($rule->then->params['cards'] ?? null);
        // The engine reads a pause `until` and a request `refill` on a card that the `when` matched.
        $ways = self::typeReads($rule->when);
        foreach (array_filter([$rule->then->until, $rule->then->refill]) as $expression) {
            $ways += self::typeReads(new AllOf([$rule->when, $expression]));
        }
        $found = [];
        foreach ($ways as [$key, $readsChildren]) {
            $type = null === $key ? null : ($types[$key] ?? null);
            if (null !== $type && !$type->children && ($readsChildren || $evaluatesChildren)) {
                $found[$type->key] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * Each way the expression can hold, as the one type it names and whether it reads the children.
     * The set stays small, because a pair repeats and a way that names two types never holds.
     * A `not` names no type, because it turns the type leaves under it around.
     *
     * @return array<string, array{?string, bool}>
     */
    private static function typeReads(Expression $expression): array
    {
        if ($expression instanceof AnyOf) {
            return array_merge(...array_map(self::typeReads(...), $expression->children));
        }
        if ($expression instanceof AllOf) {
            $ways = self::typeRead(null, false);
            foreach ($expression->children as $child) {
                $next = [];
                foreach ($ways as [$type, $reads]) {
                    foreach (self::typeReads($child) as [$childType, $childReads]) {
                        if (null === $type || null === $childType || $type === $childType) {
                            $next += self::typeRead($type ?? $childType, $reads || $childReads);
                        }
                    }
                }
                $ways = $next;
            }

            return $ways;
        }
        $type = $expression instanceof ConditionLeaf && $expression->condition instanceof CardHasType ? ParameterValue::string($expression->params, 'type') : null;

        return self::typeRead($type, \in_array(FactKey::Children, $expression->reads(), true));
    }

    /** @return array<string, array{?string, bool}> */
    private static function typeRead(?string $type, bool $readsChildren): array
    {
        return [json_encode([$type, $readsChildren], \JSON_THROW_ON_ERROR) => [$type, $readsChildren]];
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
            $byValue = \is_array($entry) ? ($entry['by'] ?? null) : null;
            $by = null;
            if (null !== $byValue) {
                $by = \is_string($byValue) ? ManualMoveActor::tryFrom($byValue) : null;
                if (null === $by) {
                    $errors[] = \sprintf('%s.by: must be one of %s', $where, implode(', ', array_column(ManualMoveActor::cases(), 'value')));
                    $valid = false;
                }
            }
            if ($valid) {
                $moves[] = new ManualMove($from, $to, $by);
            }
        }

        return $moves;
    }

    /**
     * @param list<mixed>                      $source
     * @param list<string>                     $slotKeys
     * @param ?array<string, TemplateCardType> $types    null skips the type checks
     * @param list<string>                     $errors
     *
     * @return list<Rule>
     */
    private function rules(array $source, array $slotKeys, ?array $types, array &$errors, bool $lenient, bool $app): array
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
                $when = $this->expression($entry['when'], $where.' when', $slotKeys, $types, $errors, $lenient);
            } else {
                $errors[] = $where.' when: is missing';
            }

            $then = null;
            if (\array_key_exists('then', $entry)) {
                $then = $this->action($entry['then'], $where.' then', $slotKeys, $types, $errors, $lenient, $app);
            } else {
                $errors[] = $where.' then: is missing';
            }

            if (\count($errors) === $errorCount && null !== $when && null !== $then && (null === $slot || \is_string($slot))) {
                $rule = new Rule($id, $slot, $when, $then, $app ? RuleOrigin::App : RuleOrigin::Template);
                $rules[] = $rule;
                foreach (null === $types ? [] : self::childlessTypesReadingChildren($rule, $types) as $type) {
                    $errors[] = \sprintf('%s: the type "%s" may not have children, but the rule reads them', $where, $type);
                }
            }
        }

        return $rules;
    }

    /**
     * @param list<string>                     $slotKeys
     * @param ?array<string, TemplateCardType> $types
     * @param list<string>                     $errors
     */
    private function expression(mixed $node, string $where, array $slotKeys, ?array $types, array &$errors, bool $lenient): ?Expression
    {
        if (!\is_array($node) || 1 !== \count($node) || !\is_string(array_key_first($node))) {
            $errors[] = $where.': a node must have exactly one key';

            return null;
        }
        $key = array_key_first($node);
        $value = $node[$key];

        if ('not' === $key) {
            $inner = $this->expression($value, $where.'.not', $slotKeys, $types, $errors, $lenient);

            return null === $inner ? null : new Not($inner);
        }

        if ('all' === $key || 'any' === $key) {
            if (!\is_array($value) || !array_is_list($value) || ('any' === $key && [] === $value)) {
                $errors[] = \sprintf('%s.%s: must be a %slist', $where, $key, 'any' === $key ? 'non-empty ' : '');

                return null;
            }
            $children = [];
            foreach ($value as $index => $child) {
                $children[] = $this->expression($child, \sprintf('%s.%s[%d]', $where, $key, $index), $slotKeys, $types, $errors, $lenient);
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

        if ($lenient && !$this->conditions->has($key)) {
            return new MissingConditionLeaf($key, $value);
        }

        return $this->conditionLeaf($key, $value, $where, $slotKeys, $types, $errors);
    }

    /**
     * @param list<string>                     $slotKeys
     * @param ?array<string, TemplateCardType> $types
     * @param list<string>                     $errors
     */
    private function conditionLeaf(string $key, mixed $value, string $where, array $slotKeys, ?array $types, array &$errors): ?ConditionLeaf
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
                ParameterType::Int => \is_int($param) && $param >= 1 ? null : \sprintf('parameter "%s" must be a positive integer', $parameter->name),
                ParameterType::String => match (true) {
                    !\is_string($param) || '' === $param => \sprintf('parameter "%s" must be a non-empty string', $parameter->name),
                    null !== $parameter->choices && !\in_array($param, $parameter->choices, true) => \sprintf('parameter "%s" must be one of: %s', $parameter->name, implode(', ', $parameter->choices)),
                    default => null,
                },
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
        if (\count($errors) === $errorCount && null !== $types && $condition instanceof CardHasType && !isset($types[ParameterValue::string($params, 'type')])) {
            $errors[] = \sprintf('%s: unknown type "%s"', $where, ParameterValue::string($params, 'type'));
        }

        return \count($errors) === $errorCount ? new ConditionLeaf($condition, $params) : null;
    }

    /**
     * @param list<string>                     $slotKeys
     * @param ?array<string, TemplateCardType> $types
     * @param list<string>                     $errors
     */
    private function action(mixed $node, string $where, array $slotKeys, ?array $types, array &$errors, bool $lenient, bool $app): ?ActionCall
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
        $checks = [];
        $refill = null;
        $options = [];
        $declared = self::actionParameters($type);
        if ($app && ActionType::Request === $type) {
            $declared[self::PROMPT] = false;
        }
        foreach ($declared as $param => $required) {
            if (!\array_key_exists($param, $value)) {
                if (ActionType::Pause === $type && 'until' === $param) {
                    $errors[] = $where.': a pause must carry an "until" expression';
                } elseif ($required && !('fallback' === $param && \in_array($value['write'] ?? null, self::WRITES_WITHOUT_FALLBACK, true))) {
                    $errors[] = \sprintf('%s: missing parameter "%s"', $where, $param);
                }
                continue;
            }
            $given = $value[$param];
            if ('until' === $param) {
                $until = $this->expression($given, $where.'.until', $slotKeys, $types, $errors, $lenient);
                continue;
            }
            if ('refill' === $param) {
                if (!\array_key_exists('limit', $value)) {
                    $errors[] = $where.': parameter "refill" needs a "limit"';
                } else {
                    $refill = $this->expression($given, $where.'.refill', $slotKeys, $types, $errors, $lenient);
                }
                continue;
            }
            if ('options' === $param) {
                $options = $this->askOptions($given, $where, $slotKeys, $types, $errors, $lenient);
                continue;
            }
            if (ActionType::LinkDocument === $type && 'from' === $param) {
                if (self::LINK_SOURCE !== $given) {
                    $errors[] = \sprintf('%s: parameter "from" must be %s', $where, self::LINK_SOURCE);
                } else {
                    $params['from'] = $given;
                }
                continue;
            }
            if ('document' === $param) {
                $params += self::document($given, $where, $errors);
                continue;
            }
            if ('checks' === $param) {
                $checks = self::checks($given, $where, $errors);
                continue;
            }
            $error = match ($param) {
                'limit' => \is_int($given) && $given >= 1 ? null : 'parameter "limit" must be a positive integer',
                'write' => \is_string($given) && null !== ForgeWriteKind::tryFrom($given) ? null : \sprintf(
                    'parameter "write" must be one of %s',
                    implode(', ', array_map(static fn (ForgeWriteKind $kind): string => $kind->value, ForgeWriteKind::cases())),
                ),
                'onTimeout' => \in_array($given, self::ON_TIMEOUT, true) ? null : \sprintf('parameter "onTimeout" must be one of %s', implode(', ', self::ON_TIMEOUT)),
                self::PROMPT => \is_string($given) && 1 === preg_match(self::PROMPT_PATTERN, $given) ? null : 'parameter "prompt" must match [a-z][a-z0-9-], at most 40 characters',
                'cards' => \in_array($given, self::EVALUATED_CARDS, true) ? null : \sprintf('parameter "cards" must be one of %s', implode(', ', self::EVALUATED_CARDS)),
                default => \is_string($given) && '' !== $given ? null : \sprintf('parameter "%s" must be a non-empty string', $param),
            };
            if (null !== $error) {
                $errors[] = $where.': '.$error;
            } elseif (\in_array($param, ['to', 'from'], true) && \is_string($given) && !self::isColumn($given, $slotKeys, false)) {
                $errors[] = \sprintf('%s.%s: unknown slot "%s"', $where, $param, $given);
            } elseif (\is_int($given) || \is_string($given)) {
                $params[$param] = $given;
            }
        }
        foreach (array_keys($value) as $param) {
            if (!\array_key_exists($param, $declared)) {
                $errors[] = \sprintf('%s: unknown parameter "%s"', $where, $param);
            }
        }

        return \count($errors) === $errorCount ? new ActionCall($type, $params, $until, checks: $checks, refill: $refill, options: $options) : null;
    }

    /**
     * @param list<string>                     $slotKeys
     * @param ?array<string, TemplateCardType> $types
     * @param list<string>                     $errors
     *
     * @return list<AskOption>
     */
    private function askOptions(mixed $given, string $where, array $slotKeys, ?array $types, array &$errors, bool $lenient): array
    {
        if (!\is_array($given) || [] === $given || !array_is_list($given)) {
            $errors[] = $where.': parameter "options" must be a non-empty list of maps with a "label" and a "then" list';

            return [];
        }
        $options = [];
        foreach ($given as $index => $entry) {
            $optionWhere = \sprintf('%s.options[%d]', $where, $index);
            $label = self::isMap($entry) ? ($entry['label'] ?? null) : null;
            $then = self::isMap($entry) ? ($entry['then'] ?? null) : null;
            if (!\is_string($label) || '' === $label || !\is_array($then) || [] === $then || !array_is_list($then)) {
                $errors[] = $optionWhere.': must be a map with a non-empty string "label" and a non-empty "then" list';
                continue;
            }
            $unknownKeys = array_diff(array_keys($entry), ['label', 'then']);
            foreach ($unknownKeys as $name) {
                $errors[] = \sprintf('%s: unknown key "%s"', $optionWhere, $name);
            }
            $actions = [];
            foreach ($then as $position => $node) {
                $actionWhere = \sprintf('%s.then[%d]', $optionWhere, $position);
                $name = \is_array($node) && 1 === \count($node) ? array_key_first($node) : null;
                $type = \is_string($name) ? ActionType::tryFrom($name) : null;
                if (null !== $type && !\in_array($type, self::OPTION_ACTIONS, true)) {
                    $errors[] = \sprintf('%s: the action "%s" is not allowed inside an ask option', $actionWhere, $name);
                    continue;
                }
                $action = $this->action($node, $actionWhere, $slotKeys, $types, $errors, $lenient, app: false);
                if (null !== $action) {
                    $actions[] = $action;
                }
            }
            if ([] !== $unknownKeys || \count($actions) !== \count($then)) {
                continue;
            }
            $options[] = new AskOption($label, $actions);
        }

        return $options;
    }

    /** @return array<string, bool> each parameter name, mapped to whether it is required. A state write and the epic opening need no fallback. */
    private static function actionParameters(ActionType $type): array
    {
        return match ($type) {
            ActionType::Move => ['to' => true, 'from' => false],
            ActionType::Request => ['kind' => true, 'capability' => false, 'limit' => false, 'refill' => false, 'onTimeout' => false, 'document' => false, 'checks' => false],
            ActionType::ForgeWrite => ['write' => true, 'fallback' => true],
            ActionType::Pause => ['reason' => true, 'until' => true],
            ActionType::Release => ['reason' => true],
            ActionType::Evaluate => ['cards' => true],
            ActionType::Ask => ['question' => true, 'options' => true],
            ActionType::LinkDocument => ['from' => true, 'tag' => true],
            ActionType::Detach => [],
        };
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, string> the tag, and the status when the map names one
     */
    private static function document(mixed $given, string $where, array &$errors): array
    {
        if (!self::isMap($given) || !\array_key_exists('tag', $given) || [] !== array_diff(array_keys($given), ['tag', 'status'])) {
            $errors[] = $where.': parameter "document" must be a map with the key tag, and optionally status';

            return [];
        }
        if (!\is_string($given['tag']) || '' === $given['tag']) {
            $errors[] = $where.'.document: parameter "tag" must be a non-empty string';

            return [];
        }
        if (!\array_key_exists('status', $given)) {
            return [self::DOCUMENT_TAG => $given['tag']];
        }
        $status = \is_string($given['status']) ? DocumentStatus::tryFrom($given['status']) : null;
        if (null === $status) {
            $errors[] = \sprintf('%s.document: parameter "status" must be one of %s', $where, implode(', ', array_map(static fn (DocumentStatus $s): string => $s->value, DocumentStatus::cases())));

            return [];
        }

        return [self::DOCUMENT_TAG => $given['tag'], self::DOCUMENT_STATUS => $status->value];
    }

    /**
     * @param list<string> $errors
     *
     * @return list<string>
     */
    private static function checks(mixed $given, string $where, array &$errors): array
    {
        if (!\is_array($given) || [] === $given || !array_is_list($given)) {
            $errors[] = $where.': parameter "checks" must be a non-empty list of non-empty strings';

            return [];
        }
        $checks = [];
        foreach ($given as $check) {
            if (!\is_string($check) || '' === $check) {
                $errors[] = $where.': parameter "checks" must be a non-empty list of non-empty strings';

                return [];
            }
            $checks[] = $check;
        }

        return $checks;
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

    /**
     * A template with no block retries nothing and pauses nothing for a settled refusal.
     *
     * @param list<string> $errors
     */
    private static function onWorkFailed(mixed $value, array &$errors): ?WorkFailurePolicy
    {
        if (null === $value) {
            return null;
        }
        $retryOn = \is_array($value) ? ($value['retryOn'] ?? null) : null;
        $retries = \is_array($value) ? ($value['retries'] ?? null) : null;
        if (!\is_array($value) || !\is_array($retryOn) || !array_is_list($retryOn)) {
            $errors[] = 'onWorkFailed: must be a map with a list "retryOn"';

            return null;
        }
        foreach ($retryOn as $code) {
            if (!\is_string($code) || 1 !== preg_match(ActionOutcome::CODE_PATTERN, $code)) {
                $errors[] = 'onWorkFailed.retryOn: each entry must be a refusal code';

                return null;
            }
        }
        if (!\is_int($retries) || $retries < 0) {
            $errors[] = 'onWorkFailed.retries: must be a non-negative integer';

            return null;
        }
        $backoffMinutes = self::backoffMinutes($value['backoffMinutes'] ?? null);
        if (null === $backoffMinutes) {
            $errors[] = 'onWorkFailed.backoffMinutes: must be a list of positive integers';

            return null;
        }
        $repair = $value['repair'] ?? null;
        if (null === $repair) {
            return new WorkFailurePolicy($retryOn, $retries, $backoffMinutes);
        }
        $repairKind = \is_array($repair) ? ($repair['kind'] ?? null) : null;
        if (!\is_string($repairKind)) {
            $errors[] = 'onWorkFailed.repair: must be a map with a string "kind"';

            return null;
        }
        if (1 !== preg_match(WorkKind::PATTERN, $repairKind)) {
            $errors[] = 'onWorkFailed.repair.kind: must be a work request kind';

            return null;
        }

        return new WorkFailurePolicy($retryOn, $retries, $backoffMinutes, $repairKind);
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
