<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Records each dispatched event of one class, and the transaction depth at that moment.
 *
 * @template T of object
 */
final class DispatchedEvents
{
    /** @var list<T> */
    private array $events = [];

    /** @var list<int> */
    private array $transactionDepths = [];

    /**
     * @param class-string<T> $class
     */
    private function __construct(
        private readonly string $class,
    ) {
    }

    /**
     * @template E of object
     *
     * @param class-string<E> $class
     *
     * @return self<E>
     */
    public static function of(ContainerInterface $container, string $class): self
    {
        $dispatcher = $container->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $em = $container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        $recorded = new self($class);
        $dispatcher->addListener($class, static fn (object $event) => $recorded->record($event, $em->getConnection()->getTransactionNestingLevel()));

        return $recorded;
    }

    /** @return list<T> */
    public function events(): array
    {
        return $this->events;
    }

    /** @return list<int> */
    public function transactionDepths(): array
    {
        return $this->transactionDepths;
    }

    private function record(object $event, int $depth): void
    {
        \assert($event instanceof $this->class);
        $this->events[] = $event;
        $this->transactionDepths[] = $depth;
    }
}
