<?php

declare(strict_types=1);

namespace App\Module\Review\ValueObject;

/**
 * One decision block as it stands in a rendered version: the identifier the
 * document declared, and the option labels in the order they are shown.
 *
 * The identifier — not the option text and not the position — is what a
 * selection is keyed by, so a revision that rewords the block keeps its answer.
 */
final readonly class Decision
{
    /**
     * @param list<string> $options
     */
    public function __construct(
        public string $id,
        public array $options,
    ) {
    }

    public function optionAt(int $index): ?string
    {
        return $this->options[$index] ?? null;
    }

    /**
     * Where a previously chosen option now sits, or null once it is gone.
     *
     * Both the page and the review payload resolve a stored answer through this
     * rather than trusting the index it was recorded at. A revision that
     * reorders or shortens a block leaves that index pointing at a different
     * option — and resolving it in two places by two rules is how the reviewer
     * ends up seeing one option ticked while the agent is told another.
     */
    public function indexOf(string $option): ?int
    {
        $index = array_search($option, $this->options, strict: true);

        return false === $index ? null : $index;
    }
}
