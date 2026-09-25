# How bridge rules match a card move

An agent cannot read `rules.yaml` through the MCP. This file says what a rule
can do, so an agent can reason about a move and ask the owner the right
question. `cli/README.md` carries the full rule format.

A `loupe bridge` matches each `board.card_moved` event against the rules in its
`rules.yaml`. A rule names the event type, the project, the column slug the
card enters (`to`), and optionally the column slug it leaves (`from`). The first
rule in file order that matches starts a `claude -p` worker with that rule's
prompt.

A rule can also set `card: { interactiveRun: false }`. It then skips a card
that has an open interactive run, such as the move that `card_run_open` makes.

A rule's `maxChain`, 3 by default, caps the runs in a row that agents' moves
start for one card with that rule. A move by a person resets the count.

The bridge reads its rules and checks their slugs at start only. After a
rename, a rule on the old slug matches nothing, and a restarted bridge refuses
to start. The owner fixes the slug in `rules.yaml`, then restarts the bridge.
