<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Fact;

use App\Module\Workflow\Contract\FactProvider;
use Symfony\Component\Uid\Uuid;

/** A fact provider that a test switches at runtime: it gives $facts, throws $failure, or is off. */
final class ProvidedFactsProvider implements FactProvider
{
    public ProvidedFacts $facts;

    public ?\Throwable $failure = null;

    public bool $on = true;

    public function __construct()
    {
        $this->facts = new ProvidedFacts();
    }

    #[\Override]
    public function factsClass(): string
    {
        return ProvidedFacts::class;
    }

    #[\Override]
    public function isOn(): bool
    {
        return $this->on;
    }

    #[\Override]
    public function build(Uuid $cardId): object
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->facts;
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        if (!$facts instanceof ProvidedFacts) {
            throw new \LogicException('The provider fingerprints its own facts.');
        }

        return [$facts->ready, $facts->version];
    }

    #[\Override]
    public function source(): string
    {
        return 'workflow.source.board';
    }
}
