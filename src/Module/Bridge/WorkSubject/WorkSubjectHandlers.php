<?php

declare(strict_types=1);

namespace App\Module\Bridge\WorkSubject;

use App\Module\Bridge\ValueObject\WorkSubject;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Finds the handler of a subject type among the ones the modules register. */
final class WorkSubjectHandlers
{
    /** @var array<string, WorkSubjectHandlerInterface> */
    private array $byType = [];

    /** @param iterable<WorkSubjectHandlerInterface> $handlers */
    public function __construct(#[AutowireIterator(WorkSubjectHandlerInterface::TAG)] iterable $handlers)
    {
        foreach ($handlers as $handler) {
            $this->byType[$handler::subjectType()] = $handler;
        }
    }

    /** Whether a work request may name the subject type. */
    public function has(string $subjectType): bool
    {
        return WorkSubject::CARD === $subjectType || isset($this->byType[$subjectType]);
    }

    /** Null for a card. Throws for a type that no module registers. */
    public function for(string $subjectType): ?WorkSubjectHandlerInterface
    {
        if (WorkSubject::CARD === $subjectType) {
            return null;
        }

        return $this->byType[$subjectType] ?? throw new \LogicException(\sprintf('No handler for the subject type %s.', $subjectType));
    }
}
