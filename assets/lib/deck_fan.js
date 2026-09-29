/**
 * How many deck cards a fan with room for `slots` tiles shows, and the count
 * its "+N more" tile gives. `loaded` is what the page holds and `total` is
 * every Backlog card of the epic. The top card always shows.
 */
export function fanLayout(slots, loaded, total) {
    if (total <= slots && loaded >= total) {
        return { shown: total, more: 0 };
    }
    const shown = Math.max(1, Math.min(loaded, slots - 1));

    return { shown, more: total - shown };
}
