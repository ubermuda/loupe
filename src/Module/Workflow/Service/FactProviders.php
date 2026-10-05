<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\FactProvider;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class FactProviders
{
    /** @var array<class-string, FactProvider> */
    public array $byClass;

    /** @param iterable<FactProvider> $providers */
    public function __construct(
        #[AutowireIterator('app.workflow_fact_provider')]
        iterable $providers,
    ) {
        $byClass = [];
        foreach ($providers as $provider) {
            $class = $provider->factsClass();
            if (isset($byClass[$class])) {
                throw new \LogicException(\sprintf('Two workflow fact providers give "%s": %s and %s.', $class, $byClass[$class]::class, $provider::class));
            }
            $byClass[$class] = $provider;
        }
        $this->byClass = $byClass;
    }

    public function get(string $class): FactProvider
    {
        return $this->byClass[$class] ?? throw new \LogicException(\sprintf('No workflow fact provider gives "%s".', $class));
    }
}
