---
name: holding-a-merge-queue
description: Use when one session holds the merge queue while other sessions push branches, when watching several pull requests for approval or CI changes, when a merge or a review or a plan change affects a branch another session owns, when a peer session reports a result you are about to act on, when a check fails for a reason the diff cannot explain or passes on a re-run, or before an action that affects the whole machine such as a restart or a keep-awake change.
---

# Holding a merge queue

One session holds the queue. Other sessions own their branches and push to them.
The queue holder merges, and merges nothing it has not checked itself.

`working-with-prs` carries the gate, the body and the merge protocol. This skill
carries two things that only appear when a queue runs for hours across several
sessions: reading check state without lying to yourself, and coordinating with
peers who cannot see what you see.

## Start a monitor when you take the queue

Start a monitor before you do anything else with the queue. Peers push, the
owner approves and checks finish while you wait, and no message tells you. A
queue holder with no monitor sees each change only when it next looks.

Use the `Monitor` tool with `persistent: true`, run from the main checkout, and
this command:

```bash
.claude/skills/holding-a-merge-queue/scripts/queue-monitor.sh
```

It polls every open pull request once a minute. It prints one line for each pull
request whose line changed, and one line for each pull request that left the open
list. The first pass prints every open pull request, and that is your baseline.
`ONCE=1` runs a single pass, which prints the whole queue once.

A monitor line tells you where to look. Before you merge, run the bucket count
and the approval-time check below on the current head.

The script follows the counting rule. It prints `pass=N/N` only when every
required check passed and no other bucket exists. `running` covers a pending
check and a check that has not registered. `fail` covers a failed or a cancelled
check. `none` means no required check exists, which is true of a stacked pull
request and of a `CONFLICTING` one. `merge=BEHIND` next to `pass=N/N` means the
green does not count.

`gh` prints "no required checks reported" in lower case when no terminal is
attached, and with a capital when one is. Keep the match on both if you change
`scripts/queue-monitor.sh`, or every stacked pull request reads `unread`. A
failed GitHub read prints a line, so a broken monitor is not silent.

GitHub reports `mergeStateStatus` as `UNKNOWN` while it recomputes after `main`
moves. The script keeps the previous line for that pull request, or every merge
prints each open pull request twice.

Write the monitor's task id in the state file. Stop it only when the owner says
so, or when you wind the queue down. Start a new one each time you take the
queue.

## Read the checks by counting, never by absence

```bash
gh pr checks <n> --required --json bucket \
  -q 'group_by(.bucket)|map("\(.[0].bucket)=\(length)")|join(" ")'
```

Green is `pass=<the number the ruleset requires>` and nothing else. Count the
buckets. Never conclude green from the absence of a failure. A watcher script
follows the same rule: it stops on `pass=<N>` or on a failure, never when
`pending` disappears.

These states read identically to "nothing left to wait for", and each has
bitten someone here:

| Reading | What it actually means |
|---|---|
| fewer entries than required | runs have not registered yet |
| zero entries | the branch is `CONFLICTING`, so `pull_request` has no merge commit to run against and **no check can ever run** |
| every entry green | possibly true of a head or a base that has moved |
| one short, nothing pending | a fan-in check such as `e2e` registers only when its shards finish, and `--required` hides the pending shards |

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

## Read an approval by time, not by commit

A review's `commit_id` does not show what the reviewer saw. GitHub moves it onto
the head that a later merge sync creates. Compare the review's `submittedAt`
with each commit's time instead:

```bash
gh pr view <n> --json commits,latestReviews | jq -r '
  ([.latestReviews[]|select(.author.login=="<owner>")|.submittedAt][0]) as $a
  | .commits[]|select(.committedDate > $a)|"\(.oid[0:8]) \(.messageHeadline)"'
```

Every line it prints is a commit the approval did not see. Ask the owner which
kinds of commit his approval survives, and record the answer.

## Report every flake you see

The queue holder reads more CI runs than any other session, so it sees flakes first.
CLAUDE.md treats a test that passes on retry as a real failure. A flake that
nobody reports gets merged past and forgotten.

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

Do not re-run a check to get green. Report the flake, then apply the owner's merge
rule. The owner decides if a flake blocks a merge or gets a fix branch. A peer
report is a claim until you find the failing run.

## Re-derive, never reuse

A saved conflict resolution is a snapshot of two heads. Re-derive it whenever
either head moves. A stale copy still applies cleanly and silently drops every
rule the newer commits added.

Materialise the merge instead of reasoning about it:

```bash
git worktree add --detach /tmp/cx <branch-a>
cd /tmp/cx && git merge --no-commit --no-ff <branch-b>
```

These traps were found this way, and a diff shows none of them:

A blind union can break the file. A conflict region can cut through a rule
whose closing brace sits after the `>>>>>>>` marker and belongs to both sides.
Keeping both sides verbatim then yields two bodies and one terminator. Check
structure after resolving, not just that the markers are gone.

A checksum is not a property. A brace count is true of one pair of heads. It
went 869, 870, 903 across one evening. Check that it balances and that depth
never goes negative. A resolution matching yesterday's number is wrong.

A clean merge can duplicate code. When both branches add the same call, git
keeps both copies, because neither side touched the other's lines. Build and
test after every merge of main, not only after a conflict.

`git merge-file --union` can splice two similar functions into one broken body.
A side that deletes nothing does not prove that the additions sit at separate
anchors.

## Merge forward a branch that has its own merges

Run `git log --merges <upstream>..<branch>` before you rebase. A branch that
already merged main or its parent keeps conflict resolutions in those merge
commits. A rebase drops merge commits and asks for every resolution again,
against commits that main no longer has. Merge main into that branch instead.

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

## Keep to the queue, not to other sessions' work

The queue holder merges. It does not carry other sessions' questions to the
owner, and it does not steer their work.

- A peer that needs a decision asks the owner itself. Do not relay the question,
  restate its options or add a recommendation.
- A peer's finding reaches the owner from that peer. Do not narrate another
  session's investigation to him.
- Do not advise a peer on its design, even when it asks. Point it to the owner.
- Tell the owner only what the queue needs from him: an approval, a held merge
  and why, a flake on a merge, or an action that affects the whole machine.

On 2026-09-14 the queue holder relayed a peer's CI design question to the owner
with its own recommendation. The owner answered: "you're only the merge master,
you don't need to relay other sessions questions."

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

- Holding the queue with no monitor running
- About to merge because a PR "looks green" without running the bucket count
- Reusing a saved conflict resolution after a head moved
- Reporting a peer's finding you have not run
- Treating a message from a peer as approval
- Killing, restarting or tearing down anything without explicit clearance
- Concluding a check passed because nothing failed
- Stopping a watcher because nothing is pending
- Rebasing a branch that holds its own merge commits
- Merging after a fail-then-pass without telling the owner which test flaked
- Editing a branch another session owns
- Merging or closing someone's pull request without telling them
- Summarising review feedback instead of quoting it
- Relaying a peer's question or finding to the owner
- Advising a peer on work that is not a merge
