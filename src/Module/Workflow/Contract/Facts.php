<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** What the engine knows about one card at one moment. Conditions read it and nothing else. */
final readonly class Facts
{
    /**
     * @param list<PullRequestFacts>                 $pullRequests every pull request linked to the card
     * @param array<class-string, object|Unreadable> $provided     what each fact provider gave, keyed by its facts class
     * @param array<class-string, mixed>             $fingerprints the fingerprint of each readable provided facts, keyed by its facts class
     */
    public function __construct(
        public \DateTimeImmutable $now,
        public CardFacts $card,
        public ?PullRequestFacts $pullRequest,
        public array $pullRequests,
        public RunFacts $run,
        public array $provided = [],
        public array $fingerprints = [],
    ) {
    }

    /** A copy that reads another pull request as the one the card acts on. */
    public function withPullRequest(?PullRequestFacts $pullRequest): self
    {
        return new self($this->now, $this->card, $pullRequest, $this->pullRequests, $this->run, $this->provided, $this->fingerprints);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function get(string $class): object
    {
        $facts = $this->provided[$class] ?? throw new \LogicException(\sprintf('No fact provider gives "%s".', $class));
        if (!$facts instanceof $class) {
            throw new \LogicException(\sprintf('The facts "%s" are unreadable.', $class));
        }

        return $facts;
    }

    /** Null when the provider gave its facts, or when no provider gives the class. */
    public function unreadable(string $class): ?Unreadable
    {
        $facts = $this->provided[$class] ?? null;

        return $facts instanceof Unreadable ? $facts : null;
    }
}
