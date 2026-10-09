<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** What the engine knows about one card at one moment. Conditions read it and nothing else. */
final readonly class Facts
{
    /**
     * @param ?string                                $slot         a slot key, '@backlog', '@terminal', or null for any other column
     * @param ?string                                $parentSlot   the slot of the parent card, as $slot is for this card, or null with no parent
     * @param ?PullRequestFacts                      $pullRequest  the pull request a rule acts on
     * @param array<class-string, object|Unreadable> $provided     what each fact provider gave, keyed by its facts class
     * @param array<class-string, mixed>             $fingerprints the fingerprint of each readable provided facts, keyed by its facts class
     * @param array<class-string, string>            $legacyGroups the old group name of each provided facts class that replaced one
     */
    public function __construct(
        public \DateTimeImmutable $now,
        public ?string $slot,
        public ?string $parentSlot,
        public ?PullRequestFacts $pullRequest,
        public array $provided = [],
        public array $fingerprints = [],
        public array $legacyGroups = [],
    ) {
    }

    /** A copy that reads another pull request as the one the card acts on. */
    public function withPullRequest(?PullRequestFacts $pullRequest): self
    {
        return new self($this->now, $this->slot, $this->parentSlot, $pullRequest, $this->provided, $this->fingerprints, $this->legacyGroups);
    }

    /**
     * A copy that reads other facts for the given classes. A class the copy names replaces what the provider gave.
     *
     * @param array<class-string, object|Unreadable> $provided
     * @param array<class-string, mixed>             $fingerprints the fingerprint of each replaced facts, when the copy is fingerprinted
     */
    public function withProvided(array $provided, array $fingerprints = []): self
    {
        $kept = array_diff_key($this->fingerprints, $provided);

        return new self($this->now, $this->slot, $this->parentSlot, $this->pullRequest, [...$this->provided, ...$provided], [...$kept, ...$fingerprints], $this->legacyGroups);
    }

    /** @return list<PullRequestFacts> every pull request linked to the card, empty when the list cannot be read */
    public function pullRequests(): array
    {
        $list = $this->provided[PullRequestList::class] ?? null;

        return $list instanceof PullRequestList ? $list->pullRequests : [];
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
