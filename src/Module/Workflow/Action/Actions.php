<?php

declare(strict_types=1);

namespace App\Module\Workflow\Action;

use App\Module\Workflow\Contract\Action;
use App\Module\Workflow\Contract\ParameterType;
use App\Module\Workflow\Expression\Expression;
use App\Module\Workflow\Template\ActionCall;
use App\Module\Workflow\Template\AskOption;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The actions of this instance by key. It reads the tagged services on first use, because the template
 * parser holds it and an action reaches the parser through the rule files.
 */
final class Actions
{
    /** @var ?array<string, Action> */
    private ?array $byKey = null;

    /** @param iterable<Action> $actions */
    public function __construct(
        #[AutowireIterator('app.workflow_action')]
        private readonly iterable $actions,
    ) {
    }

    public function get(string $key): Action
    {
        return $this->index()[$key] ?? throw new \LogicException(\sprintf('No workflow action has the key "%s".', $key));
    }

    public function has(string $key): bool
    {
        return isset($this->index()[$key]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->index());
    }

    /**
     * Builds the call of an action with the traits and the `from` slot its declarations give.
     *
     * @param array<string, int|string> $params
     * @param list<string>              $checks
     * @param list<AskOption>           $options
     */
    public function call(string $key, array $params, ?Expression $until = null, array $checks = [], ?Expression $refill = null, array $options = []): ActionCall
    {
        $action = $this->get($key);
        $from = null;
        foreach ($action::parameters() as $parameter) {
            if ('from' === $parameter->name && ParameterType::Slot === $parameter->type && \is_string($params['from'] ?? null)) {
                $from = $params['from'];
            }
        }

        return new ActionCall($key, $params, $until, $checks, $refill, $options, $action::traits(), $from);
    }

    /** @return array<string, Action> */
    private function index(): array
    {
        if (null !== $this->byKey) {
            return $this->byKey;
        }
        $byKey = [];
        foreach ($this->actions as $action) {
            $key = $action::key();
            if (isset($byKey[$key])) {
                throw new \LogicException(\sprintf('Two workflow actions have the key "%s": %s and %s.', $key, $byKey[$key]::class, $action::class));
            }
            $byKey[$key] = $action;
        }

        return $this->byKey = $byKey;
    }
}
