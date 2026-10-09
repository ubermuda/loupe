<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** What an action reads when it runs for one rule on one card. The template parser already checked the parameters. */
final readonly class ActionContext
{
    /**
     * @param array<string, mixed>     $params  the parameters of the action, and the labels of the options of an ask under "options"
     * @param array<string, ColumnRef> $columns the column of each slot parameter that has one, by parameter name
     * @param ?string                  $prompt  the text of the prompt that an app rule names, or null
     */
    public function __construct(
        public CardSnapshot $card,
        public string $ruleId,
        public bool $appRule,
        public array $params,
        public Facts $facts,
        public int $fires,
        public array $columns = [],
        public ?string $prompt = null,
    ) {
    }

    public function column(string $name): ?ColumnRef
    {
        return $this->columns[$name] ?? null;
    }

    public function string(string $name): string
    {
        $value = $this->params[$name] ?? null;

        return \is_string($value) ? $value : throw new \LogicException(\sprintf('The rule "%s" has no string parameter "%s".', $this->ruleId, $name));
    }

    public function optionalString(string $name): ?string
    {
        return \array_key_exists($name, $this->params) ? $this->string($name) : null;
    }

    public function optionalInt(string $name): ?int
    {
        $value = $this->params[$name] ?? null;
        if (null !== $value && !\is_int($value)) {
            throw new \LogicException(\sprintf('The rule "%s" has no integer parameter "%s".', $this->ruleId, $name));
        }

        return $value;
    }

    /** @return list<string> the labels of the options of an ask, in template order */
    public function options(): array
    {
        $options = $this->params['options'] ?? [];

        return \is_array($options) ? array_values(array_filter($options, \is_string(...))) : [];
    }
}
