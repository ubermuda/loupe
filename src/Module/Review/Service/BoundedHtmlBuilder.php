<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

/**
 * Accumulates HTML under a hard output-length ceiling and a hard visit ceiling.
 *
 * The buffer is private on purpose, and that is the whole design. Front-matter
 * expansion was bounded three times by charging a budget at each call site, and
 * three times something turned out to be uncharged — values, then container
 * visits, then nested mapping keys. A per-call-site budget fails open: whatever
 * a later edit appends is free until someone notices. Here every character
 * reaching the output has to pass through append(), so a new append is charged
 * whether or not its author thought about it.
 *
 * Two ceilings, because neither implies the other. Length bounds what a reader
 * is served; visits bound pure traversal, which length cannot see — a structure
 * of empty containers expands exponentially while emitting nothing at all.
 *
 * Once either ceiling is crossed the builder latches: further calls are no-ops
 * and result() returns null, so a caller that fails to check a return value
 * still cannot overflow it.
 */
final class BoundedHtmlBuilder
{
    private string $html = '';
    private int $visits = 0;
    private bool $exceeded = false;

    public function __construct(
        private readonly int $maxLength,
        private readonly int $maxVisits,
    ) {
    }

    /** Records one node visit. Returns false once the traversal ceiling is crossed. */
    public function visit(): bool
    {
        if (++$this->visits > $this->maxVisits) {
            $this->exceeded = true;
        }

        return !$this->exceeded;
    }

    /** Appends markup the renderer itself emits. Returns false once a ceiling is crossed. */
    public function append(string $html): bool
    {
        if (!$this->exceeded) {
            if (\strlen($this->html) + \strlen($html) > $this->maxLength) {
                $this->exceeded = true;
            } else {
                $this->html .= $html;
            }
        }

        return !$this->exceeded;
    }

    /** Appends document-supplied text, escaped. */
    public function appendText(string $text): bool
    {
        return $this->append(htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'));
    }

    /** The accumulated HTML, or null if either ceiling was crossed. */
    public function result(): ?string
    {
        return $this->exceeded ? null : $this->html;
    }
}
