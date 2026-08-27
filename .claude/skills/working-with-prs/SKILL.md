---
name: working-with-prs
description: Use when opening, gating, reviewing or merging a pull request in this repository — running the pre-PR gate, writing the body, making a branch testable, handling Codex findings, or sequencing several branches at once.
---

# Working with pull requests

Every change reaches `main` through a pull request. `main` is protected, so
there is no path that skips this.

## The gate, before you open anything

1. **`just cs`** — applies formatter and rector fixes. Commit anything it
   changed. This works correctly from a worktree: the finder uses explicit
   excludes and throws if it matches zero files, so a vacuous pass is not
   possible.
2. **`just ci`** — check-only. It reports style and rector violations but never
   rewrites files; `just cs` is the step that applies them. Fix every failure,
   including ones that pre-date your change.
3. **`just e2e`** — fix every failure, including pre-existing ones. See
   "Gating a branch you have not checked out" below, which has a destructive
   side effect worth knowing before you run it.
4. **A Codex review** — `mcp__codex-cli__review` with `model: "gpt-5.6-sol"`.
   Always pass the model explicitly; whatever the tool picks by default is
   rejected by this Codex account.

Review against `origin/main`, never `main` — a worktree's local `main` is often
stale, and reviewing against it reports findings for code that is already
merged.

**If `mcp__codex-cli__review` is not available, STOP and tell the owner.** A
missing MCP server is a configuration fault worth investigating, not an
inconvenience to route around. Known cause: `codex-cli` is registered
per-project in the user's own configuration rather than in a committed
`.mcp.json`, so a session running from a worktree path may not pick it up.

If `codex review` produces no output for ~5 minutes at near-zero CPU it is hung,
typically at MCP startup. Kill it and fall back to:

```bash
codex exec -c model="gpt-5.6-sol" "Review the diff of this branch against origin/main (git diff origin/main...HEAD) for correctness bugs and convention violations. Actionable findings only."
```

## Documentation-only branches run steps 1 and 2 only

Skip `just e2e` and the Codex review, and **say so in the PR body** so the
record shows the gate was reduced deliberately rather than forgotten.

The test is the diff, not the intent — every changed file must end in `.md`.
Check it, do not assume:

```bash
git diff --name-only origin/main...HEAD | grep -v '\.md$'
```

Any output at all means the full gate applies. A branch that also touches
`.env`, a Twig template, a fixture, `composer.json` or a `justfile` recipe is
not documentation-only, however small the change looks.

Two things this does not license. `just ci` still runs, because Markdown is not
inert here: prettier covers some of it, gamache's checks read `docs/`, and a
docs commit can break a build that greps them. And it does not extend to
Markdown that is *executed* — a `.md` file a script parses for commands is code
wearing a `.md` suffix, so gate it fully. A `SKILL.md` sits at the edge: it is
read by an agent rather than parsed by a script, so the reduced gate applies,
but say in the body that you made that call.

The reason is proportion rather than speed. `just e2e` is a serial four minutes
(`workers: 1`), and running it to prove a paragraph of prose did not break a
browser test is cost with no signal — while the habit of running a gate that can
never fail is what teaches a reader to stop trusting gate results.

## Write the body for two readers

`main` allows squash merges only, so **the PR body becomes the commit body**.
It is permanent history, not a note to a reviewer who is about to forget it.

Say what changed and *why the alternative was rejected*. Record what you
verified and how — "reverted the fix and watched the test fail" is worth more
than "added tests". Name what you could **not** verify, in the body rather than
buried in a comment: a branch that says "terraform is validate-only, the cloud
path is unverified" is trustworthy in a way that silence is not.

If a gate was reduced or skipped, say which and why. If the change needs
something at deploy time — a rerender, a cache clear, a stack recreation —
put it in the body, because the person merging is not necessarily you.

## Make the branch testable, not just reviewable

A reviewer who has to build state by hand usually will not. Two things close
that gap:

**Point at the running instance.** Every worktree serves its own branch at
`https://<slug>.loupe.dev.localhost`. Put that URL in the body with the login
(`dev@loupe.test` / `password`, or `admin@loupe.test` for the admin area), and
verify it responds before you write it down. Say plainly when a branch has no
worktree or nothing to click, rather than pasting a link that goes nowhere.

**Seed the data the change needs.** `bin/console app:dev:seed` creates a user, an
admin and a project — and no documents, comments, verdicts or exports. A diff
feature is untestable without a document carrying several versions; an undo
feature needs a standing verdict to undo. Create fixtures that contain the exact
shape the bug had, not merely a happy path, and tell the reviewer which one to
open and what to look for.

Seed with a temporary `#[When('dev')]` console command created inside the
worktree, run it, then delete it and confirm `git status --porcelain` is empty.
Fixtures must never reach the branch.

## Reviewing work you did not write

**A green gate is not evidence the change is correct.** In one wave of six
branches, every branch arrived with `just cs`, `just ci` and e2e green, and
Codex found a real defect in every single one of them — two capable of data
loss: a migration that would have corrupted anchors already stored correctly,
and a hook that would have deleted a pre-existing branch with its unmerged
commits. The gate proves the suite passes. It does not prove the change is
right.

So: **read the diff yourself**, especially the part the author called routine.
Where an agent reports a decision it made on your behalf, check whether it is
really orthogonal to the open questions it claims not to touch — one wave
branch added a unique index and called it unrelated to a design decision it
silently foreclosed.

**Send findings back to whoever wrote the code**, not to a fresh agent. The
author still has the context, and the second-order questions — "does anything
else in this file have the same shape?" — are the ones that catch the bug the
reviewer did not spot. Ask for the failure to be reproduced against the
pre-fix code, so you know the new test discriminates rather than passing
vacuously.

## Gating a branch you have not checked out

`E2E_BASE_URL=https://<slug>.loupe.dev.localhost just e2e --workers=1` runs the
suite against a sibling worktree, which is the right tool for gating a branch
without disturbing your own checkout. Warm its cache first:

```bash
( cd .claude/worktrees/<name> && bin/worktrees/compose-exec.sh bin/console cache:warmup )
```

**This destroys that worktree's dev data.** The suite's `install-reset` project
truncates every table. `just e2e` normally repairs the worktree afterwards, but
`bin/e2e-target.sh` deliberately blanks the worktree name when `E2E_BASE_URL` is
set by hand — so the automatic repair does **not** run for exactly this
invocation. Repair it yourself:

```bash
bin/worktrees/worktree-bootstrap.sh <worktree-name>   # from the main checkout
```

Use bootstrap rather than a bare `app:dev:seed`: `install-reset` also drops the
project the widget token belongs to, and bootstrap is what notices the token in
`.env.local` no longer resolves and reissues it.

**Two runs launched this way cannot overlap.** Each worktree has its own Mailpit
sidecar, so runs started with plain `just e2e` in different worktrees are
isolated — but a run with an explicit `E2E_BASE_URL` deliberately falls back to
the *shared* instance, so two of those read each other's mail. Serialise them.
`bin/e2e-target.sh` prints the Mailpit URL it resolved as its fourth line, which
is how you tell which one a run got.

## Merging

1. A branch's final gate happens on the branch **fully merged with current
   main**. A branch cut before a sibling merged has not really been gated: its
   e2e run never exercised the sibling's specs against its code.
2. Merge **immediately** after the gate goes green. Every merge to `main`
   between a branch's last sync and its own merge invalidates its PR
   (conflicts) or its gate.
3. After **every** merge, run `just cs` on main and commit the drift. Merge
   unions of two individually-clean branches produce fixer drift that otherwise
   lands on whichever branch syncs next.
4. Tear down a merged branch's worktree only **after** `gh pr merge` is
   confirmed — never in the same command chain, and confirm it by reading the
   result rather than by the command having been issued. A merge can fail for a
   reason that is not a conflict (mergeability still computing, a check that
   flipped), and destroying the tree first turns a retry into a rebuild from
   origin. Nothing is actually lost while the branch is still on origin, which
   is the thing to verify before anything else.
5. **A conflict-free merge is not a correct merge.** When one branch renames a
   class, a *new* file on another branch merges cleanly while still importing
   the old name — git sees no conflict because the file never existed on both
   sides. Run `just ci` before trusting such a merge; phpstan is what catches
   this, not git.

   The **signature variant** is the same blind spot with a different tell. A
   branch that makes an argument required lives on, absorbs merges, and each
   incoming merge can bring a *new* file from a sibling that constructs the old
   shape. It existed on only one side, so git has nothing to report, and the
   result is a runtime `ArgumentCountError`. Grepping for the changed symbol is
   a good first pass and not sufficient — it finds only the shapes you thought
   to search for, and goes stale at the next merge.
6. **Resolving a conflict by taking one side can silently revert the other
   side's fix.** Before accepting a resolution, re-verify the *behaviour* both
   branches were protecting, not just that the markers are gone. The sharpest
   form is rename/rename, where neither side is correct and the answer is the
   newer branch's **content** at the other branch's **path**.
7. **"Pull Request is not mergeable" right after `git push`** usually means
   GitHub has not recomputed mergeability yet. Wait a few seconds and retry.
8. **Read the checks against the head you are about to merge.** After syncing a
   branch, the rollup can still describe the *previous* head for a while, so an
   all-green reading is not evidence about the commit in front of you — it is
   evidence about a commit that no longer matters. Key the wait on
   `headRefOid`, require the full set of required checks to be present, and
   only then treat green as green:

   ```bash
   gh pr view <n> --json headRefOid,statusCheckRollup \
     -q '"\(.headRefOid) \([.statusCheckRollup[]|(.conclusion//.state//"PENDING")]|join(","))"'
   ```

   A rollup with fewer entries than the ruleset requires means runs have not
   registered yet, which reads identically to "nothing left to wait for".

## What the ruleset actually requires

`main` allows `--squash` only. A plain `gh pr merge` fails with
`GraphQL: Merge commits are not allowed on this repository`. Use
`gh pr merge <n> --squash`.

**Do not diagnose a rejected merge with `gh repo view`** — it reports repository
*settings*, which happily say all three merge methods are allowed while the
ruleset forbids two. The ruleset is the authority:

```bash
id=$(gh api repos/ubermuda/loupe/rulesets -q '.[0].id')
gh api repos/ubermuda/loupe/rulesets/$id -q '.rules[]|select(.type=="pull_request")|.parameters'
```

It requires eight CI checks — `lint`, `cs-check`, `phpstan`, `arkitect`,
`gamache`, `audit`, `phpunit`, `e2e` — and **one approving review**.

An approval in chat is not a GitHub approval. Check before concluding a merge is
blocked by something else:

```bash
gh pr view <n> --json reviewDecision,mergeStateStatus
```

A job that runs in CI but is not in that list cannot block anything. Adding one
is a repository setting, so a branch cannot do it — if you add a CI job, say in
the PR body that requiring it is still owed, or the job is decoration.

**Never approve your own work.** That is the one thing that stays with a human,
because it is the review itself.

**Merging is not.** A PR that is *approved* and has *all required checks green*
is good to merge — go ahead and merge it without asking. The approval already
carried the decision; waiting for a second confirmation just parks finished work
in a queue. Follow the merge protocol below when you do: sync with current main,
let the checks re-run on the synced head, merge, then `just cs` on main.

So the shape of autonomous work is: open the PR, and merge it once the owner has
approved it and CI is green. What you must not do is merge something unapproved,
merge past a failing or pending check, or use `--admin` to bypass either.

## Running several branches at once

**Give each branch its own worktree and keep them off each other's files.** The
sharpest case is a shared tracker or changelog: if every branch deletes its own
entry from `docs/NEXT_STEPS.md`, they all collide in the same region. Have wave
branches leave it alone and clean it up in one trailing PR after the wave lands.

**Sequence by blast radius.** A branch that changes shared infrastructure —
compose files, environment resolution, CI wiring — should merge last, because
merging it invalidates the environment its siblings are still being gated in.
Say so in its body.

**Land signature changes early.** A branch that makes an argument required grows
more dangerous with every sibling merged ahead of it, because each merge can
bring a new file constructing the old shape (see merge protocol item 5).

**Batch by file overlap, not by theme.** Two agents editing the same module
conflict no matter how unrelated their tasks sound.
