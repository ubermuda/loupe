---
title: "Architectural priorities"
description: "The ranking that settles a trade-off, and when to escalate instead."
---

## The ranking

The owner sets this order, and it is strict.

> correctness > simplicity > performance > shipping speed

When two of these collide, the higher one wins and the lower one yields.

## How to use it

Most trade-offs answer themselves here. An agent that faces "this is technically
wrong, and nobody would notice" reads the ranking and fixes it. Before the
ranking existed, that agent guessed, and the guesses produced both
over-engineering and blocking on the wrong things.

Escalate on cost, not on principle. Apply the ranking when the fix is cheap.
Ask the owner when the fix is expensive, when the defect is unreachable in
practice, or when "leave it and note it" is a real answer.

To escalate, name the severity, name the cost of the fix, and give a
recommendation. The owner sets the bar. Reserve blocking language for what is
unsafe to ship. [CLAUDE.md](../../CLAUDE.md) carries the longer version under
"Recommendations and the quality bar".

## Performance has two states

Measure a performance problem, and it becomes a bug. It then sits under
correctness, and the correctness rules apply to it.

Leave it unmeasured, and it is speculation. It then stays third, below
simplicity.

Run the query, read the plan, or time the request before you argue for
performance. This split settles two of the collisions below.

## Collisions

### Correctness against simplicity

Correctness wins. Accept more code when the simpler form can be wrong.

`.php-cs-fixer.dist.php` shows the shape. `ignoreVCSIgnored(true)` was one line,
and it matched zero files inside a gitignored worktree. `just cs` then fixed
nothing, and the style leg of `just ci` passed with nothing inspected. The file
now carries an explicit exclude list and a guard that throws on zero files
(`e15e07bd`).

### Correctness against performance

Correctness wins. A slower path that is right beats a faster path that is wrong.

The HTML sanitizer shows the shape. `ef0f4555` removes `style` and `title` from
the parsed tree instead of with a regex pre-pass. An unclosed `<style>` swallows
the rest of the document, and only a parser resolves that. A parser costs more
than a regex, and nobody measured the difference, because correctness settled
the choice on its own.

This pair has the weakest grounding of the six. No commit in this repository
weighs a measured performance number against a correctness cost. The rule holds,
and the example only shows correctness winning without a contest.

### Correctness against shipping speed

Correctness wins. Ship late, and ship right.

The pull request gate says so. `working-with-prs` tells you to fix every failure
the gate reports, including the failures that pre-date your change. The only
acceptable response to a flaky test is a fix.

`SelectDecisionOptionHandler` shows the same rule in the product. It refuses a
stale decision submission, so a reviewer who submits against an old version gets
an error and re-reads the block. Resolving the submission is faster for that
reviewer, and it records a label they never clicked. The owner settled that call.
[CLAUDE.md](../../CLAUDE.md) still lists it as an example of a judgement about
rigour, to make the point that the owner makes such calls.

### Simplicity against performance

Simplicity wins while the performance cost is unmeasured.

Search indexing runs synchronously inside the command handlers. A queue or a
database trigger takes the work off the write path. `DocumentSearchIndexer` keeps
it in the handlers, because that is what makes it greppable. Nobody has measured
a write that is too slow.

Measure the cost, and the answer flips, because a measured problem is a bug.
`ad5de641` accepts real complexity for a measured number. A per-row regconfig
kept the search off `idx_documents_search_vector`, which cost 8.6 ms against
0.5 ms on 200,000 rows. The fix emits one query branch per language and adds a
composite index.

### Simplicity against shipping speed

Simplicity wins. Make the change easy, then make the easy change.

The audit subsystem landed as one branch in `39129f49`. `1183917f` then moved the
audit trail out into `ubermuda/audit-bundle`, and `08e2c182` did the same for the
health checks. Each extraction delayed other work, and each left one boundary in
place of a module that keeps growing.

### Performance against shipping speed

Performance wins when it is measured. A measured problem is a bug, and a bug
outranks getting the feature out.

`ad5de641` again. Full-text search already worked, and the branch was ready. The
measurement held it until the query plan used the index.

An unmeasured performance worry raises no collision at all. Note it, ship, and
measure later.

## Architecture Decision Records

Write an ADR on judgement. No class of change requires one. A new dependency, a
schema change and a cross-module boundary are each a reason to consider an ADR.
None of them compels one.

Write one when a future reader asks why, and the code cannot answer. Skip one
when the commit message or a docblock carries the reason well enough.

This repository holds no ADR yet, so the first author creates the home:

- Put the file at `docs/decisions/NNNN-short-slug.md`, and number it from `0001`.
- Give it the front matter every page under `docs/` has, `title` and
  `description`.
- Use three sections: Context, Decision, Consequences.
- Name the options you rejected, and name the cost the decision accepts.

A page under `docs/` publishes to the public documentation site by default. The
loader skips a file name that starts with `_`, and it skips the internal paths
that `website/src/content.config.ts` excludes. A directory name that starts with
`_` does not hide the files inside it. Add the new group to
`website/astro.config.mjs`, or the page is reachable only by its URL. Write every
ADR as public text.

### Decisions with no record

The reasoning for these four lives in pull request comments and in docblocks.
They are candidates for an early ADR. Nobody has to write them.

- Adopting `martin-georgiev/postgresql-for-doctrine` in place of hand-rolled
  Doctrine classes. The library bounds PHP at `^8.2 <8.6`, and the project
  accepted that bound.
- Keeping search indexing synchronous.
- Refusing a stale decision submission instead of resolving it.
- Moving the HTML sanitizer to an explicit per-element allowlist.
