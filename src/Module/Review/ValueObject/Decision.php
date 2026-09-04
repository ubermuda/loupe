<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * One decision block as it stands in a rendered version: the identifier the
 * document declared, the option labels in the order they are shown, the
 * question the block asks, and how many answers it takes.
 *
 * The identifier — not the option text and not the position — is what a
 * selection is keyed by, so a revision that rewords the block keeps its answer.
 */
final readonly class Decision
{
    /**
     * @param list<string> $options
     * @param string       $prompt  empty when the block declared no question
     */
    public function __construct(
        public string $id,
        public array $options,
        public string $prompt = '',
        public DecisionType $type = DecisionType::Single,
    ) {
    }

    /** What to call this block in a list of them; the id is all a promptless block has. */
    public function label(): string
    {
        return '' === $this->prompt ? $this->id : $this->prompt;
    }

    public function optionAt(int $index): ?string
    {
        return $this->options[$index] ?? null;
    }

    /**
     * Where each of a block's stored answers sits now, in the order given.
     *
     * The page, the review payload and the handler all resolve through this one
     * method. Resolving in two places by two rules is how the reviewer ends up
     * seeing one option ticked while the agent is told another.
     *
     * A set rather than one answer at a time, because a block may offer the same
     * text twice: two answers to two such options both resolve onto the first of
     * them, and reporting one option twice is not what the reviewer chose. An
     * answer left with no free place reports null, as one whose option the
     * document dropped does.
     *
     * @param list<array{string, int}> $answers each the option as it read when it was chosen, and the index it was recorded at
     *
     * @return list<int|null>
     */
    public function resolveIndexes(array $answers): array
    {
        /** @var array<int, true> $taken */
        $taken = [];
        $resolved = [];
        foreach ($answers as [$option, $recordedIndex]) {
            $index = $this->resolveIndex($option, $recordedIndex);
            if (null !== $index && isset($taken[$index])) {
                $index = $this->firstFreeIndexOf($option, $taken);
            }

            if (null !== $index) {
                $taken[$index] = true;
            }

            $resolved[] = $index;
        }

        return $resolved;
    }

    /** @param array<int, true> $taken */
    private function firstFreeIndexOf(string $option, array $taken): ?int
    {
        foreach ($this->options as $index => $candidate) {
            if ($candidate === $option && !isset($taken[$index])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Where a previously chosen option now sits, or null once it is gone.
     *
     * Read one answer at a time only where a block holds one. Everything else
     * goes through resolveIndexes(), which settles a whole block at once.
     *
     * The label leads and the recorded index breaks ties, because each alone is
     * wrong in a case the other handles: trusting the index means a revision
     * that reorders a block ticks an option the reviewer never chose, while
     * trusting the label alone collapses two options that happen to read the
     * same onto whichever comes first. So the recorded index wins only while it
     * still points at a matching label — which is exactly when reordering
     * cannot have invalidated it.
     */
    public function resolveIndex(string $option, int $recordedIndex): ?int
    {
        if (($this->options[$recordedIndex] ?? null) === $option) {
            return $recordedIndex;
        }

        $index = array_search($option, $this->options, strict: true);

        return false === $index ? null : $index;
    }
}
