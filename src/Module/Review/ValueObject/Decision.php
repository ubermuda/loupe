<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * One decision block as it stands in a rendered version: the identifier the
 * document declared, the option labels in the order they are shown, and the
 * question the block asks.
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
     * Where a previously chosen option now sits, or null once it is gone.
     *
     * Both the page and the review payload resolve a stored answer through this
     * one method. Resolving in two places by two rules is how the reviewer ends
     * up seeing one option ticked while the agent is told another.
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
