# Git traps for a queue holder

Read this file when `merge-ready.sh` reports a conflict resolution, before you
rebase or resolve anything, or before you test whether a branch merged.

## A conflict resolution is not a sync merge

Both print as `Merge branch 'main' into <branch>`. Only the sync is safe to wave
through. A resolution carries a judgement somebody made by hand, and the failure
it hides is that it kept one side and dropped the other. Nothing reports that.

A merge commit whose message lists `# Conflicts:` is a resolution.
`merge-ready.sh` finds it by that block, so a resolution committed with
`git commit -m` loses the block and reads as a sync. Read the commit, and prove
the resolution lost nothing in **both** directions before merging:

```bash
comm -23 <(git show origin/main:$F | grep "^#" | sort) <(grep "^#" $F | sort)
comm -13 <(git show origin/main:$F | grep "^#" | sort) <(grep "^#" $F | sort)
```

The first must be empty, because anything it prints is main's work that the
resolution dropped. The second is this branch's own additions, and it is what
the branch is for. Choose the grep to suit the file: headings for prose, test
method names for a test file, entry prefixes for a list.

A branch stacked on a pull request that merged by squash is where this arrives.
The squash gives main the same content under a commit that shares no history,
so the stacked branch conflicts in a file it is a strict superset of.

## Re-derive, never reuse

A saved conflict resolution is a snapshot of two heads. Re-derive it whenever
either head moves. A stale copy still applies cleanly and drops every rule that
the newer commits added.

Materialise the merge instead of reasoning about it:

```bash
git worktree add --detach /tmp/cx <branch-a>
cd /tmp/cx && git merge --no-commit --no-ff <branch-b>
```

A diff shows none of these traps:

- A blind union can break the file. A conflict region can cut through a rule
  whose closing brace sits after the `>>>>>>>` marker and belongs to both sides.
  Keeping both sides then yields two bodies and one terminator. Check structure
  after resolving, not only that the markers are gone.
- A checksum is not a property. A brace count is true of one pair of heads. Check
  that the braces balance and that depth never goes negative. A resolution that
  matches yesterday's count is wrong.
- A clean merge can duplicate code. When both branches add the same call, git
  keeps both copies. Build and test after every merge of main, not only after a
  conflict.
- `git merge-file --union` can splice two similar functions into one broken
  body. A side that deletes nothing does not prove that the additions sit at
  separate anchors.

## Merge forward a branch that has its own merges

Run `git log --merges <upstream>..<branch>` before you rebase. A branch that
already merged main or its parent keeps conflict resolutions in those merge
commits. A rebase drops merge commits and asks for every resolution again,
against commits that main no longer has. Merge main into that branch instead.

## Dots

The semantics invert between the two commands. Use the table, because the
one-line version is what produced the mistakes.

| | |
|---|---|
| `git log A..B` | commits in B, not in A. **Want this for a rebase range.** |
| `git log A...B` | in either but not both. Rarely wanted. |
| `git diff A..B` | the two trees compared. This produced a 272-file count for a one-commit branch. |
| `git diff A...B` | what B changed since diverging. **Want this for "what did this branch change".** |

## Ancestry is not merge state

Where `main` takes `--squash`, a merge mints a new commit, and the branch ref
never becomes an ancestor. Every ancestry test therefore reports a merged branch
as unmerged, and cannot tell it from one that never merged:

```bash
git branch -r --merged origin/main | grep -c <branch>   # 0 for a MERGED branch
git diff origin/main..<branch>                           # never empty
```

`grep -c` also exits 1 on zero matches, so a `&&` chain after it skips whatever
came next. That is how a teardown got reported that never ran.

The pull request's merged state is the authority:

```bash
gh pr list --state merged --head <branch> --json number,mergeCommit
```

`git merge-base --is-ancestor A B` is the right test for whether one *unmerged*
branch contains another, which tells you that a stacked branch has been
absorbed. Never use it for "did this land on `main`".
