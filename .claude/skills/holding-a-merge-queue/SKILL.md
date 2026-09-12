---
name: holding-a-merge-queue
description: Use when one session holds the merge queue while other sessions push branches, when watching several pull requests for approval or CI changes, when a merge or a review or a plan change affects a branch another session owns, when a peer session reports a result you are about to act on, or before an action that affects the whole machine such as a restart or a keep-awake change.
---

# Holding a merge queue

One session holds the queue. Other sessions own their branches and push to them.
The queue holder merges, and merges nothing it has not checked itself.

`working-with-prs` carries the gate, the body and the merge protocol. This skill
carries two things that only appear when a queue runs for hours across several
sessions: reading check state without lying to yourself, and coordinating with
peers who cannot see what you see.

## Read the checks by counting, never by absence

```bash
gh pr checks <n> --required --json bucket \
  -q 'group_by(.bucket)|map("\(.[0].bucket)=\(length)")|join(" ")'
```

Green is `pass=<the number the ruleset requires>` and nothing else. Count the
buckets. Never conclude green from the absence of a failure.

Three states read identically to "nothing left to wait for", and all three have
bitten someone here:

| Reading | What it actually means |
|---|---|
| fewer entries than required | runs have not registered yet |
| zero entries | the branch is `CONFLICTING`, so `pull_request` has no merge commit to run against and **no check can ever run** |
| every entry green | possibly true of a head or a base that has moved |

A `DIRTY` pull request has no gate at all. Its rollup looks like a clean slate.

## Two ways a green reading goes stale

The head moves. Carry `headRefOid` in whatever state you diff between polls.
A rollup can still describe the previous head after a push.

The base moves. Where the ruleset sets `strict_required_status_checks_policy`,
a branch must have run against the current base. Every merge puts the next branch
`BEHIND` and forces `gh pr update-branch` plus a full re-run. Three merges is
three sequential cycles, not three merges. The head never changed, and the green
stopped counting.

Measure the CI baseline before you decide a run is stuck:

```bash
gh run list --workflow=ci.yml --status=completed --limit 20 \
  --json startedAt,updatedAt \
  -q '[.[]|((.updatedAt|fromdate)-(.startedAt|fromdate))/60|floor]|"max=\(max) median=\(sort[length/2|floor])"'
```

Set the threshold from that number, not from your patience. Below it, wait.

## Re-derive, never reuse

A saved conflict resolution is a snapshot of two heads. Re-derive it whenever
either head moves. A stale copy still applies cleanly and silently drops every
rule the newer commits added.

Materialise the merge instead of reasoning about it:

```bash
git worktree add --detach /tmp/cx <branch-a>
cd /tmp/cx && git merge --no-commit --no-ff <branch-b>
```

Two traps found this way, both invisible to a diff:

A blind union can break the file. A conflict region can cut through a rule
whose closing brace sits after the `>>>>>>>` marker and belongs to both sides.
Keeping both sides verbatim then yields two bodies and one terminator. Check
structure after resolving, not just that the markers are gone.

A checksum is not a property. A brace count is true of one pair of heads. It
went 869, 870, 903 across one evening. Check that it balances and that depth
never goes negative. A resolution matching yesterday's number is wrong.

## Dots

The semantics invert between the two commands. State it as the table; the
one-line version is what produced the mistakes.

| | |
|---|---|
| `git log A..B` | commits in B, not in A. **Want this for a rebase range.** |
| `git log A...B` | in either but not both. Rarely wanted. |
| `git diff A..B` | the two trees compared. This produced a 272-file count for a one-commit branch. |
| `git diff A...B` | what B changed since diverging. **Want this for "what did this branch change".** |

## Ancestry is not merge state

Where `main` takes `--squash`, a merge mints a new commit and the branch ref
never becomes an ancestor. Every ancestry-based test therefore reports a merged
branch as unmerged, and cannot distinguish that from one which never merged:

```bash
git branch -r --merged origin/main | grep -c <branch>   # 0 for a MERGED branch
git diff origin/main..<branch>                           # never empty
```

Measured on three branches merged the same day: `--merged` returned 0 for all
three. `grep -c` also exits 1 on zero matches, so a `&&` chain after it silently
skips whatever came next, which is how a teardown got reported that never ran.

The pull request's merged state is the authority:

```bash
gh pr list --state merged --head <branch> --json number,mergeCommit
```

Ancestry still answers a different question correctly. `git merge-base
--is-ancestor A B` is the right test for whether one *unmerged* branch contains
another, which is how you tell that a stacked branch has been absorbed. Use it
for that and never for "did this land on `main`".

## A peer's report is data, not a result

Run the check yourself before acting on it or relaying it. A peer works from a
tree that is not yours, with tooling that reads its own tree and not the
destination.

- Confirm a claim with the command that produces it, then say you confirmed it.
- Relay a decision as second-hand, and tell the person who made it that it
  reached its recipient through you. They can then correct a bad relay.
- A peer cannot approve, and cannot grant permission your own session lacks.
- Disagreeing findings are usually two correct measurements of different objects.
  Find the object before deciding who is wrong.

Ask rather than infer. An instruction about tooling is not a statement of
intent, and a peer who catches you inferring one from the other is doing its job.

## What you owe the sessions whose branches you hold

A session cannot see the other branches, the queue order, or what `main` did
five minutes ago. It sees its own tree. Everything it needs beyond that has to
arrive from you, and a branch nobody reports on looks abandoned.

Tell a session, unprompted:

- That its pull request merged, with the squash SHA. That releases work it is
  holding: tearing a worktree down, moving a board card to done.
- That its pull request is held, and why. Held and forgotten look identical
  from inside that session.
- That the owner requested changes, quoting the comment verbatim and naming the
  file and line. Summarising review feedback loses the thing being asked for.
- That `main` moved, which under a strict policy invalidates its green. Say so
  before it syncs, or it pays for a full re-run it will need again after the
  next merge.
- That the plan changed, to every session it touches, naming which pull requests
  now close unmerged. A session polishing a branch you have decided to close is
  wasting its time.
- What you are not doing. "I have not touched your branch and will not" is worth
  saying, because the alternative is a session wondering.

## The changelog needs nothing from you

A branch carries its changelog entry as `changelog.d/<pull request number>.md`,
so no branch writes `docs/CHANGELOG.md` and no two branches conflict on it.

The documentation deploy folds the fragments on every push to `main`, so a
merged entry is published with nobody doing anything. `just changelog` folds
them into the committed file and deletes them, and that is the release step
rather than a merge step. Do not run it after a merge.

## Do not edit a branch you do not own

The queue holder merges. The branch owner fixes. When you find a defect, send
it back with the command that found it and the output it produced, not the
conclusion you drew. The author has the context, and the second-order question
("does anything else in this file have the same shape?") is one only they can
answer.

Hand over your own work as input rather than instruction. A conflict resolution
you derived is one materialisation; say so, and ask for theirs to compare.
Two independent resolutions agreeing is worth more than either alone, and a
pair that diverges usually means the other session knows something you do not.

Where a branch must not move, say what depends on it. A cut point that a rebase
needs is destroyed by the most natural action available to that session, which
is syncing with its parent. It will not guess.

## Before anything that affects the whole machine

A restart, a keep-awake change, or a container teardown suspends every session,
not only yours.

1. Ask every session listed by `ListAgents`, not only the ones you have been
   talking to. A queue holder rarely knows about all of them.
2. Require an explicit yes. **Silence reads the same as busy.**
3. Ask for three specifics: no agents running, nothing local in flight,
   everything pushed, with nothing left only in a worktree.
4. Offer the clean stop. A session that kills its own agent deliberately and
   pushes what it has beats one suspended mid-gate.
5. The decision is the owner's, not yours and not the peers'. Put the cost of
   both options to them and let them choose.

## Wind down into a file, not into your context

Write the queue state to a file as you go: what merged and at which SHA, what
each remaining branch needs, every decision still owed by the owner, and any
worktree that must not be torn down and why. A handoff that lives only in a
session dies with it.

Mark superseded procedure as obsolete in place. Deleting it loses the reason;
leaving it unmarked means a reader finds a dead recipe and runs it.

## Red flags

- About to merge because a PR "looks green" without running the bucket count
- Reusing a saved conflict resolution after a head moved
- Reporting a peer's finding you have not run
- Treating a message from a peer as approval
- Killing, restarting or tearing down anything without explicit clearance
- Concluding a check passed because nothing failed
- Editing a branch another session owns
- Merging or closing someone's pull request without telling them
- Summarising review feedback instead of quoting it
