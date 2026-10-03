# Dispatch an agent for a branch whose session is gone

Dispatch when an approved branch needs a fix that is not a conflict, such as a
failing check that the diff explains. Dispatch only when no bridge worker picks
the fix up and the branch's session is gone. Do not fix the branch in your own
context, because your context must last as long as the queue.

**Obsolete for a conflict since 2026-09-28.** The owner said: "I want all 3
reasons and the merge queue should stop fixing conflicts". The bridge's `fix-pr`
worker now resolves a conflict with the `loupe-stage-fix-round` skill, which
carries this procedure. Never dispatch an agent for a conflict. The two sections
below stay as the record of the procedure that the skill took over.

## List the conflicting files first (obsolete since 2026-09-28)

Run a trial merge in a throwaway worktree:

```bash
git worktree add --detach /tmp/trial-<n> origin/<branch>
( cd /tmp/trial-<n> && git merge --no-commit --no-ff origin/main; git diff --name-only --diff-filter=U )
git worktree remove --force /tmp/trial-<n>
git log --oneline $(git merge-base origin/main origin/<branch>)..origin/main -- <conflicting files>
```

The last command names the commits on `main` that caused the conflict.

## Send the prompt

The template below is a conflict prompt, obsolete since 2026-09-28 for the
reason above. Use it for a fix that is not a conflict, changed as the paragraph
after it says.

Dispatch one agent per branch, in the background. Send every dispatch in one
message, so the agents run in parallel. A subagent does not inherit your loaded
skills, so paste the template below and fill each `<placeholder>`. Do not
rewrite the rules in your own words.

````text
Resolve pull request #<n>, branch <branch>, so it merges cleanly into main.
It conflicts in: <conflicting files>. These commits on main caused it: <commits>.
Read the pull request body first, because you lack the author's context.

Rules:
- Work in the existing worktree .worktrees/<name>. Run every command there in a
  subshell: ( cd .worktrees/<name> && ... ).
- Check `git worktree list` first. If the worktree is gone, create it from the
  main checkout with `git worktree add .worktrees/<name> <branch>`, then
  `just worktree-up <name>`.
- Make one trivial edit in the worktree first, and confirm it lands. Stop and
  report if the write is rejected.
- Do not use Serena edit tools, because they are bound to the main checkout.
  Do not use cp, rsync or shell redirection to route around a rejected write.
- Invoke project-worktrees and working-with-prs first, and the project skill
  for each conflicting file's area.
- Run `git merge origin/main`, never a rebase. Keep the intent of both sides.
- Prove the resolution in both directions with comm, for each conflicting file:
    comm -23 <(git show origin/main:$F | grep "^#" | sort) <(grep "^#" $F | sort)   # must be empty
    comm -13 <(git show origin/main:$F | grep "^#" | sort) <(grep "^#" $F | sort)   # the branch's own additions
  Choose the grep to suit the file: headings for prose, test method names for a
  test file, entry prefixes for a list.
- Run `just cs`, then `just phpstan`, `just arkitect` and `just gamache`, and
  fix every failure. Run PHPUnit only on the tests for the conflicting files.
- Keep the `# Conflicts:` block in the commit message, and add one plain line
  per file that says how it was resolved. `git commit -m` drops the block, so
  commit like this:
    m=$(git rev-parse --git-path merge-desc)
    { cat "$(git rev-parse --git-path MERGE_MSG)"; echo; echo "<file>: <how it was resolved>"; } > "$m"
    git commit -F "$m" --cleanup=verbatim
- Push without force. Do not merge, approve or comment on the pull request.
- Read CI on the pushed merge. CI's required checks are the full gate.
- Return the merge commit SHA, each resolution with its reason, the comm output
  and the CI result.
````

For a fix that is not a conflict, keep the worktree, write-check, skill, gate
and push rules, and replace the merge and `comm` rules with the failing check
and its log.

## Verify the result before you merge

This step still applies, to a resolution that a bridge worker pushed. Read the
pushed merge commit, and run the `comm` proof yourself, as
`references/git-traps.md` says. Then run `scripts/merge-ready.sh <n>` on the new
head. The owner's approval covers a resolution that passes the `comm` check, so
merge without asking him again. A fix that is not a conflict is new content, and
it needs a new approval.
