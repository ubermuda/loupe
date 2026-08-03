<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

/**
 * Accumulates HTML under hard output-length, visit-count and depth ceilings.
 *
 * The buffer is private on purpose, and that is the whole design. Front-matter
 * expansion was bounded three times by charging a budget at each call site, and
 * three times something turned out to be uncharged — values, then container
 * visits, then nested mapping keys. A per-call-site budget fails open: whatever
 * a later edit appends is free until someone notices. Here every character
 * reaching the output has to pass through append(), so a new append is charged
 * whether or not its author thought about it.
 *
 * Three ceilings — output length, nodes visited, nesting depth — and all three
 * live here rather than at the call sites so that crossing any of them has the
 * same consequence. Depth was checked by the caller once and quietly returned
 * instead of latching, which stored a truncated table: a key whose value the
 * document had written, rendered empty, with nothing to tell a reviewer that
 * anything was dropped. All-or-fallback is the property the design depends on.
 *
 * Once any ceiling is crossed the builder latches: further calls are no-ops and
 * result() returns null, so a caller that fails to check a return value still
 * cannot overflow it or keep a partial result.
 *
 * The honest limit of this defence: it bounds what the renderer does with an
 * already-parsed structure, and nothing before that. Yaml::parse() materialises
 * a `<<:` merge key into a real array while building the structure this class
 * is handed, so that allocation happens upstream and no ceiling here can bound it.
 */
final class BoundedHtmlBuilder
{
    private string $html = '';
    private int $visits = 0;
    private bool $exceeded = false;

    public function __construct(
        private readonly int $maxLength,
        private readonly int $maxVisits,
        private readonly int $maxDepth,
    ) {
    }

    /**
     * Records one node visit at $depth. Returns false once any ceiling is
     * crossed. Depth is passed in rather than checked by the caller so that it
     * cannot be tested without also latching.
     */
    public function visit(int $depth): bool
    {
        if (++$this->visits > $this->maxVisits || $depth > $this->maxDepth) {
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

    /**
     * Iterates $items, stopping the moment a ceiling is crossed.
     *
     * The loop belongs here for the same reason the buffer does. Latching makes
     * every guarded call a no-op, but it does not by itself stop a `foreach`
     * that is already running: a loop at a call site keeps stepping through its
     * remaining siblings. That is harmless only while every guard sits above its
     * loop, so no exceeded call ever enters one — an ordering nothing enforces
     * and a future edit can invert without any visible symptom. Owning the loop
     * removes the ordering question instead of relying on getting it right.
     *
     * Testing this by hand needs both loops reverted at once. Reverting only the
     * inner one leaves the outer each() containing the whole thing, and the run
     * comes back fast — which reads as "the guard was never needed" and is the
     * opposite of what it shows.
     *
     * @param iterable<int|string, mixed>      $items
     * @param callable(int|string, mixed):void $visitor
     */
    public function each(iterable $items, callable $visitor): void
    {
        foreach ($items as $key => $item) {
            if ($this->exceeded) {
                return;
            }

            $visitor($key, $item);
        }
    }

    /** The accumulated HTML, or null if any ceiling was crossed. */
    public function result(): ?string
    {
        return $this->exceeded ? null : $this->html;
    }
}
