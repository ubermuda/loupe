<?php

declare(strict_types=1);

namespace App\Module\Workflow\Condition;

use App\Module\Workflow\Contract\Condition;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class Conditions
{
    /** @var array<string, Condition> */
    private array $byKey;

    /** @param iterable<Condition> $conditions */
    public function __construct(
        #[AutowireIterator('app.workflow_condition')]
        iterable $conditions,
    ) {
        $byKey = [];
        foreach ($conditions as $condition) {
            $key = $condition::key();
            if (isset($byKey[$key])) {
                throw new \LogicException(\sprintf('Two workflow conditions have the key "%s": %s and %s.', $key, $byKey[$key]::class, $condition::class));
            }
            $byKey[$key] = $condition;
        }
        $this->byKey = $byKey;
    }

    public function get(string $key): Condition
    {
        return $this->byKey[$key] ?? throw new UnknownCondition($key);
    }

    public function has(string $key): bool
    {
        return isset($this->byKey[$key]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->byKey);
    }
}
