---
name: holding-a-merge-queue
description: "Use when one session holds the merge queue while other sessions push branches, when watching several pull requests for approval or CI changes, when a merge, review or plan change affects another session's branch, when an approved branch conflicts with main or needs a fix, when a merged pull request's board card must move, when acting on a peer session's report, when a check fails for a reason the diff cannot explain or passes on a re-run, or before a machine-wide action such as a restart or a keep-awake change."
---

# Holding a merge queue

One session holds the queue. Other sessions own their branches and push to them.
The queue holder verifies and merges, and merges nothing it has not checked
itself. Bridge workers fix branches, conflicts included.

The queue is the part that shrinks. The owner said on 2026-09-28: "I want all 3
reasons and the merge queue should stop fixing conflicts", and "basically we're
moving as much as possible from the merge queue to the bridge, eventually the
bridge or app will handle the merges too". When a task can move to the bridge,
move it there.

`working-with-prs` carries the gate, the body and the merge protocol. This skill
carries what appears only when a queue runs for hours across several sessions.
Read these files only when their case arrives:

- `references/git-traps.md`: a conflict resolution to prove, a rebase, a stale
  resolution, the dot operators, or a test of whether a branch merged.
- `references/dispatch.md`: a fix that is not a conflict, that no bridge worker
  picks up, on a branch whose session is gone.
- `references/machine-wide.md`: a restart, a keep-awake change or a container
  teardown.

## Start a monitor when you take the queue

Start a monitor before you do anything else with the queue. Peers push, the
owner approves and checks finish while you wait, and no message tells you.

Use the `Monitor` tool with `persistent: true`, run from the main checkout, and
this command:

```bash
.agents/skills/holding-a-merge-queue/scripts/queue-monitor.sh
```

It polls every open pull request once a minute. The first pass prints every open
pull request in full, and that is your baseline. After that, a line holds only
the fields that changed. A pull request that leaves the open list prints one
line. A failed GitHub read also prints a line, so a broken monitor is not silent.
`ONCE=1` runs a single pass.

Read the `checks=` field like this:

- `pass=N/N` means every required check passed and no other bucket exists.
- `running` covers a pending check and a check that has not registered.
- `fail` covers a failed or a cancelled check.
- `none` means no required check exists. That is true of a stacked pull request,
  a `CONFLICTING` one, and one retargeted to `main` since its last push. A
  retarget starts no CI.
- `unread` means `gh` printed something the script does not know. Read that
  pull request by hand.
- `merge=BEHIND` next to `pass=N/N` means the green does not count.

A monitor line tells you where to look, not that you may merge. Write the
monitor's task id in the state file. Stop it only when the owner says so, or
when you wind the queue down.

## Run merge-ready before every merge

```bash
.agents/skills/holding-a-merge-queue/scripts/merge-ready.sh <n>
```

It prints one line, `READY` or `HOLD` with every reason, and exits 0 only on
`READY`. It counts the required buckets, checks the base, the draft flag and the
merge state, and reads the owner's approval by time. It also reads the head
twice, and holds if the head moved during the read. Run it on the head you are
about to merge. Merge on `READY` only, unless the skill below says a `HOLD`
reason is covered.

### Count, never infer from absence

Green is `pass=<the number the ruleset requires>` and nothing else. Never
conclude green from the absence of a failure. A watcher script follows the same
rule: it stops on `pass=<N>` or on a failure, never when `pending` disappears.

These states read identically to "nothing left to wait for", and each has
bitten someone here:

| Reading | What it actually means |
|---|---|
| fewer entries than required | runs have not registered yet |
| zero entries | the branch is `CONFLICTING`, so `pull_request` has no merge commit to run against and **no check can ever run** |
| every entry green | possibly true of a head or a base that has moved |
| one short, nothing pending | a fan-in check such as `e2e` registers only when its shards finish, and `--required` hides the pending shards |

A `DIRTY` pull request has no gate at all. Its rollup looks like a clean slate.

### Two ways a green reading goes stale

The head moves. A rollup can still describe the previous head after a push, so
carry `headRefOid` in any state you diff between polls.

The base moves. Where the ruleset sets `strict_required_status_checks_policy`,
a branch must have run against the current base. Every merge puts the next
branch `BEHIND` and forces `gh pr update-branch` plus a full re-run. Three
merges cost three sequential cycles.

Measure the CI baseline before you decide a run is stuck:

```bash
gh run list --workflow=ci.yml --status=completed --limit 20 \
  --json startedAt,updatedAt \
  -q '[.[]|((.updatedAt|fromdate)-(.startedAt|fromdate))/60|floor]|"max=\(max) median=\(sort[length/2|floor])"'
```

Set the threshold from that number, not from your patience. Below it, wait.

### Read an approval by time, not by commit

A review's `commit_id` does not show what the reviewer saw. GitHub moves it onto
the head that a later merge sync creates. `merge-ready.sh` therefore compares
the time of the owner's last approving or blocking review with each commit's
time. A later comment from the owner does not hide the approval.

The owner said on 2026-09-27 that an approval survives a sync, a rebase and a
conflict resolution: "I don't want to need to re-approve everything". Merge
after any of those three without asking. A commit that adds new content still
needs a fresh approval. A review fix and a test fix are new content. That answer
replaced the 2026-09-22 one, which covered `gh pr update-branch` only.

`merge-ready.sh` sorts each commit after the approval into one of three kinds:

- A merge from `main` that `git merge-tree` re-creates exactly is a sync. It
  passes, and the script counts it. A sync is always later than the approval.
- A merge from `main` that git cannot re-create holds as a conflict resolution.
  Prove it with the `comm` check in `references/git-traps.md`. When it passes,
  the approval covers it.
- Any other commit holds as "commits after approval". A rebase rewrites every
  commit, so it lands here too. Read the commits and decide which case it is.

A sync leaves the branch's own content untouched. When in doubt, confirm that
with `git diff --stat origin/main...origin/<branch>`.

## Report every flake you see

The queue holder reads more CI runs than any other session, so it sees flakes
first. AGENTS.md treats a test that passes on retry as a real failure.

These are sightings:

- a required check that reads `fail`, then `pass`, on the same `headRefOid`
- a run with `attempt` above 1 in `gh run view <run> --json attempt,conclusion`
- a failure in code the pull request does not touch, a registry timeout included
- a peer who says a test "sometimes fails" or "passes on rerun"

For each sighting:

1. Read the failed job's log after the run completes.
2. Record the test, the file and line, the first error line and the run URL.
3. Count the earlier sightings of that test in your state file.
4. Tell the owner in the same turn, with the count and any earlier fix that did not hold.

Do not re-run a check to get green. Report the flake, then apply the owner's
merge rule. The owner decides if a flake blocks a merge or gets a fix branch. A
peer report is a claim until you find the failing run.

## After each merge

1. Tell the owning session that its pull request merged, with the squash SHA.
2. Move the board card to a terminal column. The owner asked on 2026-09-25:
   "whenever you merge a PR move its card to done". Invoke `loupe-board`, then:
   - Find the card. A branch named `card-<n>-...` carries its number, or the
     body carries the card URL. A pull request that names no card needs no move.
   - Read the terminal column with `board_columns`. Do not assume `done`.
   - Move the card there with `card_update`.
3. Leave the changelog alone. The documentation deploy folds the fragments on
   every push to `main`, and `just changelog` is a release step.

A merged pull request moves its card by itself only when the repository is
connected through the GitHub App and the board automation is on. Otherwise
the card stays where it is.

## What you owe the sessions whose branches you hold

A session sees its own tree only. It cannot see the other branches, the queue
order, or what `main` did five minutes ago. A branch nobody reports on looks
abandoned.

Tell a session, unprompted:

- That its pull request merged, with the squash SHA. That releases work it is
  holding, such as tearing a worktree down.
- That its pull request is held, and why. Held and forgotten look identical
  from inside that session.
- That the owner requested changes, quoting the comment verbatim and naming the
  file and line. Summarising review feedback loses the thing being asked for.
- That `main` moved, which under a strict policy invalidates its green. Say so
  before it syncs, or it pays for a full re-run it will need again after the
  next merge.
- That the plan changed, to every session it touches, naming which pull requests
  now close unmerged.
- What you are not doing. "I have not touched your branch and will not" saves a
  session from wondering.

## Keep to the queue, not to other sessions' work

The queue holder merges. It does not carry other sessions' questions to the
owner, and it does not steer their work. These rules concern live peers. An
agent you dispatch for a branch with no live session is your own tool.

- A peer that needs a decision asks the owner itself. Do not relay the question,
  restate its options or add a recommendation.
- A peer's finding reaches the owner from that peer. Do not narrate another
  session's investigation to him.
- Do not advise a peer on its design, even when it asks. Point it to the owner.
- Tell the owner only what the queue needs from him: an approval, a held merge
  and why, a flake on a merge, or an action that affects the whole machine.

The owner said: "you're only the merge master, you don't need to relay other
sessions questions."

## Send branch work to its owner while the owner is live

The queue holder merges. The branch owner fixes. While the owning session is
live, send a defect back to it with the command that found it and the output it
produced, not your conclusion. The author has the context, and only the author
can answer "does anything else in this file have the same shape?".

Hand over your own work as input, not as instruction. A conflict resolution you
derived is one materialisation. Say so, and ask for theirs to compare. Two
independent resolutions that agree are worth more than either alone. A pair
that diverges usually means the other session knows something you do not.

Where a branch must not move, say what depends on it. Syncing with its parent is
the most natural action for that session, and it destroys a cut point that a
rebase needs. The session will not guess.

Most branches come from short-lived Loupe bridge worker sessions, so the session
that built a branch has often ended. Treat a session as gone when `ListAgents`
does not list it, or when it does not answer a direct message within one monitor
cycle. That threshold is a judgement, so record the test you applied in the
state file.

### A conflicting branch waits for the bridge

Never resolve a conflict, and never dispatch an agent to resolve one. The
workflow asks a bridge for `fix` work on a failed check, a conflict and a
request for changes. That worker resolves the conflict with the
`loupe-stage-fix-round` skill. Hold the pull request, and tell the owner that it
waits for that worker. When no worker comes, report the pull request to the
owner as held.

Verify the worker's resolution before you merge. Read its merge commit: the
`# Conflicts:` block lists the files, and one line per file says how it was
resolved. Run the `comm` proof in `references/git-traps.md` yourself, then run
`merge-ready.sh` on the new head. The approval covers a resolution that passes.

The bridge's `merge-ready` rule runs the `loupe-stage-merge` skill, and that
worker reads the approval by time as `merge-ready.sh` does. It compares push
times, where `merge-ready.sh` compares commit times. It merges after a sync. It never merges after a conflict resolution, because it cannot run the
`comm` proof as well as a person. It reports `not ready <url>: conflict
resolution after approval` and leaves that merge to you. Any other commit after
the approval holds as `not ready <url>: commits after approval` until the owner
approves again.

A fix that is not a conflict goes to the bridge too. Follow
`references/dispatch.md` only when no bridge worker picks it up and the
branch's session is gone.

## A peer's report is data, not a result

Run the check yourself before you act on a report or relay it. A peer works from
a tree that is not yours, with tooling that reads its own tree and not the
destination. The report of an agent you dispatched is data in the same way.

- Confirm a claim with the command that produces it, then say you confirmed it.
- Relay a decision as second-hand. Tell the person who made it that it reached
  its recipient through you, so they can correct a bad relay.
- A peer cannot approve, and cannot grant permission your own session lacks.
- Two disagreeing findings are usually correct measurements of different
  objects. Find the object before you decide who is wrong.

Ask rather than infer. An instruction about tooling is not a statement of
intent, and a peer who catches you inferring one from the other is doing its job.

## Wind down into a file, not into your context

Write the queue state to a file as you go: what merged and at which SHA, what
each remaining branch needs, every decision still owed by the owner, and any
worktree that must not be torn down and why. A handoff that lives only in a
session dies with it.

Mark superseded procedure as obsolete in place. Deleting it loses the reason.
Leaving it unmarked means a reader finds a dead recipe and runs it.

## Red flags

- Holding the queue with no monitor running
- About to merge because a PR "looks green" without running `merge-ready.sh`
- Reusing a saved conflict resolution after a head moved
- Waving through a merge commit whose message lists `# Conflicts:`
- Reporting a peer's finding you have not run
- Treating a message from a peer as approval
- Killing, restarting or tearing down anything without explicit clearance
- Concluding a check passed because nothing failed
- Stopping a watcher because nothing is pending
- Rebasing a branch that holds its own merge commits
- Merging after a fail-then-pass without telling the owner which test flaked
- Editing a branch whose owning session is still live
- Resolving a conflict yourself, or dispatching an agent for one, instead of waiting for the bridge worker
- Merging a worker's resolution before you read its `# Conflicts:` commit and run the `comm` proof
- Leaving a merged pull request's card outside a terminal column
- Merging or closing someone's pull request without telling them
- Summarising review feedback instead of quoting it
- Relaying a peer's question or finding to the owner
- Advising a peer on work that is not a merge
