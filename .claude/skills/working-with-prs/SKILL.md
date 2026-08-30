---
name: working-with-prs
description: Use when opening, gating, reviewing or merging a pull request in this repository, running the pre-PR gate, writing the body, making a branch testable, handling Codex findings, or sequencing several branches at once.
---

# Working with pull requests

Every change reaches `main` through a pull request. `main` is protected, so no
path skips this.

## The gate, before you open anything

1. `just cs` applies the formatter and rector fixes. Commit anything it
   changed. It works from a worktree: the finder uses explicit excludes and
   throws if it matches zero files, so a vacuous pass is not possible.
2. `just ci` is check-only. It reports style and rector violations but never
   rewrites files; `just cs` is the step that applies them. Fix every failure,
   including ones that pre-date your change.
3. Run a Codex review with `mcp__codex-cli__review` and `model: "gpt-5.6-sol"`.
   Always pass the model explicitly. This Codex account rejects the model the
   tool picks by default.

Review against `origin/main`, never `main`. A worktree's local `main` is often
stale, so a review against it reports findings for already-merged code.

e2e is not in the local gate. The `e2e` required check on the PR gates the
suite, and it runs the same `just e2e` on a disposable runner. Push, then read
that check. Fix every failure it reports, including pre-existing ones. Do not
run the full suite locally before you open the PR. See "Running the suite
locally is debugging, not gating" for the cases that still want a local run.

If `mcp__codex-cli__review` is not available, STOP and tell the owner. A missing
MCP server is a configuration fault worth investigating, so do not route around
it. Known cause: `codex-cli` is registered per-project in the user's own
configuration rather than in a committed `.mcp.json`, so a session running from
a worktree path may not pick it up.

A `codex review` with no output for ~5 minutes at near-zero CPU is hung,
typically at MCP startup. Kill it and fall back to:

```bash
codex exec -c model="gpt-5.6-sol" "Review the diff of this branch against origin/main (git diff origin/main...HEAD) for correctness bugs and convention violations. Actionable findings only."
```

## Documentation-only branches run steps 1 and 2 only

Skip the Codex review. Say so in the PR body, so the record shows the gate was
reduced deliberately rather than forgotten. CI still runs its own checks
including `e2e`; you skip the review, not them.

The test is the diff, not the intent. Every changed file must end in `.md`.
Check it, do not assume:

```bash
git diff --name-only origin/main...HEAD | grep -v '\.md$'
```

Any output at all means the full gate applies. A branch that also touches
`.env`, a Twig template, a fixture, `composer.json` or a `justfile` recipe is
not documentation-only, however small the change looks.

`just ci` still runs, because Markdown is not inert here: prettier covers some
of it, gamache's checks read `docs/`, and a docs commit can break a build that
greps them.

The reduced gate does not extend to Markdown that a script *executes*. A `.md`
file a script parses for commands is code wearing a `.md` suffix, so gate it
fully. A `SKILL.md` sits at the edge: an agent reads it rather than a script
parsing it, so the reduced gate applies. Say in the body that you made that
call.

The reason is proportion. Asking a reviewer model to read prose for correctness
bugs is cost with no signal, and running a gate that can never fail teaches a
reader to stop trusting gate results.

## Write the body for two readers

`main` allows squash merges only, so the PR body becomes the commit body. It is
permanent history.

- Say what changed, and why you rejected the alternative.
- Record what you verified and how. "Reverted the fix and watched the test
  fail" is worth more than "added tests".
- Name what you could not verify, in the body rather than buried in a comment.
  "Terraform is validate-only, the cloud path is unverified" is trustworthy in a
  way that silence is not.
- Say which gate you reduced or skipped, and why.
- Put any deploy-time need in the body: a rerender, a cache clear, a stack
  recreation. The person merging is not necessarily you.

## Make the branch testable, not just reviewable

A reviewer who has to build state by hand usually will not.

Point at the running instance. Every worktree serves its own branch at
`https://<slug>.loupe.dev.localhost`. Put that URL in the body with the login
(`dev@loupe.test` / `password`, or `admin@loupe.test` for the admin area).
Verify it responds before you write it down. Say plainly when a branch has no
worktree or nothing to click, rather than pasting a link that goes nowhere.

**Every such URL must be clickable, and that means one whole absolute URL in
plain text.** A bare host followed by paths in backticks — the shape a body
falls into naturally when there are several pages to point at — renders as
unclickable code spans, and the reviewer has to assemble each URL by hand. That
is enough friction to lose the click the seeding was for. So: no backticks
around a URL, no relative paths under a host given once, no `<slug>` or other
placeholder left for the reader to substitute. Write the full
`https://<slug>.loupe.dev.localhost/projects/…/review` per destination, even
when that repeats the host five times, and paste one into a browser before
opening the PR.

The same rule covers a branch with no worktree of its own: link the page on
whatever instance does serve it, rather than describing the route and leaving
the reader to construct it.

"Nothing to click" is a claim about the reviewer's options, not about routing.
A branch that adds no route can still produce reviewable output — HTML from a
renderer, a generated report, a file — and saying it has nothing to look at
because nothing is wired up is wrong, and reads as though the work cannot be
judged until a later branch lands. Publish the output as an artifact and link
it, and be clear about which questions it answers and which it defers.

Seed the data the change needs. `bin/console app:dev:seed` creates a user, an
admin and a project, and no documents, comments, verdicts or exports. A diff
feature is untestable without a document carrying several versions. Create
fixtures that contain the exact shape the bug had, not merely a happy path, and
tell the reviewer which one to open and what to look for.

Seed with a temporary `#[When('dev')]` console command created inside the
worktree. Run it, delete it, then confirm `git status --porcelain` is empty.
Fixtures must never reach the branch.

## Reviewing work you did not write

A green gate is not evidence the change is correct. In one wave of six branches,
all green on `just cs`, `just ci` and e2e, Codex found a real defect in every
one, and two of them could lose data. The gate proves the suite passes. It does
not prove the change is right.

So read the diff yourself, especially the part the author called routine. Where
an agent reports a decision it made on your behalf, check whether it is really
orthogonal to the open questions it claims not to touch. One wave branch added a
unique index and called it unrelated to a design decision it foreclosed.

Send findings back to whoever wrote the code, not to a fresh agent. The author
still has the context, and the second-order questions ("does anything else in
this file have the same shape?") catch the bug the reviewer did not spot. Ask
for the failure to be reproduced against the pre-fix code, so you know the new
test discriminates rather than passing vacuously.

## Running the suite locally is debugging, not gating

CI's `e2e` check is the gate. It runs the same `just e2e` against a disposable
stack on an isolated runner, with both `E2E_BASE_URL` and `MAILPIT_URL` set
correctly. See `.github/workflows/ci.yml`.

Locally the same suite is slower, destructive, and measurably less truthful.
Across one wave of five branches, every local e2e problem was environmental and
CI passed all five: three runs lost to the `MAILPIT_URL` omission below, one to
cross-worktree contention that CI then cleared. A gate that fails for reasons
unrelated to the diff is worse than no local gate, because someone has to spend
judgement deciding which failures to believe.

So push, then read the check. Fix every failure it reports, pre-existing ones
included.

Run it locally when you are working on a spec, not when you are finishing a
branch. These cases earn it:

1. A named spec you are changing or debugging: `just e2e
   tests/<area>/<spec>.spec.ts`. It is fast, and the only way to iterate.
2. A branch you cannot push yet, or one whose CI run you need to pre-empt for a
   reason you can state.

Neither case is the full suite before opening a PR.

### Aiming a local run at a sibling worktree

This is still the right tool for the two cases above when the branch lives in
another tree. Set both variables:

```bash
E2E_BASE_URL=https://<slug>.loupe.dev.localhost \
MAILPIT_URL=https://mailpit-<slug>.loupe.dev.localhost \
just e2e --workers=1
```

`E2E_BASE_URL` alone suppresses worktree detection, so the run reads the
*shared* Mailpit while the worktree's app sends to its own sidecar. Nothing
collides and nothing warns, and the assertions never see a message. About two
dozen registration, login and verification specs then fail in a way that reads
as broken auth rather than broken mail: each registration *succeeds*, times out
waiting for mail that went elsewhere, and its retry fails as a duplicate email.
Diagnose it by running `bin/e2e-target.sh` with and without the variable and
diffing the fourth line, not by re-running.

Warm its cache first:

```bash
( cd .claude/worktrees/<name> && bin/worktrees/compose-exec.sh bin/console cache:warmup )
```

This destroys that worktree's dev data. The suite's `install-reset` project
truncates every table. `just e2e` normally repairs the worktree afterwards, but
`bin/e2e-target.sh` deliberately blanks the worktree name when you set
`E2E_BASE_URL` by hand, so the automatic repair does **not** run for exactly
this invocation. Repair it yourself:

```bash
bin/worktrees/worktree-bootstrap.sh <worktree-name>   # from the main checkout
```

Use bootstrap rather than a bare `app:dev:seed`. `install-reset` also drops the
project the widget token belongs to, and bootstrap is what notices the token in
`.env.local` no longer resolves and reissues it.

Two runs that omit `MAILPIT_URL` cannot overlap. Each worktree has its own
Mailpit sidecar, so runs started with plain `just e2e` in different worktrees
are isolated, and so are runs that set both variables above. Two runs that set
only `E2E_BASE_URL` both fall back to the *shared* instance and read each
other's mail. Serialise those, or give each its own `MAILPIT_URL`.

## Merging

1. A branch's final gate happens on the branch **fully merged with current
   main**. A branch cut before a sibling merged has not really been gated: its
   e2e run never exercised the sibling's specs against its code.
2. Merge **immediately** after the gate goes green. Every merge to `main`
   between a branch's last sync and its own merge invalidates its PR through
   conflicts, or invalidates its gate.
3. After **every** merge, run `just cs` on main and commit the drift. Merge
   unions of two individually-clean branches produce fixer drift that otherwise
   lands on whichever branch syncs next.
4. Tear down a merged branch's worktree only **after** `gh pr merge` is
   confirmed, never in the same command chain. Confirm it by reading the result,
   not by having issued the command. A merge can fail for a reason that is not a
   conflict (mergeability still computing, a check that flipped), and destroying
   the tree first turns a retry into a rebuild from origin. Nothing is lost
   while the branch is still on origin, so verify that before anything else.
5. A conflict-free merge is not a correct merge. When one branch renames a
   class, a *new* file on another branch merges cleanly while still importing
   the old name. Git sees no conflict, because the file never existed on both
   sides. Run `just ci` before trusting such a merge; phpstan catches this, git
   does not.

   The signature variant is the same blind spot with a different tell. A branch
   that makes an argument required lives on and absorbs merges, and each
   incoming merge can bring a *new* file from a sibling that constructs the old
   shape. It existed on only one side, so git has nothing to report, and the
   result is a runtime `ArgumentCountError`. Grepping for the changed symbol is
   a good first pass and not sufficient: it finds only the shapes you thought to
   search for, and goes stale at the next merge.
6. Resolving a conflict by taking one side can silently revert the other side's
   fix. Before accepting a resolution, re-verify the *behaviour* both branches
   were protecting, not just that the markers are gone. The sharpest form is
   rename/rename, where neither side is correct and the answer is the newer
   branch's **content** at the other branch's **path**.
7. "Pull Request is not mergeable" right after `git push` usually means GitHub
   has not recomputed mergeability yet. Wait a few seconds and retry.
8. Read the checks against the head you are about to merge. After you sync a
   branch, the rollup can still describe the *previous* head, so a green reading
   is evidence about a commit that no longer matters. Key the wait on
   `headRefOid`, require the full set of required checks to be present, and only
   then treat green as green:

   ```bash
   gh pr view <n> --json headRefOid,statusCheckRollup \
     -q '"\(.headRefOid) \([.statusCheckRollup[]|(.conclusion//.state//"PENDING")]|join(","))"'
   ```

   A rollup with fewer entries than the ruleset requires means runs have not
   registered yet, which reads identically to "nothing left to wait for".

## After the merge, write the changelog entry

Every merged pull request earns one line in `docs/CHANGELOG.md`, under `[Unreleased]`, newest first. Tag it `Added`, `Changed`, `Removed` or `Fixed`. Write one sentence saying what changed from the reader's side, and leave the reasoning to the PR body, which the SHA and the PR number both point at.

```
- `0406b9c` (#209) — **Fixed:** what changed, from the reader's side.
```

The entry anchors to the first-parent squash commit on `main`, which is what `git log --first-parent` shows. That commit does not exist until you merge, so the entry cannot ride the pull request it describes. Write it immediately after the merge, in the next documentation pull request.

One entry per pull request, not one per branch. A branch that shipped six features earns six lines, because a reader looking for when tags arrived should find a line about tags rather than a paragraph about the wave that contained them. Tracker churn in `docs/NEXT_STEPS.md` earns no entry.

## What the ruleset actually requires

`main` allows `--squash` only. A plain `gh pr merge` fails with
`GraphQL: Merge commits are not allowed on this repository`. Use
`gh pr merge <n> --squash`.

Do not diagnose a rejected merge with `gh repo view`. It reports repository
*settings*, which happily say all three merge methods are allowed while the
ruleset forbids two. The ruleset is the authority:

```bash
id=$(gh api repos/ubermuda/loupe/rulesets -q '.[0].id')
gh api repos/ubermuda/loupe/rulesets/$id -q '.rules[]|select(.type=="pull_request")|.parameters'
```

It requires eight CI checks (`lint`, `cs-check`, `phpstan`, `arkitect`,
`gamache`, `audit`, `phpunit`, `e2e`) and **one approving review**.

An approval in chat is not a GitHub approval. Check before concluding a merge is
blocked by something else:

```bash
gh pr view <n> --json reviewDecision,mergeStateStatus
```

A job that runs in CI but is not in that list cannot block anything. Adding one
is a repository setting, so a branch cannot do it. If you add a CI job, say in
the PR body that requiring it is still owed, or the job is decoration.

**Never approve your own work.** The review itself stays with a human.

**Merging does not.** A PR that is *approved* with *all required checks green*
is good to merge, so merge it without asking. The approval already carried the
decision, and waiting for a second confirmation parks finished work in a queue. Follow the merge protocol above when you do:
sync with current main, let the checks re-run on the synced head, merge, then
run `just cs` on main.

So autonomous work means: open the PR, then merge it once the owner has approved
it and CI is green. Never merge something unapproved. Never merge past a failing
or pending check. Never use `--admin` to bypass either.

## Running several branches at once

Give each branch its own worktree and keep them off each other's files. The
sharpest case is a shared tracker or changelog: if every branch deletes its own
entry from `docs/NEXT_STEPS.md`, they all collide in the same region. Have wave
branches leave it alone and clean it up in one trailing PR after the wave lands.

Sequence by blast radius. A branch that changes shared infrastructure, such as
compose files, environment resolution or CI wiring, should merge last, because
merging it invalidates the environment its siblings are still being gated in.
Say so in its body.

Land signature changes early. A branch that makes an argument required grows
more dangerous with every sibling merged ahead of it, because each merge can
bring a new file constructing the old shape (see merge protocol item 5).

Batch by file overlap, not by theme. Two agents editing the same module conflict
no matter how unrelated their tasks sound.
