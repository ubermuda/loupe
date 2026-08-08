# Next steps

Open work and observations worth revisiting. Delete items entirely once resolved.

Entries are ordered by priority (high → medium → low); insert new entries at
the end of their priority band. Format and rules: `project-next-steps` skill.

## `just e2e` from a worktree gates the main checkout unless `E2E_BASE_URL` is set


**Author:** Claude · **Type:** tooling · **Priority:** high · **Status:** pending

There are **two** independent fallbacks that both silently point an e2e run away
from the branch under test, and neither announces itself.

`just e2e-up` resolves its target with
`git worktree list --porcelain | awk '/^worktree /{print $2; exit}'` — the
**first** entry, which is always the main checkout — and serves that. This is
by design and documented. The trap is the consequence: a `just e2e` run started
from a worktree, without `E2E_BASE_URL`, exercises the main checkout's code and
**passes while gating none of the branch**. Separately, `playwright.config.ts`
falls back to `https://loupe.dev.localhost` when `E2E_BASE_URL` is unset, which
points at the developer's own dev host — and the suite is destructive, so that
path truncates every table in the working database.

Observed on 2026-08-03: a worktree run "failed" because the rendered page had no
filter bar at all. It was serving `main`'s template. The failure was only
noticeable because the branch added visible UI — a branch changing behaviour
without changing markup would have gone green while testing nothing.

Two fixes worth considering together: make `playwright.config.ts` **throw**
rather than default when `E2E_BASE_URL` is unset, since a wrong target is worse
than a refusal; and have `just e2e` detect that it is being run from a worktree
and either target that worktree or refuse. Until then the only reliable check is
to prove the target after every run — the worktree database must show the
`install-reset` truncation while the main `app` database is untouched.

## `guzzlehttp/guzzle` 7.15.1 carries two published security advisories


**Author:** Claude · **Type:** security · **Priority:** high · **Status:** pending

`composer audit` reports two advisories against the installed `guzzlehttp/guzzle`
7.15.1, both published 2026-08-03 and both fixed in **7.15.2**:

1. **CVE-2026-69246 (high)** — a noncanonical host can bypass host-based checks
   (`GHSA-v5mv-p594-2x33`).
2. **CVE-2026-69245 (medium)** — a noncanonical cookie domain keeps subdomain scope
   (`GHSA-f7vp-7xgx-4w4r`).

Guzzle is transitive: `league/oauth2-client` 2.9.0 requires `^6.5.8 || ^7.4.5`, so it sits
on the Google and GitHub social-login path. `league/flysystem` only declares a conflict
below 7.0. The existing constraint already permits the fix, so the whole change is
`composer update guzzlehttp/guzzle` with no `composer.json` edit — but it moves
`composer.lock`, so it wants a branch and the full pre-PR gate rather than a commit to
`main`.

This was invisible until 2026-08-04, because `composer` could not reach the GitHub API at
all while the container was rate-limited anonymously. Worth remembering as the argument for
running `composer audit` on a schedule: the advisories had been public for a day and
nothing in the project would have surfaced them.

## Unset optional config should disable a feature, not break it


**Author:** Geoffrey · **Type:** feature · **Priority:** high · **Status:** pending

Owner note (2026-07-27, reviewing DEPLOY.md on PR #85): leaving an optional
integration's configuration unset should cleanly disable that feature, rather
than leaving a path that fails when a user reaches it.

The Terraform side already behaves this way — every entry in `extra_env`
(`terraform/main.tf`) is omitted from the app spec when its variable is empty,
and `enable_mercure` keys off whether `mercure_jwt_secret` is set. The gap is
in the application: with those variables absent the env vars simply do not
exist, and the affected code paths error rather than being switched off.
`DEPLOY.md`'s "If unset" column records the current behaviour honestly —
"Billing paths fail", "Those buttons fail" — and that is what should change.

Affected surfaces, each needing its own decision about what "disabled" means:
**Stripe** (`STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`) — probably the whole
billing area hidden, which interacts with the `#[PaywallExempt]` work in
"Encapsulate Billing: replace #[PaywallExempt] with a firewall-level rule";
**OAuth** (`OAUTH_GOOGLE_ID`/`_SECRET`, `OAUTH_GITHUB_ID`/`_SECRET`) — the
provider's button should not render at all rather than 500 on click, and each
provider should be independently toggleable; **Mercure** — already degrades
correctly, since a failed publish is caught and logged, and is worth using as
the reference shape; **`ADMIN_EMAIL`** — already a no-op when unset.

Relevant to self-hosting: a self-hoster who wants neither billing nor social
login should get a working install without setting either. See "Self-hosting
audit".

## Set a real INSTALL_TOKEN value in the production deploy config


**Author:** Claude · **Type:** security · **Priority:** high · **Status:** pending

`src/Module/Account/Service/InstallAccessGuard.php` gates `/install` and fails
CLOSED: with an empty `INSTALL_TOKEN`, it 404s in `prod` specifically (only
outside prod — dev, test, `just worktree-up` — does an empty token leave the
wizard open, deliberately, so those keep running the wizard unattended). The
vulnerability this entry originally tracked (a forgotten variable silently
leaving an admin-minting endpoint open in production) is closed at the code
level.

What remains is operational: `terraform/main.tf` now declares `install_token`
as a variable and wires it into `extra_env` (omitted from the app spec when
empty), but that only means the plumbing exists — someone still has to supply
the actual secret value when running `terraform apply` against production.
Until that happens, `/install` 404s in prod, so the first administrator has to
be created from a shell instead (`bin/console app:admin:create`, see
`DEPLOY.md` → "Recovering an instance"). Track this as a pre-launch deploy
checklist item, not a live code vulnerability — hence the lower priority than
when this was first filed.

## Data-export object storage is proven on Garage, not on a hosted provider

**Author:** Claude · **Type:** tooling · **Priority:** medium · **Status:** pending

`EXPORT_STORAGE=s3` used to be proven only to *wire up* — every automated test
exercises the local adapter. It has now been run end to end against a real
S3-compatible store: `just garage-up` starts an opt-in single-node Garage and
creates the bucket and key, and pointing `.env.local` at it makes
`e2e/tests/account/data-export.spec.ts` pass unchanged.

**That run found a real bug, which is the argument for doing it at all.**
Flysystem's `move()` reproduces the source object's ACL on the destination,
which means reading it — and Garage implements no ACLs, answering
`GetObjectAcl` with a 501. Every export failed with an unexplained "unable to
move file". `DataExportArchiveBuilder` now states the visibility instead of
carrying it over, which skips the read. A run against MinIO had passed happily,
because MinIO implements ACLs; the bug was only visible against a store that
does not.

Settled by that run: the write-to-`<key>.tmp`-then-`move()` completes and leaves
no `.tmp` behind; the move is a server-side copy; the download streams a valid
ZIP; and path-style addressing works, which Garage requires.

Still open:

1. **Neither Garage nor MinIO is the provider this deploys to.** `EXPORT_STORAGE_ACL`
   exists because canned ACLs are rejected differently by different providers,
   and the value that matters is the one DigitalOcean Spaces accepts —
   `terraform/spaces.tf` creates that bucket. Garage says nothing about it,
   having no ACLs at all. See `DEPLOY.md` → "Known gaps".
2. **The missing-object path is code-verified, not run.** `AsyncAwsS3Adapter`
   throws `UnableToReadFile` and `DownloadDataExportController` catches
   `FilesystemException` and 404s, so the path is sound by inspection — but no
   run has deleted an object and requested its link.

Closing this means one export against a real Spaces bucket with the ACL
Terraform sets.


## Flip the CSP from report-only to enforcing

**Author:** Claude · **Type:** security · **Priority:** high · **Status:** pending

`config/packages/nelmio_security.yaml` still sends the policy under `report`
rather than `enforce`, so it blocks nothing.

**The nonce work it was waiting on is done.** `importmap()` in
`templates/base.html.twig` — the only inline scripts this app emits — carries
`csp_nonce('script')`, and the nonce reaches the header as well as the markup:
observed on a dev request configured with a report policy, `script-src` came
back containing `'nonce-…'` matching the rendered tags. `NelmioSecurityBundle`
is now registered in every environment for that to be possible, with only prod
configuring anything that emits a header.

What is left is the flip itself, plus two allowlist edits it enables:
`'unsafe-inline'` can come out of `script-src` (browsers ignore it once a nonce
is present), and `style-src` still needs it, because the inline styles are not
nonced.

**It cannot be verified from a dev gate, and that is the real blocker.**
`just e2e` runs against a dev-mode target, which by design sends no policy at
all, so a green suite proves nothing about an enforcing header. Verifying needs
a prod-like target — `compose.prod.yaml` is the obvious candidate, driven by
`compose.prod.env`. Build that first; shipping the flip on a dev-green gate
risks a blank page for every real user, since a policy that blocks the
importmap breaks all JavaScript on the page.

Two things already checked, so they need not be rechecked. The `cdn.jsdelivr.net`
scripts in `base.html.twig` are gated behind `FRANKENPHP_HOT_RELOAD` and never
render in production, so they need no allowlist entry. And `connect-src` still
omits the Mercure hub origin, which is fine while browser-side Mercure is
disabled in `assets/controllers.json` — enabling browser SSE would need it
added.

**Do not treat this flip as a mitigation for markup-injection findings.** A
review of the Markdown sanitizer produced an attack where a `class` attribute on
document-supplied `<code>` selected the app's own compiled stylesheet rules to
paint a full-screen phishing overlay. An enforcing CSP would not have stopped
it: CSP governs neither `class` attributes nor which of the app's own rules
apply, and `style-src 'self'` permits exactly the stylesheet the payload used.
The mitigation for that class of attack is restricting what the sanitizer
admits. Flipping the CSP buys script-injection defence, not markup-shaped
attacks that stay inside the app's own CSS.

## The MCP connection drops repeatedly, and reconnecting does not restore the tools

**Author:** Claude · **Type:** bug · **Priority:** high · **Status:** pending

An agent session connected to this app over MCP lost the connection three
separate times on 2026-08-01, in three different ways: `ConnectionRefused`, a
silent drop mid-task, and finally a reconnect that reported success but left the
client with no tools. Once in that last state the client sees `MCP error -32001:
Request timed out` and cannot call anything. The only recovery found was
restarting the agent session entirely; `/mcp reconnect` restores the transport
without repopulating the tool list.

This matters more than a flaky dev convenience: submitting and revising
documents over MCP is how documents get into the app at all, so a dropped
connection stops the primary workflow, and it did so mid-task here.

**The server side is healthy — do not start by debugging it.** Verified while
the client was in the broken state: `bin/console debug:mcp` lists every tool;
`var/log/dev.log` shows them registering on each handshake; `POST /mcp` answers
200; unauthenticated requests answer 401; and all containers were up.

**Three dead ends, recorded so they are not chased again.** `GET /mcp`
answering 405 and `POST /mcp` answering 202 both look like smoking guns and are
neither — 405 on GET is a legal way to say the server offers no server-initiated
stream, and 202 is the correct response to a notification, which has no reply by
design.

The third was, until 2026-08-05, this entry's one remaining lead: every tool
registering **twice** per handshake, with two `Manual element registration
complete` lines. **That is a dev-only web-profiler artifact and is not a bug.**
`Symfony\Bundle\McpBundle\Profiler\DataCollector::lateCollect()` calls
`$this->builder->build()` on every request, which re-runs the loaders against
the shared registry — the bundle's own comment there says re-building on an MCP
request is harmless. `WebProfilerBundle` is registered for `dev` and `test` only
(`config/bundles.php`), so a production instance registers once. Nothing to fix.

**Also checked and dead for now: upgrading out of it.** `symfony/mcp-bundle`
0.12.0 and `mcp/sdk` 0.7.0 are both installed, and both are the newest releases
that exist — there is no newer version whose notes could mention tool-list
delivery or session handling. Re-check when either publishes a release; both are
pre-1.0 and moving.

**What the entry now turns on, and why it is stuck.** Every observation here was
against the local dev host. Whether a deployed instance drops connections the
same way decides whether this is a local annoyance or a production defect
affecting every agent that connects — and there is no deployed instance to test
against yet. That makes this **blocked on having a deployment**, not on
analysis. Until one exists there is no experiment left to run that has not been
run.

## Give each agent its own container in the cloud instead of sharing one dev stack


**Author:** Geoffrey · **Type:** tooling · **Priority:** high · **Status:** pending

Every git worktree gets its own nginx sidecar, database and URL, but they all
share **one php-fpm container, one Mailpit and one Postgres**. That sharing is
the root of most of the parallel-work pain, and on 2026-08-03 it cost most of a
day across nine concurrent branches.

What sharing actually causes, all observed rather than predicted:

- **php-fpm worker exhaustion.** `docker/dev/php-fpm/zz-pm.conf` sets
  `pm.max_children = 20` with `pm = dynamic` and `pm.start_servers = 4`. One
  pool serves every worktree plus all e2e traffic. The logs carry both failure
  modes — 71 "seems busy … 0 idle" spawn-rate warnings and 4 "server reached
  pm.max_children setting (20)". A request that gets no worker returns nothing:
  no response body, no fatal, no log line, and a submit button left disabled
  with no validation error. That signature is documented elsewhere as a
  cold-cache symptom and has been misattributed that way more than once.
- **e2e cannot be parallelised at all**, because Mailpit is shared and
  mail-asserting specs across concurrent runs read each other's messages. That
  forces `workers: 1` and one branch at a time — roughly 8.5 minutes per branch,
  which becomes the throughput ceiling for a multi-branch wave.
- **Any sibling's `just ci` starves an in-flight e2e run**, so gating has to be
  coordinated by hand. That coordination does not survive parallel agents; it
  has to be remembered by whoever is orchestrating.

Per-agent containers would remove all three by construction rather than by
convention, and would also end the class of bug where a stop or a kill reaches
only the host-side wrapper (see 'Host `pkill` does not kill a process inside the
php-fpm container').

Worth deciding alongside it: whether the e2e suite still needs to be destructive.
Today the `install-reset` project truncates every table as its last act, which
is only tolerable because the target is disposable — see 'A green e2e run leaves
the worktree database unusable for the next one'.

Cheaper interim step if this stays unbuilt: raise the pool limits. Observed peak
demand is 17–20 concurrent, so `pm.max_children = 40` with
`pm.start_servers = 12` and `pm.min_spare_servers = 8` gives headroom for bursts
without waiting on the spawn rate. That addresses reliability but not
parallelism.

## Proper HTTP API + outbound webhooks




**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner request (2026-07-25): a real public HTTP API and outbound webhooks
(events like document created/revised, review verdict, comment added — so
integrators don't have to poll). Existing relevant surface: the `/api`
firewall + ApiToken scopes (currently only site-review + MCP), and the
deny-by-default `access_control` rule on `^/api` in
`config/packages/security.yaml` — every new /api route must add its own scope
rule above that line or it is refused. Design questions for the spec phase: API versioning,
token scopes per resource, webhook subscription storage + signing (HMAC),
delivery retries (the existing Messenger worker is the natural transport),
and dogfooding the API from the CLI/widget.

## Surface what the comments say about a document, not just the review verdict


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner decision (2026-07-27, via site review): the state the product exposes
should be derived from the comments themselves — for a site review, things like
"has drafts" / "has addressed comments"; for a document, "has addressed
comments". The four-step loop ribbon (Proposed → In review → Revise → Approved)
and the `LoopStage` enum behind it were the visible half of that idea and are
already gone.

**This entry used to also propose deleting `Document::$status`, and that half
was dropped on 2026-08-02 because its premise was wrong.** `status` is not a
comment-derived value that drifted — it is a denormalised copy of the latest
`Review`'s verdict, mapped one-to-one by `SubmitReviewHandler`. Removing it
would have been a refactor with no product content, and it is not happening; the
column stays. Two conclusions that were drawn from the old framing and are also
wrong, recorded so they are not re-derived: filtering the documents list by
status is a plain `WHERE` and not a join, and document approval does not lose
its write target, because approval writes a `Review`.

What remains is the part that was the actual intent, and it does not exist in
any form: **signals computed from the comments on a document.** "Has addressed
comments" is the named example, and plausible neighbours are open-thread counts,
orphaned counts, and whether every thread has been answered.

The raw material only just arrived. Document comments had two booleans,
`resolved` and `orphaned`, and no notion of *addressed* at all — that concept
existed only on `SiteReviewComment`. The comment-status work replaces the
boolean with a three-case status (pending, addressed, resolved) on the thread
root, which is what makes "has addressed comments" answerable for a document.

Worth settling when this is picked up: whether these signals are computed per
request or denormalised (the documents list already issues one
`countOpenByVersion()` per row, so a naive per-row derivation compounds an
existing N+1 — `SiteReviewCommentRepository::submittedStatusCountsForProject()`
is the in-repo precedent for a single grouped tally), where they surface
alongside the existing status badge, and whether the MCP payload carries them.

## Automate the worktree lifecycle instead of driving it by hand


**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

`just worktree-up` provisions a worktree well, but nothing *invokes* it and
nothing tears it down, so every step around it is manual and each one failed at
least once during the nine-branch self-hosting wave on 2026-07-28. The failures
were all lifecycle, never provisioning:

- **A harness-created worktree is not a provisioned one.** An agent launched
  with `isolation: "worktree"` gets a real git worktree under
  `.claude/worktrees/agent-<id>` with no `vendor/`, no `.env.local`, no
  database and no sidecar — so it cannot run `just ci`. That is why the wave
  hand-created and hand-named every worktree instead of using harness
  isolation.
- **`worktree-up` dies on a large container.** `cache:clear` runs
  `str_replace` over the whole compiled container XML and exceeds the php-fpm
  container's 128M CLI `memory_limit`. Two of the nine worktrees failed to
  provision until the limit was raised by hand, and that change lives only in
  the running container — a rebuild loses it.

  Because of this, `worktree-up` cannot be used to **reset** a worktree whose
  database an e2e run has truncated, which is the case that matters most. The
  working sequence, reconstructed by hand three separate times before being
  written down, takes about ninety seconds — run from the worktree, and note
  the `-d memory_limit=512M` is load-bearing on **every** line:

  ```bash
  bin/worktrees/compose-exec.sh php -d memory_limit=512M bin/console doctrine:database:drop --force --if-exists
  bin/worktrees/compose-exec.sh php -d memory_limit=512M bin/console doctrine:database:create
  bin/worktrees/compose-exec.sh php -d memory_limit=512M bin/console doctrine:migrations:migrate --no-interaction
  bin/worktrees/compose-exec.sh php -d memory_limit=512M bin/console app:dev:seed --reissue-widget-token
  # paste the printed token into .env.local as SITE_REVIEW_WIDGET_TOKEN, then:
  bin/worktrees/compose-exec.sh php -d memory_limit=512M bin/console tailwind:build
  ```

  Three things that waste time if missed: the drop fails with *"1 other session
  using the database"* unless the messenger consumer is stopped first; the token
  step is not optional — skipping it leaves the site-review widget in its
  rejected-token state, which surfaces as unrelated-looking spec failures; and
  the final `tailwind:build` matters whenever `app.css` changed in a merge,
  because a worktree serving stale CSS fails any spec asserting on a new class
  in a way that reads as a template bug rather than a missing build.
- **`vendor/` goes stale silently.** `worktree-up` rsyncs `vendor/` from the
  main checkout, but nothing re-runs `composer install` on main after a merge
  changes `composer.lock`. After the export-storage branch merged, main's
  `vendor/` had no Flysystem, so every worktree provisioned from it failed
  `just ci` with a missing-class error that looks exactly like broken code.
- **Messenger consumers die when the cache rebuilds under them**, and a live
  consumer then blocks `just worktree-down`, which drops the database *after*
  git has already deregistered the worktree — leaving a directory behind.
- **Running `just cs`/`just ci` in a worktree while an e2e suite runs against
  it corrupts the run.** It produced two false-red failures in that wave, both
  presenting as an unrelated flaky spec.

The shape of the fix is Claude Code's `WorktreeCreate` and `WorktreeRemove`
hooks: create runs the bootstrap (making harness isolation usable, which is the
biggest win), remove kills consumers before `just worktree-down`. Two fixes do
not belong in hooks and are worth doing regardless — `worktree-up` should run
`composer install` when `vendor/` does not match the lock it is being given,
and it should raise the CLI memory limit itself rather than depending on a
hand-edited container.

Prior art worth reading before designing this: `raine/workmux` exposes
`post_create` / `pre_merge` / `pre_remove` hooks plus declarative file
copy/symlink and pane layout; `gausejakub/claude-skills` ships a
`laravel-worktrees` skill doing per-worktree database, domain and port
provisioning with Claude Code hooks for init and teardown — the same problem
solved for Herd. There is also a "5 Claude Code worktree tips from creator
of…" thread on r/ClaudeAI (`/r/ClaudeAI/comments/1rae05r/`) the owner flagged
as worth mining.

Related: 'Worktree e2e runs now require a worktree-scoped worker', which this
would subsume.

## Host `pkill` does not kill a process inside the php-fpm container


**Author:** Claude · **Type:** tooling · **Priority:** medium · **Status:** pending

`bin/worktrees/compose-exec.sh` ends in `exec docker compose exec … php-fpm
"$@"`, so a host-side `pkill -f <script>` matches only the **client** process.
The container has a separate PID namespace — on macOS, a separate VM entirely —
so the real process keeps running, orphaned and invisible to the host process
table, until it completes on its own.

This is how a runaway scratch script consumed 26+ CPU-minutes while its author
believed it had been killed, starving concurrent e2e runs the whole time. The
symptom is deceptive: `ps` on the host shows nothing, so the container looks
quiet when it is not.

Kill container work from inside the container:

```sh
docker compose exec php-fpm pkill -f <script>
```

And check for it the same way — `docker exec <project>-php-fpm-1 ps aux` — since
a host-side check will report a quiet container that is fully loaded.

**The agent harness's own `TaskStop` has the same blind spot.** Stopping a
background task that was launched through `compose-exec.sh` reports success and
kills the **host-side wrapper**, leaving the real process running inside the
container. This was observed with a `messenger:consume` consumer: `TaskStop`
succeeded, a host-side check showed a clean shell, and the consumer was still
holding the container. Anything that reports "the slot is free" on that basis is
wrong.

So the rule generalises past `pkill` to every stop mechanism: **a process
started inside the container can only be observed and stopped from inside it.**
Verify with `docker exec … ps aux` after any stop, whatever issued it.

## A signature change on a long-lived branch makes every merge a phpstan question


**Author:** Claude · **Type:** tooling · **Priority:** medium · **Status:** pending

When a branch changes a constructor or method signature and then lives long
enough to absorb several merges, **git cannot tell you what broke**. Each
incoming merge may add a call site using the old signature, in a file the branch
never touched — so there is no conflict, the merge is clean, and the result is a
runtime `ArgumentCountError`.

This happened **fourteen times** across four syncs of one branch on 2026-08-03,
after it made a logger a required constructor argument of `MarkdownRenderer`.
The last instance is the clearest: a construction inside
`DiffDocumentVersionsControllerTest.php`, a **brand-new file** arriving from a
sibling. It existed on only one side of the merge, so git had nothing to report
at all. Twelve of the fourteen were found only because `just ci` ran.

The rule worth internalising: after merging into a branch that changed a
signature, the question "did this merge break anything" is answered by
**phpstan, not by the absence of conflict markers**. A grep for the changed
symbol is a good first pass but is not sufficient — it only finds shapes you
thought to search for, and it goes stale the moment another merge lands.

This is the same family as the rename/rename case already documented in
`CLAUDE.md`'s merge protocol ("a conflict-free merge is not a correct merge"),
but with a different tell: there, a file moves and a reference goes stale; here,
a *new* file arrives already speaking the old contract. Both are invisible to
git and visible to static analysis.

Relevant when planning a wave: it argues for landing signature changes early
rather than letting them ride at the back of a queue, since every sibling merged
ahead of them multiplies the exposure.

## Set up ADRs and a stated list of architectural priorities


**Author:** Geoffrey · **Type:** docs · **Priority:** medium · **Status:** pending

Two artifacts, one purpose: make architectural judgement explicit and durable
instead of re-deriving it per decision, per session, per agent.

**Architecture Decision Records** — one short document per significant decision:
what was chosen, what was rejected, and why. Several decisions made on
2026-08-03 have their reasoning only in PR comments and code docblocks, where it
is discoverable by accident at best: adopting
`martin-georgiev/postgresql-for-doctrine` over four hand-rolled Doctrine
classes (with the `php: <8.6` bound as an accepted cost); keeping search
indexing synchronous; resolving a stale decision-form submission by refusing it
rather than resolving against the version the reviewer saw; and the sanitizer
moving to an explicit per-element allowlist. Each one is a question that will be
asked again.

**A stated ranking of architectural priorities** — performance versus
correctness versus simplicity versus shipping speed, ordered rather than merely
listed. Every one of them sounds good in isolation; the value is entirely in
knowing which yields when two collide.

That list is the durable fix for a problem recorded separately in 'The owner
sets the quality bar, not the agent'. Without it, an agent facing "this is
technically wrong but nobody would notice" has to either guess or ask every
time — and guessing has produced both over-engineering and misplaced
blocking. With it, most of those calls answer themselves and only genuine edge
cases need escalating.

Worth deciding when writing it: whether ADRs are required for a class of change
(new dependency, schema change, cross-module boundary) or written on judgement,
since a process nobody follows is worse than none.

## The owner sets the quality bar, not the agent — say so in the instructions


**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

Deciding what is good enough and what has to be exactly right is the owner's
call. Claude has been making it silently and then presenting the outcome as a
technical necessity, which removes the decision rather than informing it.

Observed on 2026-08-03, across a wave of nine branches:

- Findings were ranked "must-fix" and "should-fix" and agents were told to block
  on the former. That ranking is a priority judgement wearing a severity label.
- A branch's failing e2e run was declared to "outrank the merge queue".
- Mutation-checking every fix was imposed as a standard rather than offered as
  an option with a cost.
- A stale form submission was required to be **refused** rather than resolved
  against the version the reviewer saw — a product decision about what the user
  experiences, presented as correctness.

Some of these were probably the right calls. That is not the point: each was a
choice about how much rigour a given thing deserves, and each was made without
the person who gets to make it being asked.

What the instructions should ask for: name the severity and the cost of fixing,
recommend, and let the owner set the bar — especially where the fix is
expensive, where the defect is unreachable in practice, or where "leave it and
note it" is a legitimate answer. Reserve blocking language for things that are
genuinely unsafe to ship, and say plainly when something is a judgement call
rather than a requirement.

The tell to watch for: describing a preference as though it were a property of
the code. "This must refuse" and "I think refusing is better, here is the cost
of each" are different sentences, and only the second leaves the decision where
it belongs.

Related: 'Update the agent instructions to weigh trade-offs instead of defending
one option' — the same root, one level up. Inflating an argument defends a
chosen approach; setting the bar unasked decides whether the approach was even
needed.

## Update the agent instructions to weigh trade-offs instead of defending one option


**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

Claude tends to pick an approach and then argue for it, rather than laying out
the options with honest costs on each side and letting the reader decide. The
instructions should push toward the second behaviour explicitly.

The failure is not that the recommendation is usually wrong — it is that the
*reasoning is inflated to match the conclusion*. A minor downside of the
rejected option gets described in the register of a serious one, which makes the
argument unfalsifiable and hides how close the call actually was.

Concrete example from 2026-08-03, on whether document search indexing should be
synchronous. The measurement settled it easily: 1.07 ms for a typical document,
109 ms at the 1 MiB input ceiling. "One millisecond, so async adds machinery for
no gain" is the whole argument. What was written instead was that async would
introduce "a window where a freshly created document is not yet findable" and a
"real correctness cost" — for a delay of a second or two, in a document-review
tool, which nobody would notice. The conclusion was right and the case for it
was overstated, which is worse than a weaker conclusion honestly argued.

What the instructions should ask for:

1. State the options and what each actually costs, in proportionate language.
2. Give a recommendation and the confidence behind it, without padding the
   rejected options' downsides to justify it.
3. Say plainly when a call is close, or when it rests on taste rather than
   evidence — "either is fine, I lean X" is a legitimate answer.
4. Keep the alternatives genuinely available rather than mentioning them as a
   courtesy before dismissing them.

Related: the existing instruction not to capitulate under pressure. These pull
in opposite directions and the tension is the point — hold a position against
disagreement, but do not manufacture support for it.

## A better framework for planning and running multi-branch waves


**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

Running nine parallel branches on 2026-08-03 worked, but the coordination lived
in one session's head and in ad-hoc prose briefs. Everything below is a real
cost paid that day, not a hypothetical.

What the current approach does not hold:

1. **Queue and gate state.** Which branch is synced, gated, Codex-clean, or
   holding the exclusive e2e slot existed as prose in a task description. It was
   correct only because one session kept rewriting it.
2. **Cross-branch obligations.** "Whichever of these two merges second must wrap
   the contents panel in `{% if not diffMode %}`" was tracked as a note. Nobody's
   tests could catch it, and skipping it turned out to produce a hard 500 rather
   than a cosmetic flaw. A framework should make an obligation like that block
   the second merge automatically.
3. **Lessons between siblings.** Findings had to be relayed by hand — that
   `TaskStop` does not kill a container process, that counts and even natural
   keys survive a truncate-and-reseed so only surrogate keys discriminate, that
   `nullable: false` on a many-to-many join column is a silent no-op. Each
   reached later agents only because someone remembered to forward it.
4. **Merge ordering.** Every merge invalidates the gates of everything behind
   it, so the order determines how much re-work the wave costs. It was chosen by
   hand each time.

What would have helped most, in rough value order: a machine-readable per-branch
state (synced/gated/reviewed/blocked-on) rather than prose; explicit dependency
and conflict edges between branches, since the deletion paths and one template
were touched by four branches each; and a shared findings log that new agents
read on start instead of being told.

Worth weighing against building anything: much of the pain was a single shared
php-fpm pool, now fixed, and the rest may dissolve if agents move to their own
containers — see 'Give each agent its own container in the cloud instead of
sharing one dev stack'. Build the coordination layer only for what survives that.

## Shrink the e2e suite and push its assertions down to functional tests


**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

The e2e suite is 64 specs and cannot be parallelised (shared Mailpit), so it is
a serial gate every branch queues behind. Most of it is asserting things that do
not need a browser.

**The discriminating question for each spec: does this assertion depend on a
real browser?** If it asserts a status code, a redirect, a flash message, or the
presence of text in rendered HTML, `WebTestCase` does the same thing against the
same kernel and the same database in milliseconds.

By that test, the bulk of the suite is a candidate — signup, login, remember-me,
forgot-password, social-login, delete-account, data-export, paywall, waitlist,
trial-end. These are form-submit-redirect-flash flows whose assertions never
exercise the browser realism the layer provides.

What genuinely earns e2e is the review UX, and it is the minority: comment
anchoring through the Selection API, the CSS Custom Highlight rungs and their
priority resolution, keyboard auto-repeat on the strike shortcut, Turbo stream
targeting, and focus behaviour during debounced navigation. None of those can be
asserted without a browser, and each has produced a real defect.

**Mail is not a reason to keep e2e, contrary to the obvious assumption.**
`Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait` ships with
framework-bundle and **is currently used in zero tests here**.
`getMailerMessages()` returns the sent `Message` objects, so a functional test
can extract a verification or download link from the body and follow it with
`$client->request()` — the whole round trip, no Mailpit and no browser. Adopting
it is worth doing on its own merits even if no e2e spec is ever deleted, and it
removes the shared-Mailpit constraint from the specs that move.

**Do not size this work from the symptom that prompted it.** The complaint was
that e2e was slow and flaky; that was one php-fpm pool at its ceiling, and after
raising it the full suite went from ~8.5 minutes to ~3.6 with zero saturation
warnings. Cutting specs to fix that would be paying twice for the same problem.
The case for the cut is that fast unit and functional tests catch these defects
earlier and more precisely — not that the gate is slow.

Two things to protect in any reduction:

1. **Keep at least one real-browser path through each critical flow.** Something
   has to actually run the application in a browser, or a whole class of defect
   goes unobserved — the `nullable: false` deprecation flood on the
   many-to-many join columns surfaced only because a run drove the real app.
2. **Convert rather than delete.** A spec removed without its assertions
   reappearing at a lower layer is coverage lost, and the loss is invisible
   because the suite still passes.

## Reduce how much the test suite logs by default


**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

One `just ci` run writes roughly **4.6 MB** to `var/log/test.log`. That is noise
for the overwhelming majority of runs: nobody reads it, it grows without bound
across a working day, and it makes the file useless for spotting anything by
eye. `config/packages/monolog.yaml`'s `when@test` handler is where to change it.

**Do not simply silence it.** On 2026-08-03 that volume was the evidence that
settled a real question. Doctrine logs a deprecation on *every* mapping read
when `nullable: false` is set on a many-to-many join column — a no-op annotation
that becomes a hard error in Doctrine 4, and one that `just ci` cannot fail on.
Proving a branch was clean of it meant pointing at a 4.6 MB log written by a
suite that had read every mapping thousands of times and showing it contained
zero deprecation lines. A quiet log would have made that a much weaker argument,
and the same shape recurs whenever the question is "did this *not* happen".

So the goal is less volume without losing that capability. Options worth
weighing rather than a single obvious answer:

1. Keep deprecations and warnings, drop `info`/`debug` — the bulk is almost
   certainly SQL and request logging, not the lines anyone wants.
2. Route deprecations to their own file, so the useful signal stays greppable
   and cheap regardless of what the main handler does.
3. Make verbosity opt-in for a single run via an env var, so the default is
   quiet and a session investigating something can turn it back up.

Whichever is chosen, check the change against the case above: reinstate a
`nullable: false` on a many-to-many join column and confirm the deprecation
still appears. A logging change that passes its own tests while removing the
ability to answer "did this not happen" has made things worse.

## Document search stems every document as English


**Author:** Claude · **Type:** feature · **Priority:** medium · **Status:** pending

`App\Doctrine\FullTextSearch::CONFIGURATION` is `english`, and both the stored
vector (`documents.search_vector`, written by
`App\Module\Review\Service\DocumentSearchIndexer`) and the query
(`TSMATCH` / `TS_RANK`, from martin-georgiev/postgresql-for-doctrine) use it.
That is what makes "reviewing" match "review". It is also wrong for every other
language: a French document is stemmed with English rules, so its own words
often fail to match themselves.

Accepted knowingly, because there is nowhere to read a better answer from — a
`Document` has no locale, and neither does the project or the submitting agent.
Closing this means deciding where a language comes from first. The options are a
per-project setting, a per-document one set at `document_create` time, or
detection at ingest; only then does the column become
`to_tsvector(<per-row regconfig>, …)`.

Whichever is chosen, changing the configuration requires rebuilding every stored
vector in the same change — a vector stemmed as English and a query parsed as
French do not meet. The migration that introduced the column
(`Version20260803015620`) has the backfill statement to copy.

## Site-review bridge CLI (`cli/`): polish before shipping




**Author:** Claude · **Type:** feature · **Priority:** medium · **Status:** pending

The Go bridge is functional (`loupe login` + `loupe bridge run
--site <name>`, with an interactive picker when the flag is omitted). Remaining
work before it's a turnkey distributable:

- **Reconnect-after-401 is still uncovered.** `cli/internal/transport/mercure_test.go`
  (`TestSubscribeResumesFromLastEventID`) now covers the reconnect path in
  general — a fake hub that drops the connection after one event, and asserts
  the next connection sends `Last-Event-ID`. What it does not cover is the
  specific 401-then-fresh-JWT scenario: `transport.Subscribe` mints a fresh
  subscriber JWT per attempt (`TokenFunc`) and refreshes by resolved site id,
  but no test exercises a hub that 401s and then serves an event on the
  reconnect. That was the gap that let the expiring-JWT regression ship in the
  first place.
- **No-echo token prompt.** `login` reads the token from stdin with the terminal
  still echoing. Use `golang.org/x/term` (or equivalent) to read without echo.
- **OS keychain storage.** The token is stored in `~/.config/loupe/config.json`
  (mode 0600). Move it to the OS keychain (e.g. `go-keyring`) with the file as a fallback.
- **CI + release.** Wire `just cli-test` into the gate, and add goreleaser for a
  multi-platform release matrix (current `just cli-build` only cross-compiles one target).

## Site-review comments have no agent-reply data model




**Author:** Claude · **Type:** feature · **Priority:** medium · **Status:** pending

The approved design for the site-review screen shows agent ("Claude") replies indented under
each comment with an "addressed" tag. `SiteReviewComment` has no reply/response
field — the MCP `site_review_mark_comment_addressed` tool only flips the status to `Addressed`, it
stores no reply text. So the site-review page renders no agent-reply block (there
is nothing to show). When agent replies become a real requirement, add a reply/
response field (or a related entity) to `SiteReviewComment`, have the addressing
tool persist the reply body, and render it in
`templates/Module/SiteReview/show_site_review.html.twig` (a placeholder comment
marks where it goes).

## Unbound legacy MCP tokens look like a connection failure to agents




**Author:** Claude · **Type:** feature · **Priority:** medium · **Status:** pending

`mcp`-scoped tokens minted before the Project entity existed (pre-2026-07-03)
authenticate fine but resolve no project, so every tool call fails with "MCP
token is not bound to a project" — which agents surface as "can't connect".
A 2026-07-10 session debugged this; the three affected local tokens were fixed
by creating projects and binding them directly in the dev DB. Product follow-ups
to consider: reject unbound `mcp`-scope tokens at authentication time (clear 401
instead of per-call errors), and/or purge orphan unbound tokens (the dev DB has
~500 unbound e2e-harness tokens accumulating — see also whether e2e should clean
up after itself).

## Gamache rule: catch skills that document tooling which no longer exists




**Author:** Claude · **Type:** tooling · **Priority:** medium · **Status:** pending

`.claude/skills/**/SKILL.md` cites `just` recipes, repo-relative paths and shell
helper functions, and nothing verifies those still resolve. When the
`wt-tailwind` recipe was renamed to `worktree-tailwind` (2026-07-25), two
references inside `project-worktrees` were left dangling and were only caught by
grepping by hand. The same hand-check was needed when the skill was written
(8 recipes, 3 `slug.sh` helpers, `compose-exec.sh`) — that is exactly the work
worth automating, because it stops being done the moment someone is in a hurry.

Proposal — `SkillReferencesCheck` in the `src/Check/` layer of
`ubermuda/gamache` (textual analysis over markdown, so it cannot be a PHPStan
rule: that layer only sees files in PHPStan's `paths:`). It scans every
`SKILL.md` and asserts:

- `` `just <recipe>` `` — the recipe exists in the justfile
- repo-relative paths in backticks (`bin/worktrees/slug.sh`,
  `compose.worktree.yaml`) — the file exists
- shell function names named alongside a `.sh` path (`worktree_slug`,
  `worktree_slug_index`) — the function is defined in that file

Should **fail**, not be advisory. `TranslationCheck` is advisory because it
scores heuristics and guesses wrong; this one is objective — a recipe either
exists or it does not — so it belongs with `NoTodosCheck`.

Main false-positive risk is skills naming non-project commands
(`git worktree remove`, `docker compose up`). Matching only `just <x>` plus
repo-relative paths avoids most of it; add a constructor-injected ignore list
for the rest, following the `TranslationCheck(ignoredCallSites: [...])`
precedent in `gamache.php`.

Two PRs: the rule in `github.com/ubermuda/gamache`, then one line wiring it in
this repo's `gamache.php` (rule classes must not be added directly here).

Generalises past worktrees — every `project-*` skill cites recipes and paths,
and all of them rot the same way.

## ProjectDeleter misreports a stale entity when looped without clearing




**Author:** Claude · **Type:** bug · **Priority:** medium · **Status:** pending

Found while building account deletion (2026-07-25): calling `ProjectDeleter::delete()` twice for
two different projects in the same EntityManager session throws
`ORMInvalidArgumentException: A new entity was found through the relationship`
on the second call, naming the `project` association of whichever entity was
loaded stale. Cause: `DeleteSiteReviewDataOnProjectDeleting` removes
`SiteReviewEvent`/`SiteReviewComment` rows via bulk DQL, which bypasses the
identity map, so a first project's now-stale object of one of those classes
survives into the second call's `flush()`. Doctrine's changeset computation
then finds that stale object's `project` association pointing at an entity
that was already detached after the first project's successful
removal+flush, and misreports it as a new, non-cascaded entity. (The bug was
originally reproduced against the `SiteReview` entity itself; that entity has
since been deleted (PR #84) — but the mechanism is unchanged for the
bulk-deleted entities that remain.)

`src/Module/Project/Service/ProjectAccountPurger.php::purge()` works around
this at the call site (fetch project ids up front, then `find()` + `delete()`
+ `em->clear()` per iteration — never holding a stale entity across two
`ProjectDeleter::delete()` calls). Every
other caller today only ever deletes one project per request, so this has not
surfaced before. If a future caller loops `ProjectDeleter::delete()`, it needs
the same workaround unless `ProjectDeleter` is fixed at the source (e.g.
`$this->em->clear()` at the end of its own transaction) — worth doing there
instead, once a second real call site exists, to stop every caller from having
to know this.

## Domain boundaries sweep — and the arkitect gate that has never rejected anything


**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

Owner decision (2026-07-25): once the feature wave and the trial-end lifecycle work land,
do a dedicated domain-boundaries sweep across `src/Module/` — check module-to-module
dependencies, tighten or add boundary rules where modules have grown entangled, and move
any misplaced code to the module that should own it. Treat it as its own branch, not a
rider on feature work.

**The sweep has no working gate, and writing one is part of it.** `phparkitect.php` at the
project root contains no rules — only the commented-out example from the package's own
documentation, wrapped around a `ClassSet` assigned to an unused variable. `just arkitect`
runs on every commit as part of `just ci`, passes every time, and has never checked a
single thing. A gate that reports success for doing nothing is worse than no gate: it
occupies the slot where architecture enforcement is supposed to be, so nobody notices the
enforcement is absent. This is the same class of failure as the php-cs-fixer finder that
matched zero files and let a formatting bug through a green pipeline — fixed since by
switching `.php-cs-fixer.dist.php` to explicit excludes plus a throw when the finder
matches nothing. The arkitect equivalent has no such guard.

Known cycles to break, confirmed in the code: Project↔Review and Project↔SiteReview
(`ListProjectsController.php` and `CreateProjectController.php` import
`DocumentRepository`, `SiteReviewCommentRepository`, `SiteReviewEventRepository`),
Account↔Project (`HomeController.php`, `DeleteAccountHandler.php`), and Account↔Billing
(`DeleteAccountHandler.php` imports `BillingProfileRepository` +
`StripeGatewayInterface`). The duplicated project-list count block in the two Project
controllers is the natural first extraction seam — one provider service fixes the reverse
edges and the 3-counts-per-project N+1 together.

Two pieces of work, in order:

1. Write real rules. The obvious first candidates are the module boundaries under
   `src/Module/` (a module must not depend on another module's internals) and the
   domain/infrastructure direction: a dependency rule catches infrastructure leaking
   *into* the domain, which is the half that is mechanically checkable — it says nothing
   about domain logic leaking *out* into an adapter, since adapters may depend on
   everything.
2. **Prove each rule red before green.** Point it at a real violation, confirm it fails,
   fix the violation, confirm it passes. A rule that has never been seen rejecting
   anything is how this entry came to exist in the first place.

Candidate to fold in while here: "Deduplicate the waitlist idioms (convert +
invite-validation)".

## Review UI: per-section approval




**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner request (2026-07-25): alongside the whole-document verdict, let a
reviewer approve individual sections. During the trial-end sweep spec review,
each revision orphaned most open comments and there was no way to mark "these
sections are settled, only re-review the delta" — per-section approval state
(persisting across revisions when a section's content is unchanged) would make
multi-round spec reviews much cheaper. Section identity comes from headings, so
`App\Module\Review\Service\HeadingExtractor` is the existing source of it; also
interacts with comment re-anchoring.

## Make messenger synchronous under Playwright so e2e needs no consumer

**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

Owner decision (2026-08-04): e2e should not need a messenger consumer at all.
This supersedes the earlier "wire it or delete it" question about
`PlaywrightSyncEmailMiddleware` — the answer is wire it, and wider than mail.

Until it lands the consumer requirement is at least no longer silent: `just e2e`
refuses to start unless a consumer is live, and `just e2e-worker` recycles below
PHP's own memory limit and relaunches itself, so a worker cannot disappear
mid-session. This entry would delete that machinery rather than merely stop it
hurting.

An attempt on 2026-08-04 got most of the way and is worth reading before the
next one starts, because the remaining gap is specific rather than general.

What worked: a bus middleware that stamps `TransportNamesStamp(['sync'])` on any
envelope dispatched during a request carrying `X-Playwright`, with the `sync`
transport uncommented in `config/packages/messenger.yaml`. Registered under
`framework.messenger.buses.messenger.bus.default.middleware`. With no consumer
running at all, the whole authentication-shaped block passes — signup, login,
email verification, forgot-password, waitlist, the first-run wizard — which is
the ~19-spec failure mode that made a forgotten worker so expensive.

What did not: the data-export chain. `GenerateDataExportMessage` is handled
inline, but the email `ProcessDataExportHandler` sends from inside that handler
still lands on `async` and sits there. One `SendEmailMessage` row remains queued
after the spec runs. The likely cause is that the nested dispatch happens where
`RequestStack::getCurrentRequest()` no longer returns the request, so the
middleware's guard skips it — worth confirming before designing around it, since
if that is right the fix is to capture the flag once per request rather than
re-read the stack per dispatch.

Two traps found while testing this, both of which cost a full suite run:

- Verifying the middleware is wired by grepping the compiled container gives a
  false negative against a stale `var/cache/dev`. It showed only in
  `removed-ids.php` until the cache was rebuilt, which reads exactly like a
  config that never took effect.
- `just e2e-up` migrates `app_e2e` but never rolls it back, so a database
  migrated by one branch is ahead of another branch's code and the suite fails
  with SQL errors that look like application bugs. Drop the database and re-run
  `just e2e-up` when switching between branches whose schemas differ.

## Fuller billing section in account settings (manage sub in-app)




**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner note (2026-07-25): the account settings page needs a proper billing
section — cancel a subscription, and start a subscription mid-trial. Partial
today (the `BillingSummary` Twig component on /account): shows trial days left /
active / payment-problem status and links to the subscribe page ("Subscribe")
or Stripe's customer portal ("Manage subscription" — cancel lives there).
The gap vs the note: cancellation is portal-only (no in-app cancel button),
and the mid-trial subscribe path exists but may deserve more prominence
(e.g. explicit "X days left — subscribe now" CTA). When picked up: decide
in-app cancel (Stripe API cancel_at_period_end via StripeGatewayInterface +
confirm modal) vs keeping the portal as the single management surface, and
whether the section should show renewal date/price (data already synced on
BillingProfile).

## Agent-authored test scenarios delivered through the site-review widget


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner note (2026-07-27): the agent should be able to send the human a list of
scenarios to test. They appear in the site-review widget, and the human walks
through them marking each one resolved or not.

Scenarios probably need to be linked back to the comments that prompted them —
the agent fixes a comment, then hands back "here is what to check". That link
is what turns the widget from a capture tool into a round-trip: the human sees
which of their original comments a given scenario is meant to prove.

A scenario the human marks as not resolved needs somewhere to put the why, so
a comment or bug can be attached to the scenario itself (owner note,
2026-07-27) — the same capture the widget already does for elements, hung off
a scenario instead of a selector. That attached comment is the natural return
path to the agent.

Open questions for the design phase: whether a scenario is its own entity or a
typed variant of `SiteReviewComment`; whether the link is one comment per
scenario or many-to-many; and how scenarios reach the widget, since the widget
currently only ever *sends* comments and pulls drafts. Related:
'Promote a site-review scenario into an e2e test' (what a passing scenario is
worth keeping as) and 'Site-review comments have no agent-reply data model' (the
agent has nowhere to put reply text today, which is the same missing
return path) and 'Capture scenarios from the widget, not just anchored
comments' (the other direction of the same object).

## Capture scenarios from the widget, not just anchored comments


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner note (2026-07-27): the widget should be able to capture *scenarios*, not
only comments anchored to a single element. Today every capture path in
`public/site-review/widget.js` produces one comment tied to one selector (or a
general page note) — there is no way to record "do this, then this, then this,
and here is what should happen".

This is the human→agent direction of the same object described in
'Agent-authored test scenarios delivered through the site-review widget'; the
two should share one data model rather than growing separately. Worth deciding
early whether a scenario is a multi-step recording (steps captured as the human
clicks through) or simply free text with several anchors attached, since that
choice drives most of the widget UI work.

## Promote a site-review scenario into an e2e test


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner note (2026-07-27): from a scenario in the widget, the human should be
able to task the agent with turning it into an e2e test. A scenario is already
a described walkthrough with an expected outcome, which is most of a Playwright
spec — this is what stops manually-verified behaviour from silently regressing
once the human stops re-walking it.

This is a second verb on a scenario alongside attaching a comment or bug (see
'Agent-authored test scenarios delivered through the site-review widget'). It
needs an outbound task channel the app does not have today: site-review work
currently flows agent→app by the agent *polling* `site_review_get` over MCP,
so decide whether promotion enqueues something the agent picks up on its next
poll or requires a real push. The generated spec lands in `e2e/tests/` and must
follow the `project-e2e` conventions; worth deciding whether the agent writes
it straight to a branch or hands back a diff for review.

## Attach a screenshot to a site-review comment


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner note (2026-07-27): a reviewer should be able to attach a screenshot to a
comment. The note came with the assumption that this needs a Chrome extension
because embedded JS cannot screenshot the page — that assumption is only half
right, and the difference decides the shape of the feature, so check it before
committing to an extension:

- `navigator.mediaDevices.getDisplayMedia({ preferCurrentTab: true })` captures
  the current tab from an ordinary page, no extension. It needs transient user
  activation and shows a source picker on every capture, and it returns a video
  stream, so the widget must grab a frame to a canvas and `toBlob` it.
- `getViewportMedia` (W3C Viewport Capture) captures the top-level viewport
  behind a permission prompt rather than a picker, which is the UX we actually
  want. Verify how widely it has shipped before relying on it.
- A Chrome extension (`chrome.tabs.captureVisibleTab`) gives a prompt-free,
  pixel-accurate capture — a UX optimisation over the above, not a prerequisite,
  and it costs every reviewer an install. That trade is the real decision.
- DOM-rasterising libraries (html2canvas and friends) need no permission but
  re-render rather than capture, so they drift from the real paint on
  cross-origin images, iframes and effects like backdrop-filter. For a tool
  whose whole point is "here is what I saw", that drift is disqualifying.

Whichever path: the widget must hide its own overlay (pins, panel, scrim) before
capturing and restore it after, or every screenshot contains the review UI. Also
needs a decision on where the image is stored and how it is served, since
`SiteReviewComment` today carries only text, a selector and a URL. Related:
'Drawing on the page in the site-review widget' — the two are usually one
gesture, and a stroke drawn on a frozen screenshot is a very different feature
from one drawn on live DOM.

## Drawing on the page in the site-review widget


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner note (2026-07-27): let the reviewer draw on the page — circle the thing
that is wrong, arrow at it — rather than only clicking one element.

The decision that shapes everything else is what the strokes are drawn *on*:

- **Live DOM.** Strokes are vector data in the widget's overlay, anchored the
  way pins already are, and re-render on the real page later. Survives a redeploy
  in the sense that the page stays current, but reflow, responsive breakpoints
  and any content change move the page out from under the drawing.
- **A frozen screenshot.** Capture first, then annotate the image (see 'Attach a
  screenshot to a site-review comment'). Always shows what the reviewer saw, and
  sidesteps anchoring entirely, but the annotation is dead pixels the agent
  cannot map back to an element.

Drawing also gives the widget a capture mode that is neither "pick one element"
nor "general page note", so it needs its own entry in the composer alongside
those two, and a selector-less comment shape. The overlay already owns a
fixed-position layer above the page, which is where the canvas would live.

## Anchor a site-review comment to several elements, not just one


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner note (2026-07-27): let the reviewer pick more than one element for a
single comment, so the comment can talk about the elements *in relation* to
each other ("these two should be side by side", "this belongs above that").

This is the cheap version of what 'Product idea (long horizon): drag DOM
elements in the widget to try layouts' is reaching for. Dragging struggles
because a moved node does not tell the agent which rule to edit or what the
intent was; a multi-anchor comment states the relationship in words and lets
the agent decide the CSS. It is worth doing before, and possibly instead of,
the drag idea.

Cost is concentrated in the data model, which is single-anchor throughout:
`SiteReviewComment` has scalar `selector`, `text` and `url` columns, so this
needs either a related anchor entity or a JSON collection, plus a migration.
Everything downstream reads those scalars and would follow: the widget's pin
reconciliation is one pin per comment keyed by index (`renderPins` in
`public/site-review/widget.js`), the site-review page renders one selector
disclosure per comment, and the MCP `site_review_get` payload exposes
`selector`/`text` per comment — that last one is an agent-facing contract
change, so version it deliberately.

Decide early what happens when only some anchors still resolve: today a pin
whose element is gone is simply dropped, but a partially-orphaned relational
comment ("these two…" with one element left) is misleading rather than merely
incomplete. Related: 'Drawing on the page in the site-review widget' — drawing
and multi-anchor are two ways to express the same relational feedback, and a
stroke connecting two elements is arguably just a multi-anchor comment with a
picture attached.

## Public feedback widget (a public pendant to the site-review widget)


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner note (2026-07-27): the site-review widget is internal-only — it is for the
project's own people reviewing their own site. We also want a widget a project
can expose to its actual public, collecting feedback from anonymous visitors.
That feedback goes through **its own pipeline and is never routed to the LLM**.

**Make "never routed to the LLM" structural, not a filter.** The obvious
shortcut is to reuse `SiteReviewComment` with an `isPublic` flag and exclude it
in the agent-facing queries — do not. `SiteReviewGetTool` reads
`findPendingForProject`, and any future query, export or MCP tool that forgets
the flag silently leaks public feedback into an agent's context, which is
exactly the failure the owner is ruling out. Separate storage (its own entity,
plausibly its own module) makes the leak impossible to write rather than
merely currently-absent.

The trust model inverts, so little of the current widget's API carries over:

- Today the widget only renders for a logged-in user
  (`{% if app.user and site_review_widget_token %}` in `base.html.twig`) and its
  token is minted per project with the `ROLE_API_SITE_REVIEW` scope that
  `config/packages/security.yaml` names above the deny-by-default `^/api` rule.
  A public widget's token ships in the page source of a public site to anyone,
  so it needs its own scope that can **create only** — never list, patch or
  delete. The existing widget has read and mutate endpoints (draft listing,
  update, delete) that must not be reachable with a public token.
- There is no draft/send cycle. The current widget batches drafts locally and
  submits a review; an anonymous visitor submits one piece of feedback and
  leaves. That removes most of the widget's state machine.
- Abuse controls become load-bearing rather than incidental.
  `RateLimitSiteReviewWrites` keys on the authenticated user and falls back to
  client IP; for anonymous traffic only the fallback applies, so it needs
  per-project limits, body size caps and a spam story before this is exposed.
- CORS currently answers for a project's configured domain
  (`SiteReviewCorsSubscriber`); the same mechanism should work, but the origin
  allow-list becomes a security boundary rather than a convenience.

Also decide before building: where public feedback surfaces in the app (its own
inbox screen, not the site-review list), whether it notifies by email, and its
retention story — the account-deletion and data-export purgers exist for
`User`-owned data, and anonymous submissions with no owning user fit none of
those paths while still potentially carrying personal data.

## The full e2e suite wipes a worktree's dev database


**Author:** Claude · **Type:** docs · **Priority:** medium · **Status:** pending

`just e2e` includes an `install-reset` Playwright project
(`e2e/tests/install/install.spec.ts`) that resets the app to a fresh-install
state to exercise the first-install wizard. Run against a worktree — which is
the correct gate target — it destroys that worktree's **dev** data: the seeded
`dev@loupe.test` user, its project, and anything created for manual review.
Observed 2026-07-27: after a green suite the dev database held one user
(`e2e-install-admin@example.com`) and zero projects, so the login handed to a
human for manual review simply did not exist.

`just worktree-up` is idempotent and re-seeds the user and its project, so the
recovery is cheap once you know. What is missing is the signal: nothing warns
that the suite is destructive to dev data, and the failure surfaces much later
as "the login does not work". Options: have `just e2e` print a warning when
pointed at a worktree, re-seed automatically on completion, or scope the
install-reset project behind an opt-in flag so the default run is
non-destructive. Note it also leaves `e2e-install-admin@example.com` behind as
a stray ROLE_ADMIN account.

Related: 'Worktree e2e runs now require a worktree-scoped worker' — same
setup surface, and both are things a person only learns by losing time to them.

## Let the agent close the loop when a human approves the work


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner note (2026-07-28): when a PR that exists *because of* a site review gets
approved or merged, the agent should be allowed to mark those site-review
comments **resolved** — not merely addressed. Likewise, when a document review
is approved verbally, the agent should be able to mark that document approved.

**The PR-driven half is parked (owner decision, 2026-08-02).** Mapping what it
would take found that none of its prerequisites exist, and each is a piece of
work in its own right rather than a detail of this one:

1. **Nothing records which site-review comments a PR came from.** No entity,
   column or migration anywhere carries a PR, branch, commit or issue
   reference. The nearest thing in the whole model is `SiteReviewComment::$url`,
   which is the page a comment was anchored on — a browsing location, not a work
   item. Same missing link as in 'Agent-authored test scenarios delivered
   through the site-review widget'.
2. **There is no GitHub credential to ask with.** GitHub integration is OAuth
   login only: `league/oauth2-github` for sign-in, one read-only call to
   `api.github.com/user/emails` during login, and the access token is passed
   transiently to `SocialProfileFactory` and **discarded**. `ConnectedAccount`
   persists no token. So the app cannot query a PR's review state on anyone's
   behalf.
3. **There is no inbound webhook path from GitHub.** The only third-party
   receiver is `/webhooks/stripe`. It is the right template if this is ever
   built — signature-verified over raw request bytes, outside `/api` on purpose
   — but it is billing-specific today.
4. **Addressed comments become invisible to the agent.** `site_review_get`
   calls `findPendingForProject`, which filters on `status = Pending`. The
   moment the agent marks a comment addressed, its id is returned by no MCP
   tool, so "resolve the comments this PR closed" cannot enumerate them. Tracked
   separately in 'Addressed site-review comments disappear from the MCP'.

What remains open here is the **document-approval** half — letting an agent
record that a human approved a document. It carries the same evidence problem
and is now sharper than the original note assumed, for two reasons found while
mapping it.

`Review` is append-only and requires a non-nullable `reviewer: User`. An MCP
request already holds that `User`, because `ApiTokenAuthenticator` authenticates
as the project owner. So a tool writing a `Review` would produce a row
**indistinguishable from one the owner clicked** — their name on an approval
they never gave — and there is no audit trail to separate the two afterwards
(see 'No audit trail distinguishes agent-written state from human action').

The second reason cuts the other way and is worth recording, because the
original note had it backwards: document approval does **not** depend on
'Replace explicit document/site-review state with computed state' removing a
column it needs. `Document::$status` is a denormalised copy of the latest
`Review`'s verdict — `SubmitReviewHandler` maps one to the other 1:1 — so once
status is computed, "mark this document approved" still has somewhere to write.
It writes a `Review`, which is exactly the row whose provenance is the problem.

The precedent worth copying if this is picked up: `GithubPrimaryEmailFetcher` →
`SocialProfileFactory` → `ResolveSocialLoginHandler` is the one place in the
codebase where a third party's word permits a local state change, and it works
because **the app makes the call itself**, with a credential whose control the
caller just proved, and fails closed on any ambiguity. "The agent was told in
chat" has neither property.

## Documents can be tagged and linked, but not grouped into a series


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Two of the three organizing primitives now exist. Project-scoped tags ship with
a `Tag` entity, a filter bar on the documents list, and `document_create` /
`document_set_tags` over MCP; document-to-document references ship too, render
on both sides, and are settable through `document_create`, `document_revise`
and `document_set_references`.

What neither expresses is **membership of an ordered set**. Submitting a blog
series on 2026-08-01 put seventeen related documents into one project — eleven
posts, six companion threads — and the only way to say "thread 5 belongs to
post 5, and both are the fifth item of one series" was to bake it into the
titles by hand ("Post 5 — …", "Thread 5 — …"). That is a naming convention
pretending to be a data model: nothing enforces it, nothing sorts on it, and it
breaks the moment a title is wrong. A tag can say seventeen documents are
related; it cannot say they are numbered one to eleven, nor that six of them
are companions to the other eleven.

Still open, then: whether grouping is a hierarchical primitive of its own
(categories, folders, a `Series` entity with an ordinal), or whether ordering
metadata on the existing reference edge is enough. Whichever it is, it must be
settable from the MCP at `document_create` time — the agent submitting the
batch is the one that knows how the documents relate, and asking a human to
order seventeen documents afterwards means it does not happen.

## Edit a document in the app, not only through an agent


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

A document can only be written by an agent over MCP. `debug:router` has no
create, edit or delete route for documents — `app_project_documents` lists them
and `app_document_review` shows one, and that is the whole surface. So fixing a
typo, correcting a stale sentence, or writing a document by hand all require
going back to an agent and asking it to revise, which is absurd for a one-word
change and impossible if no agent session is open.

The domain work is already done, which is what makes this worth scheduling: the
command + handler pairs exist as `Module/Review/Command/CreateDocumentCommand`
+ `CreateDocumentHandler` and `ReviseDocumentCommand` + `ReviseDocumentHandler`.
`Module/Review/Mcp/DocumentCreateTool` and `DocumentReviseTool` are thin
wrappers over them. What is missing is only a form, a controller and a template.

**Go through `ReviseDocumentHandler`, do not write a second revision path.**
Revising is not a text overwrite: it creates a new `DocumentVersion` and
re-anchors open comments onto it via `Module/Review/Service/AnchorService`,
flagging as orphaned any whose quoted text no longer appears. An editor that
updates the markdown directly would silently strand every comment on the
document. The MCP path already returns carried/orphaned counts, and the UI
should surface the same thing — ideally warning before saving when an edit is
about to orphan comments, since a human editing in-place has no equivalent of
the agent's deliberate revise step.

Two decisions to make when this is picked up. Whether an in-app edit creates a
version on every save or only on an explicit "publish" — per-keystroke
versioning would make the version list useless, so some form of draft state is
probably needed. And who may edit: today authorship is implicit in whoever's
agent token created the document, and there is no edit permission modelled.

Related: "Review UI: version diff view", which becomes considerably more useful
once humans are producing versions too.

## Review comments should be able to express an edit, not just describe one


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Every review comment is untyped prose. `Module/Review/Entity/Comment` carries a
free-text `body`, an `Anchor`, a thread `status`, an `orphaned` flag and an
optional `parent` for replies — and nothing else. So "delete this paragraph" and "reword
this as X" have to be written out longhand and then applied by hand by whoever
owns the document, which is slow for the reviewer and lossy for the author.

Two tools worth having: a **strike** that means "remove this passage" with no
prose required, and a **suggest rewording** that carries replacement text.

**These are two tools in the interface and one mechanism underneath, and the
two statements must not be confused.**

In the UI, striking is its own action: select a passage, strike it, done. No
form, no prose, no empty field to skip past — plausibly a single keystroke, the
way the site-review widget's `t` shortcut works. Removing text is likely the
commonest edit a reviewer makes, so it must be the cheapest gesture on offer.
Suggesting a rewording is the one that opens an input. The two should also
*render* differently: a strike as struck-through text, a rewording as
before/after.

Underneath, a strike is a suggestion whose replacement text is empty, so one
anchored-comment-plus-optional-replacement model serves both and the accept path
is written once. `body` then holds the rationale rather than the replacement.

That equivalence is an implementation note and nothing more. It must not surface
as "create a suggestion, leave the replacement blank" — that turns the most
common action into the most tedious one, which is exactly backwards.

**This is where it meets in-app editing** (see "Edit a document in the app, not
only through an agent"). A suggestion that cannot be accepted is just a comment
with better formatting; the value is in applying it. Accepting one has to go
through `ReviseDocumentHandler`, which means a new `DocumentVersion` and a
re-anchoring pass. Consequences worth knowing up front:

- Applying a suggestion changes the exact text its own comment is anchored to,
  so the comment orphans itself on accept. The accept flow must mark it resolved
  deliberately rather than letting `AnchorService` report it as orphaned, or
  every accepted suggestion looks like a failure.
- Accepting several suggestions should produce **one** new version, not one per
  suggestion. Per-suggestion versions would make the version list unreadable and
  would re-anchor repeatedly for no reason.
- Overlapping suggestions on the same passage need a rule. Simplest is
  first-accepted wins and the rest orphan, but that should be a decision rather
  than an accident.

Also unresolved: how this interacts with `Review` and `Verdict` — whether a
document can be approved while unaccepted suggestions are outstanding, or
whether those must be accepted or rejected first.

The MCP side matters too, since agents are the main authors of documents here: a
human's accepted rewording should be visible to the agent that wrote the
document, and an agent should plausibly be able to *make* suggestions on a human
edit. Neither needs building first, but the comment model should not make them
awkward later.

## Gamache rule: an MCP tool class name must match its tool name


**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

The convention is that a class carrying `#[McpTool(name: 'document_create')]`
is named `DocumentCreateTool` — the tool name in PascalCase, plus a `Tool`
suffix. It was caught in review on the `feat/mcp-scoped-authz` pull request and
enforced by hand across all seven tools; nothing enforces it now, so the next
tool added can violate it silently.

No rule for it exists in any of the five gamache layers — checked `src/Check/`,
`src/PHPStan/`, `src/Rector/`, `src/PhpCsFixer/` and `src/TwigCsFixer/` in the
`ubermuda/gamache` package, none of which mentions MCP at all. The PHPStan
layer is where it belongs: it already holds several name-agreement rules that
compare a class name against something else (`ControllerTemplateNameRule`,
`DtoRequestSuffixRule`, `MessengerHandlerNamespaceRule`), and reading an
attribute argument off a class node is the same shape as those.

Gamache is an external package, so this is a pull request on
https://github.com/ubermuda/gamache, not a class added under `src/`. Consider
covering the paired test class in the same rule (`DocumentCreateToolTest`),
since that half drifts just as easily.

## Two patterns now exist for fieldless POST actions — converge them


**Author:** Claude · **Type:** tooling · **Priority:** medium · **Status:** pending

Archiving and unarchiving a document were converted (2026-08-02, review comment
on pull request #118) from a hand-written `<form>` plus
`#[CsrfToken('document-archive')]` to a real Symfony form —
`Module/Review/Form/ArchiveDocumentFormType`, built per row by
`ReviewExtension::documentArchiveForm()` and rebuilt by name in the controller,
with the form component issuing and checking the token. The
`document-archive` entry came out of `config/packages/csrf.yaml` with it.

Four controllers still use the pattern that replaced:

- `Module/Review/Controller/DeleteCommentController` and `ResolveCommentController`
  (`comment-action`), submitted from `templates/Module/Review/components/CommentThread.html.twig`
- `Module/SiteReview/Controller/ReopenSiteReviewCommentController` and
  `ResolveSiteReviewCommentController` (`site-review-comment-action`), submitted
  from `templates/Module/SiteReview/show_site_review.html.twig`

They were left alone deliberately — they were outside the branch that made the
change — so the divergence is known rather than accidental. But it is still two
ways to write one shape of action in the same module, and the next fieldless
POST has no obvious precedent to copy.

**Decision needed** — which way to converge:

1. Convert the four to Symfony forms, matching archive (recommended: the form
   component already owns CSRF, so `stateless_token_ids` shrinks toward holding
   only the genuinely form-less endpoints, and there is now a worked example
   including the per-row `createNamed` naming).
2. Keep `#[CsrfToken]` for fieldless actions and revert archive to it, treating
   "a form with no fields" as ceremony not worth the indirection.

Whichever is chosen, `SubmitReviewController` (`submit-review`) is **not** part
of this: it submits a verdict value, so it is not the fieldless shape.

One thing the conversion surfaced that the attribute form hides: a per-row
fieldless form still needs a unique name (`createNamed`), or every row renders
the same DOM id on its hidden token input.

## MCP tools flatten field-level errors into one string for agents


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Error reporting to agents is thinner than what the app already knows. When a
handler rejects an MCP call it throws `DomainErrors`, which is a map of field
name to reason — `['title' => 'review.rename.error.too_long']`.
`Module/Review/Mcp/ToolCallErrorMessages` then collapses that map to a single
English sentence and throws it as a `ToolCallException`, so the agent learns
that something was wrong but not *which argument* was wrong. With one failing
field that is merely lossy; with two it is actively unhelpful, because the
agent has to guess which of its arguments to change. Tools that validate at
their own boundary (the markdown size cap) hand-roll their message separately,
so there is no single shape for "your call was rejected, here is why".

The idea worth trying: Symfony's form and validation component already models
exactly this — a tree of fields, each with its own violations — and the app
already relies on it for every HTML endpoint. An MCP tool call is a set of
named arguments, which is the same shape as a submitted form. If a tool's
arguments were bound and validated the way a form is, the tool could return
structured per-argument errors instead of a sentence, and the rules would live
in one place instead of being written twice.

Two facts that constrain any solution, both deliberate today. MCP tools do
**not** use forms: they are plain invokable classes whose arguments come from
the JSON-RPC payload, and their argument types and docblocks are what the MCP
SDK publishes as the tool schema — so anything that binds them must not break
that schema generation. And `#[IsGranted]` does not fire on them either;
authorization goes through `ReviewSubjectResolver` plus `McpBoundProjectVoter`
by explicit call. So this is not "make MCP tools controllers" — it is finding
which part of the validation machinery can be reused without the HTTP
scaffolding that surrounds it.

Worth checking what the MCP specification says about structured error payloads
before designing anything, since the wire format may already have a place to
put field-level detail.

## A symfony/yaml bump can silently move every anchor in a document


**Author:** Claude · **Type:** bug · **Priority:** medium · **Status:** pending

`MarkdownRenderer` renders a document's opening `---` block as a key/value table
when its YAML parses to a map, and otherwise falls back to rendering the block
as ordinary Markdown text. Which path a given document takes is therefore
decided by `symfony/yaml`'s parser, and the two paths produce very different
`DocumentVersion::plainText()`.

So a `symfony/yaml` upgrade that changes how any edge-case document parses will
flip it between the two — moving every comment anchor below the block — with
nothing to trigger a re-render and no signal that it happened. The renderer logs
`review.markdown.front_matter_not_tabulated` whenever it takes the fallback,
which is the hook to watch: a document that starts or stops logging it across a
dependency bump has moved. Worth deciding whether the fallback path should be
pinned by storing which path a version used, rather than recomputed.

## A renderer change that moves plainText needs a reanchor pass, not just a rerender


**Author:** Claude · **Type:** bug · **Priority:** medium · **Status:** pending

`app:review:rerender-versions` (`RefreshDocumentVersionsHtmlHandler`) rewrites
`document_versions.rendered_html` from the stored Markdown and nothing else. Every
comment anchor is an offset plus a quote into `DocumentVersion::plainText()`, which is
derived from that HTML — so any renderer change that alters the **text** invalidates
every anchor at or after the change. The browser re-locates each anchor by quote and
context (`comment_anchor_controller`'s `#findRange`), so a comment whose quote has moved
simply gets no highlight while still looking healthy in the sidebar; `comments.orphaned`
is only ever set later, by `ReanchoringService`, when someone next revises the document.

Measured on seeded data while adding front-matter and HTML-comment rendering: of four
comments placed around the affected regions, two resolved cleanly against the
re-rendered text (shifted +2 and +35 characters) and two resolved to nothing — one
anchored on front-matter text that is now table cells, one whose quote spanned the point
where a previously invisible HTML comment now contributes text. The re-render reported
"1 of 3 versions" and left all four `comments` rows byte-identical, `orphaned` still
false.

**Mitigated, not fixed.** The command now inspects every version before writing anything
and refuses outright when any version carries an anchored comment whose plain text the
re-render would move, reporting the count and exiting non-zero.
`--accept-comment-orphaning` proceeds anyway and still reports the count as a warning.
So the silent data problem is now a loud one — but the damage is unchanged if the flag is
passed, and the flag is the only way to re-render a document whose rendering has
legitimately changed. Untargeted comments (empty anchor quote) are deliberately not
counted: they are never relocated, and an alarm that cannot come true is how an opt-in
flag turns into something people pass by reflex.

The real fix is a maintenance entry point that walks stored comments and re-resolves them
against the re-rendered basis, marking the unresolvable ones `orphaned` — so the damage is
recorded when it happens rather than surfacing at the next revision. Add a `--dry-run`
that reports the counts before writing, since that is what you want before running a
renderer migration. Once that lands, the refusal and its flag should go away rather than
being kept alongside it.

Two things make this more than it looks. `ReanchoringService::reanchor()` cannot be
reused: it builds *new* `Comment` rows against a *new* `DocumentVersion`, whereas this
needs an in-place update of the existing rows — a different operation against the same
`AnchorService::resolve()` predicate. And `RefreshDocumentVersionsHtmlHandler` runs
without a transaction, so a reanchor pass has to wrap rewrite-plus-reanchor per version; a
mid-run crash that left new HTML beside stale anchors would be worse than today's uniform
silent de-highlight.

**This entry blocks three others**, all deliberately deferred rather than forgotten:
"Simplify the sanitizer block list with defaultAction(Block)", "A document cannot render a
checkbox, by either route", and "No table of contents on document versions rendered before
headings had ids". Build this first and each becomes a normal change.

## Simplify the sanitizer block list with defaultAction(Block)




**Author:** Claude · **Type:** tooling · **Priority:** medium · **Status:** pending

`App\Module\Review\Service\MarkdownRenderer` ends its config with a loop that
calls `blockElement()` on every `W3CReference::BODY_ELEMENTS` entry it does not
render, so an element it does not know about keeps its text instead of being
dropped with it. `HtmlSanitizerConfig::defaultAction(HtmlSanitizerAction::Block)`
expresses exactly that intent in one call, and additionally covers element names
the reference has never heard of (`<foobar>`), which the loop cannot.

It was not done on the branch that introduced the loop because it is not a
refactor: with Block as the default, `<script>`, `<style>`, `<iframe>`, `<form>`,
`<textarea>` and `<select>` stop being dropped and start contributing their
contents as visible text — a script body would render as prose. That changes
`plainText()` for any stored document containing one, which moves every comment
anchor below it. Doing it therefore needs a rerender **and** a reanchor pass; see
"A renderer change that moves plainText needs a reanchor pass, not just a
rerender". Elements whose text must stay out (`script`, `style`) would also each
need an explicit `dropElement()`, and note that `dropElement('style')` and
`dropElement('title')` are **no-ops in body context** — `HtmlSanitizer` filters
`W3CReference::HEAD_ELEMENTS` out of the body element config entirely.

## Document images are fetched from wherever the document points




**Author:** Claude · **Type:** security · **Priority:** medium · **Status:** pending

`MarkdownRenderer` allows `<img src>` with no origin restriction, so a document
can point an image at any host and the reviewer's browser fetches it on page
open, handing that host the reviewer's IP, User-Agent and a timestamp. Documents
are agent-authored, which makes the content a plausible injection surface rather
than something only the reviewer writes.

This is not new — the pre-existing sanitizer config allowed `img` with the full
W3C-safe attribute set — but it sits badly beside this project's stated no-egress
posture (`assets/icons/` is committed and `iconify.on_demand` is off in prod
precisely so a self-hosted instance never calls out).

`config/packages/nelmio_security.yaml` does not currently constrain it either:
the CSP is registered prod-only and sent under `report`, and its `img-src`
allows `https:` wholesale. Options are to proxy or inline document images at
render time, restrict `img-src` when the CSP goes enforcing (see "CSP is
report-only until inline scripts carry nonces"), or accept it explicitly. Worth a
decision rather than drift.

Ranked below "CSP is report-only until inline scripts carry nonces" deliberately: the
`img-src` half of the answer is decided there, and this entry only survives on its own if
the answer turns out to be a render-time proxy or inlining, which nobody has scoped.

## A document's own in-page links lose their href




**Author:** Claude · **Type:** bug · **Priority:** medium · **Status:** pending

`HtmlSanitizerConfig::allowRelativeLinks()` is not enabled in
`App\Module\Review\Service\MarkdownRenderer`, so the sanitizer strips the `href`
from any non-absolute link. A document that hand-writes `[jump](#heading-intro)`
renders as unclickable text with no error anywhere.

Pre-existing, but newly worth fixing: the renderer now mints stable
`heading-<slug>` ids for every heading (that is what the review screen's table of
contents links to), so a document author has a real reason to write intra-page
links and they will silently not work. `allowRelativeLinks()` also permits
same-origin paths like `/projects/…`, so decide whether that is wanted before
switching it on.

## Per-worktree Mailpit sidecar so e2e can run in parallel




**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

Mailpit is shared by the main stack and every worktree, so two concurrent
`just e2e` runs read each other's messages and mail-asserting specs fail
nondeterministically. That is why the suite must run `--workers=1` and
one-worktree-at-a-time. During the 2026-07-26 audit-remediation wave this
serialized gate was the single largest cost: ~15 branches each needing a full
suite run, back to back, while the DB-free static gate parallelised freely.

Owner decision (2026-07-26): fix it with a **per-worktree Mailpit sidecar**,
mirroring the nginx sidecar design already in `compose.worktree.yaml` — its own
container, its own Traefik route, and a per-worktree `MAILER_DSN` +
Mailpit API base URL that the e2e harness reads. Provisioned and torn down by
`bin/worktrees/worktree-bootstrap.sh` / `worktree-teardown.sh` alongside the
existing sidecar and databases, so `just worktree-up` remains the single entry
point.

Check when picking this up: `e2e/` currently resolves Mailpit from a fixed
host/port — that lookup has to become worktree-aware too, or the specs will
still all talk to the shared instance. Interacts with "Decide fate of
PlaywrightSyncEmailMiddleware": if Playwright-headed requests ever deliver mail
synchronously, the worktree-scoped worker requirement for mail specs goes away,
but the Mailpit isolation problem does not.

## Ship a minified site-review widget




**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

`public/site-review/widget.js` is 76 KB unminified and is the third-party embed
loaded on every page of every customer site. The 2026-07-26 audit paired this
with missing cache headers; the caching half shipped (an exact-match
`location = /site-review/widget.js` block in `docker/prod/nginx.conf`), the
minification half did not.

It was deferred because the project has no minifier at all: `package.json`
carries only eslint and prettier, and AssetMapper does not cover the widget —
it is a standalone static file served directly, not part of the importmap. So
this needs a build-step decision, which is why it is not a one-line fix. Owner
decision (2026-07-26): log it rather than bundle it into the perf PR.

Options to weigh: an `esbuild`/`terser` npm script wired into `just cs` and the
Docker prod build; or bringing the widget into AssetMapper so it gets the same
treatment as everything under `/assets/` (which would also give it a
content-hashed filename, and the cache block could then become `immutable`
instead of the current 5-minute window).

## Self-hosting audit




**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

Owner request (2026-07-26): audit the app for self-hosting readiness. Scope was
not specified beyond that, so settle it when picking this up rather than
assuming — the note is recorded here verbatim in intent.

Likely ground to cover, from what the repo looks like today: everything a
third party would have to supply or change to run Loupe themselves. That
includes the required environment variables and which have unsafe defaults
(`COMPOSE_PROJECT_NAME` is mandatory, `DATABASE_URL` ships a placeholder,
`INSTALL_TOKEN` gates `/install` and must be set, since the wizard fails
closed in production and a self-hoster who omits it cannot create their first
administrator at all); the
hard dependencies beyond Postgres (Mercure hub, Mailpit/SMTP, the messenger
worker as its own container, Traefik routing); the Stripe coupling, since
billing is currently woven through the paywall listener and account
lifecycle, and a self-hoster may want it off entirely; the prod image and
`docker/prod/` layout versus the dev compose stack; and what documentation
exists (`README.md`, `CONTRIBUTING.md`) versus what a self-hoster would
actually need.

Related decisions already recorded: CLAUDE.md notes that if the project goes
public, `docs/` should stop shipping and open work moves to GitHub issues —
that choice interacts with this.

## Encapsulate Billing: replace #[PaywallExempt] with a firewall-level rule




**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

Owner idea (2026-07-27): explore relaxing the `#[PaywallExempt]` attribute in
favour of a firewall/access-control-level rule, with the goal of making Billing
completely encapsulated — no dependency on it from anywhere else in the PHP
code.

Today the paywall reaches outward in two directions. `RequireSubscriptionListener`
(Billing) inspects every request and needs to know which routes are exempt, and
the exemption marker `App\Routing\PaywallExempt` is applied to ~18 controllers
across Account and Billing, so those modules carry a Billing-motivated
attribute. Expressing the exemption as configuration — an `access_control`
entry, a firewall rule, or a route-prefix convention — would remove the
attribute from every controller and leave Billing owning its own policy.

Points to work through when picking this up: the listener does content
negotiation config cannot express on its own (302 to the subscribe page for UI,
`402` with a JSON body for `/api/` and `/mcp`); it exempts third-party bundle
routes by prefix (`ubermuda_feature_flags_*`, `app_admin_*`) whose controllers
cannot carry our attribute either way; `/mcp` is a single endpoint dispatching
many tools, so per-route granularity does not map onto it; and whatever replaces
the marker must preserve the current deny-by-default polarity — a forgotten
exemption must fail closed (user wrongly blocked, loud) rather than open
(feature silently free). Keep `RequireSubscriptionListenerTest` and
`PaywallRedirectTest` green across the conversion. Related: "Expose the paywall
decision as a voter for the view layer".

## OAuth for the MCP and site-review widget, with project selection at consent




**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner idea (2026-07-27): let the MCP endpoint and the site-review widget
authenticate by OAuth rather than by a pasted API token, with the consent
screen offering project selection — and project *creation* — at authorization
time.

What this replaces: both surfaces today use `ApiToken` rows minted per project
and bound to it (`mcp` and `site_review` scopes), pasted into an agent's MCP
config or embedded in the widget snippet built by
`templates/Module/Project/connect_agent.html.twig`. The binding is fixed at
mint time, so switching project means minting a new token, and the widget's
credential is visible in page source.

Things this would interact with: the per-token agent-forwarding flag that
already ships (`ApiToken::$forwardsToAgent` — an OAuth scope is the natural
home for it, and the opt-in must survive the migration rather than being
granted by default); "Personal reviewer tokens as an identity layer for the
widget" (OAuth largely subsumes it, and gives per-reviewer identity for free);
"Unbound legacy MCP tokens look like a connection failure to agents" (consent-
time project selection removes the unbound state by construction); and the
deny-by-default `^/api` rule in `config/packages/security.yaml`, since OAuth
scopes would need the same per-scope `access_control` discipline. Note `symfony/mcp-bundle` is on 0.12 and
tracks the MCP protocol's own authorization spec — check what it provides
before hand-rolling a server.

## Decision controls: multi-select, and whether a choice should carry a comment


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Single-choice decision controls ship: a document fences a decision with
`<!-- decision: some-id -->` around a list, the reviewer clicks one option, and
`document_get_review` reports the answer under `decisions`. `DecisionBlockService`
renders and reads them; `loupe-documents` rule 10 teaches the syntax. Three
things were deliberately left out.

1. **Multi-select.** Every block is a radio group — exactly one answer. A
   decision that legitimately takes several answers ("which of these do we
   ship?") has no representation. `- [ ]` is already GFM task-list syntax and
   round-trips through other tooling, so it is the natural marker for a
   multi-select block, but it is currently accepted and stripped in the
   single-choice fence too. Any multi-select syntax has to distinguish itself
   from that rather than reuse it, and the storage would move from one row per
   decision to one per chosen option.
2. **Whether a selection needs an accompanying comment for the "why".**
   Currently no: a reviewer can already anchor a comment to the decision block,
   so requiring one adds friction to answering without adding a capability.
   Revisit if answers start arriving without any recorded reasoning.
3. **Whether selecting should resolve the thread attached to that passage.**
   Currently no, on the same grounds `CommentRepository::findOpenByVersion`
   already applies to `addressed`: the human choosing is not the human agreeing
   the discussion is finished, and the agent still has to act on the choice.
4. **An answer to a decision a later version dropped is retained but never
   reported.** `GetReview` iterates the current version's blocks, so a row whose
   `decision_id` no longer appears is invisible — neither surfaced nor cleaned
   up. Harmless while ids are permanent (re-adding the id brings the answer
   back, which is arguably right), but it means the table grows without bound
   and an agent cannot see that a decision it removed had been answered.
5. **Option text runs together in `plainText()`.** Converted options are
   adjacent inline elements, so `…staging firstShip straight to…` is what both
   `strip_tags()` and the browser read. The two agree, so anchors are correct
   and this is cosmetic — but a reviewer selecting across two options gets a
   quote with no separator in it.

## Version checker for self-hosted installs


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner note (2026-07-27): self-hosted installations need a way to learn that a
newer Loupe release exists — an operator running an old build should be told,
not left to notice.

Prerequisite the codebase does not have yet: **the running build has no version
identity.** `prod_image` in the `justfile` is a fixed `:prod` tag, so there is
no release number or commit SHA to compare against, and no rollback handle
either (noted in `DEPLOY.md`). Tagging images by version or commit SHA and
baking that value into the image is step one; the checker is step two.

Things to settle: where the check runs — a scheduled task on the existing
scheduler transport is the natural fit, not a per-request call; where it
surfaces — an admin-only banner or a line in the admin dashboard, never a
public page; what it contacts — GitHub releases is the obvious source but means
a self-hosted install calls out to the internet, so it must be switchable off
and must never transmit installation data; and how it interacts with the
self-hosting audit already tracked under "Self-hosting audit".

Related gap from the self-hosting audit (2026-07-28): `docker/prod/release.sh`
applies migrations unconditionally, and no expand/contract policy is written
down anywhere. Until one is, rolling an image back can leave the schema ahead
of the code — so version identity and a rollback-safe migration policy need to
land together, not separately.

## Inbound MCP events so an agent can react without being asked


**Author:** Geoffrey · **Type:** idea · **Priority:** medium · **Status:** pending

Owner note (2026-07-28): today every agent action in a session is pull-based —
the human says "92 approved" and the agent goes and looks. The idea is to close
that loop the other way: something happens outside the session, and the agent
finds out on its own.

Sketch of the chain: an external event (marking a PR approved on GitHub) hits
webhook machinery, which queues an event in Loupe — **a new Loupe feature, the
event queue does not exist yet** — and the agent picks it up by calling a
`get_events` MCP tool from a monitor it set up at the start of the session. A
skill would carry the instruction to set that monitor up, so the behaviour is
opt-in per session rather than baked into every agent.

Three things to settle before this is designable:

1. **What the queue is scoped to.** Events almost certainly belong to a project
   and a user, since the MCP token already carries both — but a PR-approved
   event has no natural Loupe project unless something maps repository to
   project.
2. **Delivery semantics.** Whether `get_events` drains (at-most-once, simple,
   loses events if the agent dies mid-handling) or acknowledges separately
   (at-least-once, needs idempotent handling). The site-review outbox settles
   the same tradeoff at-least-once: `DrainOutboxHandler` leases a batch,
   republishes, and retries with backoff until the hub confirms, which is
   only safe because the nudge is payload-free and idempotent. An event queue
   carrying real payloads does not get that for free.
3. **What stops a polling loop from being wasteful.** A monitor that wakes
   every 30 seconds all session is mostly empty calls; long-poll on the MCP
   side, or a wake-up interval tied to what is actually being waited on, are
   the obvious alternatives.

Worth noting the security shape early: an inbound event queue is a channel by
which outside parties influence what an agent does next. Event bodies are
untrusted text and must never be treated as instructions — the same rule that
already applies to site-review comment bodies.

## Worker heartbeat, so "is a worker running?" can be answered positively


**Author:** Claude · **Type:** feature · **Priority:** medium · **Status:** pending

The `worker` check on `/admin/status` (`CheckSystemStatusHandler::checkWorker()`
in `src/Module/Account/Command/`) can only prove the *failure* case: it measures
the age of the oldest available-and-unclaimed row in `messenger_messages`, so a
backlog nobody has touched for a minute means nothing is consuming. An empty
queue is reported as `Unknown`, because a running worker leaves no trace and a
green tick there would be an assertion the app cannot back up.

A positive signal is possible: listen for
`Symfony\Component\Messenger\Event\WorkerRunningEvent` (dispatched roughly once
a second, including while idle), throttle to one write every ~15 seconds, and
upsert a timestamp into a single-row table. The status page then reports "a
worker reported in N seconds ago" — genuinely observed, and it works in
production where the web and worker containers share only the database (so a
filesystem cache pool would not do).

Deliberately not built with the status page: it costs a table plus a migration
for a check the owner had already accepted could be approximate. Revisit if
"unknown" turns out to be the answer operators see most of the time.

## Pick the analytics vendor — PostHog or Umami — before the CSP commits us further


**Author:** Geoffrey · **Type:** idea · **Priority:** medium · **Status:** pending

`config/packages/nelmio_security.yaml` already allows `https://cloud.umami.is`
in both `script-src` (line 37) and `connect-src` (line 43), but **no analytics
code exists anywhere** — a grep across `src/`, `templates/` and `assets/` for
either vendor returns nothing. So the CSP pre-authorises a vendor that has not
landed, and picking PostHog instead would leave those two entries pointing at
the wrong origin.

The self-hosting audit withdrew this as a finding on the grounds that Umami was
coming shortly, and noted the part that still matters whichever vendor wins:
**the origin should be env-driven rather than hardcoded**, so an operator who
does not want third-party analytics can drop it. A self-hosted instance that
silently phones a third party contradicts the "no phone-home of any kind"
property the audit verified across every other subsystem, and that property is
the single most valuable claim in the report.

What to weigh:

- **Both self-host**, which is what keeps that property intact — the question is
  which is less work to run alongside Postgres and the Mercure hub, not which
  cloud is cheaper.
- **Scope.** Umami is page analytics. PostHog is analytics plus session replay,
  feature flags and experiments — and this app already has its own feature-flag
  system (`ubermuda/feature-flags-bundle`), so adopting PostHog raises the
  question of whether two flag systems coexist or one absorbs the other. That is
  a bigger decision than the analytics one and should be made deliberately
  rather than inherited.
- **Payload weight and CSP surface.** PostHog's client is substantially larger
  and, with session replay on, records DOM content — which is a privacy posture
  decision for an app whose users paste their own documents into it, not merely
  a performance one.

Whichever is chosen, the work is: make the origin a parameter, add the snippet
behind a feature flag so it is off by default, and correct or remove the two
`cloud.umami.is` CSP entries. If the answer turns out to be "neither for now",
delete those entries — a CSP that allows an origin nothing uses is a standing
invitation to assume something does.

## Install Umami analytics


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner note (2026-07-27): add Umami (privacy-focused, self-hostable web
analytics) to Loupe.

Things to settle when picking this up: whether the tracker is self-hosted
alongside the app or uses Umami Cloud — self-hosting means another App Platform
component and another database. The shared terraform module has no generic
"extra component" escape hatch, so this needs the same treatment the Mercure hub
got: a purpose-built opt-in component added to the module upstream (shipped
there in v1.6.0, and worth reading as the template for how to add another);
which
pages are tracked, and whether authenticated app pages are tracked at all or
only marketing/public routes; and the CSP interaction — `nelmio_security.yaml`
is report-only today, but `script-src` will need the tracker's origin before
enforcement is switched on.

Note the site-review widget is embedded on customers' own sites; keep analytics
out of `public/site-review/widget.js`, which must stay a small, dependency-free
third-party embed.

## Public /open page fed by real data


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner note (2026-07-27): build an `/open` page in the spirit of open-startup
dashboards, populated from live sources rather than hand-maintained numbers —
the application database, Umami, Stripe, DigitalOcean, and Claude token spend.

Rough shape of each source: **database** for product metrics (projects,
documents, reviews, registered users) — all already queryable; **Umami** for
traffic, which depends on "Install Umami analytics"; **Stripe** for revenue
(MRR, active subscriptions, churn) via the existing `StripeGatewayInterface`,
though the read side is not built yet; **DigitalOcean** for infrastructure cost,
via the API used by the App Platform deployment; **Claude** for what it cost in
tokens to build the product, which is the number this kind of page exists to
show.

The Claude source has a hard prerequisite worth checking before any work starts:
its data comes from Anthropic's Admin API, and **the Admin API is unavailable to
individual accounts** — it needs an organization (Console → Settings →
Organization) and an Admin API key (`sk-ant-admin01-…`), which is a different
credential from the ordinary API key. If the account is an individual one this
source is blocked until that changes, and the rest of the page should ship
without it.

Given an org, two endpoints cover it. `/v1/organizations/usage_report/messages`
returns token counts (uncached input, cached input, cache creation, output) in
`1m`/`1h`/`1d` buckets, groupable by model, workspace, API key and service tier.
`/v1/organizations/cost_report` returns USD, daily buckets only, grouped by
workspace or description. The daily bucket cap is 31 per request, so a running
lifetime total means paginating and accumulating locally rather than asking for
one number. For per-developer Claude Code cost specifically there is a separate
Claude Code Analytics API — the usage and cost endpoints are organization-wide.

Things to settle when picking it up: it is a public page, so decide what is
genuinely publishable and make aggregation the boundary — never per-customer
figures, and note that token spend is arguably the most sensitive of these to
publish; four of the five sources are third-party HTTP APIs, so it needs caching
and a stale-data story rather than fetching per request (the scheduler transport
is the natural home for a periodic refresh, and Anthropic's usage data lands
within about 5 minutes and tolerates roughly one poll a minute, so a periodic
refresh fits it well); and each source needs a read credential the deployment
does not currently carry, so it interacts with the `extra_env` wiring in
`terraform/main.tf`.

## Decide whether health checks stay hand-rolled, move to a third-party package, or become our own


**Author:** Geoffrey · **Type:** idea · **Priority:** medium · **Status:** pending

The health and status surface is currently hand-rolled and lives entirely in
this app: `App\Controller\ShowHealthController` serves `/healthz`, and
`App\Module\Account\Command\CheckSystemStatusHandler` runs the six checks
behind `/admin/status` and the install wizard's status step. Three options are
worth weighing rather than letting the hand-rolled version become the answer
by default:

1. **Adopt an existing open-source package.** `liip/monitor-bundle` is the
   long-standing Symfony option and ships checks for Doctrine connections,
   disk space, memory, and a readiness endpoint. The question is whether its
   check abstraction can express the two checks that carry the actual value
   here — a real SMTP `start()`/`stop()` against the configured transport, and
   a backlog query that distinguishes an unclaimed message from one claimed by
   a worker that has since died — or whether wrapping them in someone else's
   interface costs more than it saves.
2. **Extract our own `ubermuda/*` package**, alongside the other first-party
   bundles. Attractive only if a second application actually needs it;
   otherwise it adds a release to every change (see the bundle-pinning
   protocol in `CLAUDE.md`).
3. **Keep it in-app.** Cheapest today, and the checks are unusually
   opinionated about *this* application's failure modes.

What should drive the decision is whether the honesty of the current checks
survives the move. The worker check deliberately never reports "ok" — an idle
queue cannot prove a consumer is running — and a generic package that reports
green for "no errors" would reintroduce exactly the false reassurance the
check was written to avoid. Related: "Worker heartbeat, so 'is a worker
running?' can be answered positively".

## An agent highlight is invisible to a screen reader


**Author:** Claude · **Type:** feature · **Priority:** medium · **Status:** pending

`document_highlight` marks passages through the CSS Custom Highlight API, which
paints without touching the DOM — deliberately, because the review pane's
`textContent` must stay identical to `DocumentVersion::plainText()` or every
anchor offset shifts. The cost is that the mark carries no semantics: it has no
element, no role and no accessible name, so a reviewer using a screen reader is
told nothing about which passages the agent flagged. The carriers themselves
(`templates/Module/Review/show_document.html.twig`, the
`data-comment-anchor-target="agentHighlight"` spans) are empty and hidden.

The same gap already applies to comment anchors, so a fix should cover both.
Candidates that do not mutate the pane: a sidebar list of marked passages a
screen reader can walk, or an `aria-describedby` region summarising them. Do not
solve it by wrapping the passages in elements.

## Binding one Loupe project to several Claude Code projects is manual repetition


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

A Loupe project's MCP credential is an `ApiToken` bound to that project when it
is minted, handed over as a copy-paste command by
`templates/Module/Project/_connect_instructions.html.twig`. That command is
`claude mcp add --transport http <name> <url> --header "Authorization: Bearer
<token>"` with no `-s/--scope` flag, so it takes Claude Code's default `local`
scope — a registration that applies to the directory it was run in and nowhere
else. Using the same Loupe project from a second checkout, a docs repo, or any
worktree therefore means running the command again in each, and nothing on
either side knows the registrations are the same project.

Worktrees make this routine rather than occasional: every
`.claude/worktrees/<name>` is its own path, and `CLAUDE.md` already records
`codex-cli` going missing from worktree sessions for exactly this reason.

Nothing is broken; it is repetition with no propagation. Rotating or revoking
the token means finding every copy by hand, and a stale copy surfaces as "can't
connect" rather than as an obviously expired credential — see "Unbound legacy
MCP tokens look like a connection failure to agents" for how that reads to an
agent.

Worth deciding the shape of the fix before building one. The cheap version is
presentational: offer the `-s user` form (or both, labelled) in the connect
instructions, since a user-scoped registration is visible from every directory
and would make one Loupe project reachable everywhere in one step. The larger
version is "OAuth for the MCP and site-review widget, with project selection at
consent", which replaces the pasted token entirely — but that still authorizes
per directory unless the resulting credential is stored user-wide, so the scope
question outlives the token question and should be answered on its own.

## Ownership voters grant everything to an MCP request, and nothing says so at the voter

**Author:** Claude · **Type:** security · **Priority:** medium · **Status:** pending

An MCP request authenticates **as the project owner**: `ApiTokenAuthenticator`
builds its passport with
`new UserBadge($token->owner->getUserIdentifier(), fn () => $token->owner)`.
Every ownership-based voter in the app compares the subject's owner against the
authenticated user — `SiteReviewCommentVoter`'s entire rule is
`$subject->project->owner === $token->getUser()` — so **every one of them
returns true for a tool call by construction**.

Nothing is exploitable today: no tool calls a write path that is meant to be
human-only, and `ApiTokenAuthenticator` is registered only on the `mcp` and
`api` firewalls, so a Bearer token cannot reach the `main` firewall's routes at
all. The docblock on `SiteReviewMarkCommentAddressedTool` used to credit the
voter for this and now names the real guards instead.

What is still open is whether that should be made structural rather than
incidental. A future tool that calls a voter and reads the result as a
meaningful check would be wrong, and nothing at the voter would tell its author
so. Options worth weighing: give ownership voters an explicit
"deny when the token is a machine credential" clause; introduce a distinct
role or attribute namespace for tool context so a tool cannot accidentally ask
an ownership question; or leave it and rely on the firewall boundary, with the
convention written into `project-authz` instead of the code. The first two cost
indirection at every voter; the third keeps a real invariant only in prose.

## The actor model is unsettled — no audit trail, and an agent's writes look like the owner's


**Author:** Claude · **Type:** security · **Priority:** medium · **Status:** pending

There is no audit entity, no audit table and no dedicated Monolog channel —
`config/packages/monolog.yaml` declares only `deprecation`. What exists is scattered
`LoggerInterface::info` calls, and of every review-related write only
`review.document.verdict_submitted` records an actor at all. `SiteReviewEvent` looks like
an event log and is not one: it is a Mercure delivery outbox recording deliveries rather
than decisions, and it carries no actor.

This was three separate entries and is one question: **what is an actor here, and how is
an agent acting through an owner's token distinguished from the owner?** Settle it once.
The three faces of it:

1. **`Review` cannot be attributed at all.** It requires a non-nullable `reviewer: User`,
   and an MCP request authenticates as the project owner
   (`ApiTokenAuthenticator` builds its passport from `$token->owner`), so any
   agent-written `Review` is byte-for-byte identical to one the owner clicked. This is
   what blocks the document-approval half of "Let the agent close the loop when a human
   approves the work".

2. **Document metadata operations leave no trace.** Renaming, archiving, tagging and
   setting references all mutate a document without recording an actor;
   `Document::$archivedAt` is the only timestamp any of them writes. The content path is
   covered and the metadata path is not — a revision creates a `DocumentVersion` carrying
   its own description and ordering, so it is attributable, while the operations beside it
   are not. The gap widened as that surface grew: rename, tags, archive/unarchive and
   references are all agent-callable over MCP now, so an agent changing a document's
   metadata leaves less of a trail than one editing its text.

3. **Every agent comment comes from one global user.** Comments written through the MCP
   are authored by `App\Module\Account\Entity\User::AGENT_ID` (inserted by
   `migrations/Version20260803000402.php`) — one account for the whole instance, so two
   projects, two API tokens and two different agents produce replies that are
   indistinguishable in the thread and in `document_get_review`. That was the deliberate
   choice when `document_reply_to_comment` shipped: a per-project agent account multiplies
   rows in `users` for a distinction nobody had asked to see, and every count and sweep
   that must skip the agent would have to skip a set instead of an id.

**Decision needed** — the two designs answer different questions:

1. A per-operation audit log (actor, verb, subject, timestamp, payload) generalises to any
   future operation and can answer "what was this called before", but it is a table that
   grows without bound and needs a retention policy.
2. Actor and timestamp columns on the subject itself are far cheaper and answer "who last
   touched this", but nothing historical.

Whichever is chosen, the provenance shape for comments is already known and cheap: a
**nullable `ApiToken` reference on `Comment` alongside the existing non-nullable
`author`** — the token already carries a name and a project binding, so it identifies
which credential wrote the reply without inventing an identity. Attribution stays on the
singleton user and provenance rides beside it.

Worth deciding before something writes state that cannot be attributed afterwards.

## Addressed site-review comments disappear from the MCP


**Author:** Claude · **Type:** bug · **Priority:** medium · **Status:** pending

`SiteReviewGetTool` calls `SiteReviewCommentRepository::findPendingForProject`,
which filters on `status = Pending`. So the moment an agent marks a comment
addressed through `site_review_mark_comment_addressed`, that comment's id is returned
by no MCP tool at all.

The agent therefore cannot re-read what it addressed, report on it, or act on it
in a later session — its own write makes the record unreachable. Anything that
needs to enumerate previously-addressed comments is blocked by this, including
the parked PR-driven half of 'Let the agent close the loop when a human approves
the work'.

The fix is a read path that can return non-pending comments — either a status
filter parameter on `site_review_get` or a companion tool. Note the document
side does not have this problem: `GetReview` uses `findByVersion`, which returns
every comment regardless of state.

## Comment on a diff, not only on a document


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner note (2026-08-02): once the review UI can show a word-level diff between
two document versions, a reviewer should be able to comment on the diff itself.
Pointing at a change while looking at the change is the obvious thing to want,
and today the only commentable surface is the current version's prose.

The version-diff work deliberately does **not** ship this, and equally
deliberately does not foreclose it. Two constraints were built in for that
reason and should not be undone:

- The diff renders **in place of** the document, so while it is on screen the
  pane's `textContent` is not `DocumentVersion::plainText()`. Anchoring is
  therefore inert in diff mode — no toolbar, no composer, no highlight painting.
  `readOnly` alone does **not** achieve this and was not used for it:
  `comment_anchor_controller.js` repaints highlights on every layout regardless
  of that flag. `show_document.html.twig` instead omits
  `data-controller="comment-anchor"` entirely in diff mode, so the controller
  never connects. Re-enabling comments here means attaching it deliberately, not
  flipping a flag.
- The diff renderer must emit segments tagged unchanged, inserted and deleted,
  so that **either side's plain text can be reconstructed from the diff markup**:
  unchanged plus inserted yields the new version, unchanged plus deleted the
  old. `App\Module\Review\ValueObject\DocumentDiff` does this
  (`oldSource()`/`newSource()`), and it is the **server** half only — the
  rendered pane's `textContent` is neither side, because deleted and inserted
  lines interleave and line breaks are block layout rather than newlines. A
  comment captured in the browser will additionally need per-side markers or
  offsets in the DOM.

The open design question, which did not need answering to keep the door open:
whether a comment made while looking at a diff anchors to the new version, the
old one, or records which side it was made on. Anchoring to the new version is
the intuitive default — a reviewer commenting on a change is usually commenting
on the result — but a comment on deleted text has no home there.

## Marking a comment addressed can overwrite a human's Resolve


**Author:** Claude · **Type:** bug · **Priority:** medium · **Status:** pending

Both mark-addressed tools read a comment, check its status, and write — with no
version column, no `SELECT … FOR UPDATE`, and a `flush()` that happens after the
whole batch. A human clicking Resolve in the web UI in that window has their
resolution silently replaced by `addressed`, and the thread reopens in front of
them. The check that is supposed to refuse a resolved thread passes, because it
ran against the row as it was before they clicked.

The window is short and the collision needs a reviewer and an agent working the
same thread at the same second, which is why this is recorded rather than fixed.
It is also **not new**: `SiteReviewMarkCommentAddressedTool` has had the identical
shape since it shipped, and `DocumentMarkCommentAddressedTool`
(`src/Module/Review/Mcp/`) copied it deliberately. Fixing one without the other
would leave the surprising half in place.

The fix is the read-check-write-under-a-row-lock pattern `project-backend`
already documents — `wrapInTransaction` + `lock(PESSIMISTIC_WRITE)` + `refresh()`
around each comment — or a conditional `UPDATE … WHERE status = 'pending'` whose
affected-row count decides between `addressed` and a `already_resolved` skip. The
second is cheaper and fits the batch shape better. Note that neither is
unit-testable here: `dama/doctrine-test-bundle` runs each test inside one
connection's transaction, so two overlapping DB transactions cannot be expressed.

## There is no JavaScript test harness, and the JS is no longer trivial


**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

`package.json` carries `eslint`, `prettier` and Tailwind and nothing else — no
test runner, no DOM environment, no `test` script. The only way to execute any
JavaScript in this project is Playwright, which needs a booted app, a database
and a mail catcher, and costs minutes.

That was proportionate when the front end was a handful of small Stimulus
controllers. It is not any more: `assets/controllers/comment_anchor_controller.js`
is ~500 lines carrying the selection capture, the anchor extraction and the
highlight painting that document review depends on, and
`public/site-review/widget.js` is ~1600 lines that ships to other people's sites.

Two consequences already visible:

- **Real bugs reach review with no way to write a failing test.** A review of the
  strike shortcut found two: a stale `pendingSelection` could be struck after the
  user clicked the selection away, and keyboard auto-repeat submitted duplicate
  strikes. Both are pure controller-state bugs, reachable in a browser in
  seconds, and neither was expressible except as an e2e spec.
- **The fallback is source-content assertions.** `WidgetFileTest` and the newer
  strike-guard test read the JS as *text* and assert a guard appears in it. They
  catch a deletion and nothing else — not a reintroduction elsewhere, not wrong
  behaviour, not a regression in a path the string still matches.

What a harness would buy, concretely: `#findRange`'s ranking is a pure function
over a string and three anchor fields and could be tested directly rather than
through a browser; `#extractAnchor`'s offsets are the browser half of the
anchoring contract that PHP currently asserts alone; and the widget's fatal-state
transitions are a state machine currently covered only by whole-app e2e specs.

Worth deciding together with "Ship a minified site-review widget", since that
entry introduces a build step for the same file and the two share tooling. The
open questions are which runner (vitest is the obvious default given no bundler
is present), whether the widget's tests run against source or the minified
artefact, and whether `just ci` gains a leg or it stays opt-in until the suite
earns its place.

## Ship a skill bundle so agents know when to call Loupe


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

The MCP server gives an agent the ability to submit a document and to fetch a
review; nothing tells it *when*. Today the user has to say "pull the site
review" by hand every time, which was hit live on 2026-08-03 while storyboarding
the reveal video.

Close it with a `SKILL.md` bundle (and/or a `/loupe` slash command) that teaches
the agent the natural moments: submit a plan before implementing, pull pending
site-review comments before continuing, re-fetch after a revision. This is the
cheap half of the harness work — a skill is instructions, not an integration.

`SKILL.md` was confirmed first-party in 13 of 14 agent harnesses surveyed on
2026-08-03, so one bundle covers nearly the whole ecosystem. Caveat: Agent
Skills is the least governed spec in that survey — no version, no RFC 2119, no
discovery protocol — so expect churn.

Do **not** solve this with a blocking hook. Almost every harness's hooks halt
the agent until the hook returns (exit 2 to deny), which is the gate model a
competitor uses and the source of their largest issue cluster. Only Gemini CLI
(5 of its 11 events are explicitly advisory) and Cline (per-hook
`mode: blocking|async`) support fire-and-forget natively.

## Package Loupe as a Claude Code plugin and list it in the agent directories


**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

Distribution research on 2026-08-03 (written up in the ubermuda.xyz repo under
`gtm/loupe/strategy/`) found that a comparable tool reached ~7,500 GitHub stars
with essentially no help from Reddit, Hacker News or Product Hunt — a flat
23–39 stars/day for seven months with no decay. What drove it was being
installable from inside the tools people already use: a one-line installer, a
plugin marketplace entry, and listings in auto-generated directories.

Two build artefacts cover most of the ecosystem, because the de facto standard
is Claude Code's file layout — Copilot CLI, Cursor, OpenCode, Cline, Amp, Pi and
Droid all read parts of it directly:

- the existing MCP server (confirmed first-party in 11 of 11 harnesses checked)
- the skill bundle tracked in the entry above

Packaging detail that is easy to get wrong: `.mcp.json` goes at the **plugin
root**, not inside `plugin.json`, and `.claude-plugin/marketplace.json` must sit
at the **repo root**.

Then list it. Self-serve, no gatekeeper: Gemini CLI (add the GitHub topic
`gemini-cli-extension` plus a root `gemini-extension.json`, crawled daily), Pi
(npm keyword `pi-package`), OpenCode (PR to their ecosystem page), skills.sh,
and the MCP registry (still in preview). Curated but open: the Claude Code
plugin directory, Cursor's marketplace (manual review, plugins must be open
source — AGPL qualifies), and Kiro's Powers.

Not worth investing in: Droid, Amp, Devin Desktop and Cline have no third-party
publishing path, and Zed has no agent lifecycle hooks. Roo Code is discontinued
(archived 2026-05-15) and Windsurf/Codeium no longer exists as a brand.

## Let the agent reply inside the site-review widget


**Author:** Geoffrey · **Type:** idea · **Priority:** medium · **Status:** pending

Raised 2026-08-03. Today site review is one-way: comments are captured in the
browser and the agent pulls them. If the agent could answer *in the widget* —
acknowledging, asking a question, reporting what it changed — the loop closes
without leaving the page.

The strategic point is bigger than the feature. Every tool in this space
assumes a terminal, which serves developers who already live in one. Someone
who vibe codes does not. If the agent can answer in the browser, that user never
has to open a terminal at all, which is the audience nobody currently serves.

Scope caution, because these are very different builds: "the agent can post a
reply in an existing comment thread" is close to the current comment model with
one new author type. "There is a conversation view in the widget" is a chat
product, in a crowded space, and easy to sink months into. The first probably
carries most of the value.

## Two unpublished blog drafts still carry TODO placeholders for the skeleton repo link


**Author:** Claude · **Type:** docs · **Priority:** medium · **Status:** pending

The drafts titled "I spent weeks on a skeleton so my agents inherit my
standards" and "A skeleton drifts, so I turned mine into packages" (Loupe
project, tagged `blog-post`) both contain an unresolved HTML comment asking
for the skeleton repository's exact name and URL to be filled in.

Neither can publish with the placeholder in place, and an HTML comment
survives most Markdown renderers as invisible-but-present text rather than
failing loudly. Resolve the repo URL once and fix both.

## The blog series hand-off lines are stale in three places


**Author:** Claude · **Type:** docs · **Priority:** medium · **Status:** pending

The blog series in the Loupe project was written as a six-post arc, then had
a post inserted mid-sequence and five more appended. Three closing hand-offs
were never updated to match:

- "I spent weeks on a skeleton so my agents inherit my standards" closes with
  "that's the next post" pointing at the deterministic-guardrails piece, but
  the packages post now sits between them.
- The companion thread for that same post repeats the identical wrong
  hand-off, so fixing one without the other leaves the chain broken.
- "Deterministic guardrails: let machines catch the boring stuff" closes with
  "it's the last post in this series" — five later posts exist.

Each will read as a broken promise to anyone reading in order. Decide whether
the later posts are the same series or a second one, then fix the three
closers to match that answer.

## Enable and disable individual MCP tools per instance and per project


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

The MCP surface is all-or-nothing today: a token that reaches the server can
call every tool registered on it. A self-hosted operator has no way to withhold
a tool across their whole instance, and no project can run with a narrower
surface than its neighbours.

Two levels, and they are different controls. **Instance** is the operator
deciding which tools exist at all on their deployment — a policy switch, set
once, applying to everyone on it. **Project** is narrowing that set further for
one project's agents, which behaves more like a permission grant than a policy.

What makes this concrete: the archive tools were withheld from the MCP surface
entirely on 2026-08-02, on the reasoning that an agent able to archive can take
its own work out of a reviewer's list — then added on 2026-08-03 when the
capability proved genuinely useful for retiring duplicate uploads. A per-tool
switch is what turns that into a setting rather than a one-way decision: the
cautious posture stays available to whoever wants it, without denying the tool
to everyone else. The same shape will recur for any tool that is useful to most
projects and unwanted by some.

Check the existing feature-flag bundle before building a second mechanism —
flags already have an admin UI and are already how other capabilities are
gated. The open question is whether a per-project override fits that model or
needs its own storage.

## Gamache rule: a delegating MCP tool should not restate its query's array shape

**Author:** Claude · **Type:** tooling · **Priority:** medium · **Status:** pending

`DocumentGetTool` and `GetDocument` each carried a full `@return array{...}`
for the same payload, so adding a key to the query alone left the two
disagreeing. That pair is fixed — the query declares a `@phpstan-type
DocumentPayload` alias and the tool imports it with `@phpstan-import-type` —
but only that pair, by hand, and nothing stops the next tool from restating a
shape again.

The failure mode is worth restating because it does not look like what it is.
Found on 2026-08-04 while adding `tags` and `referencedBy`: the query was
updated, and phpstan reported four `offsetAccess.notFound` errors against the
*tests* rather than against the stale annotation on the tool — which reads as a
broken test. It is caught at all only because the tests happen to index every
key.

What is still open is the general rule: a gamache PHPStan rule asserting that a
tool whose body is a single delegation declares the same shape as what it
delegates to. Same family as the existing name-agreement rules
(`ControllerTemplateNameRule`, `MessengerHandlerNamespaceRule`). Gamache is an
external package, so this is a pull request on
https://github.com/ubermuda/gamache, not a class under `src/`.

Worth noting for whoever writes it: changing a tool's `@return` is safe with
respect to the published MCP schema, which is not obvious. The SDK builds
`inputSchema` from `@param` docblock tags only, and emits an `outputSchema`
solely when one is passed explicitly to `#[McpTool]` — verified in
`vendor/mcp/sdk/src/Capability/Discovery/SchemaGenerator.php`.

## The system status page is one handler with a check method per concern

**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

Owner note (2026-08-04): the status page needs to be modularized.

`App\Module\Account\Command\CheckSystemStatusHandler` holds every check as a
private method on one class, and the class takes a constructor argument per
thing any of them needs — a connection, an HTTP client, the mailer transport
factory, the feature flags, and six autowired environment values. Adding the
agent-account check meant adding a method and a SQL constant to a class that
already knows about SMTP, Mercure, Stripe and the messenger tables.

The shape to move to is one class per check behind a common interface, tagged
and collected, so a check declares its own dependencies and can be tested
without constructing the others. `SystemCheck` and `SystemCheckState` are
already the right value objects for it; what is missing is the seam.

Two things to preserve, because they are the parts that took thought. The
worker check deliberately never reports "ok" — an idle queue cannot prove a
consumer is running — and a generic collector must not tempt anyone into
reporting green for "no errors". And the Stripe check is skipped rather than
failed when billing is switched off, so whatever replaces the current
`if` needs a way for a check to declare itself not-applicable that is distinct
from passing.

Related: 'Decide whether health checks stay hand-rolled, move to a third-party
package, or become our own' — that entry asks whether to adopt
`liip/monitor-bundle`, whose check abstraction would be the seam this entry
wants. Settle that one first, or this refactor is done twice.

## MCP tool: hand the human a list of what needs their attention

**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner note (2026-08-04): a tool that lets an agent push "todos for the human" —
pull requests waiting to be reviewed, test scenarios to walk through by hand,
decisions the agent could not make — so that someone returning after a long
unattended session can see what to do next instead of reconstructing it from
the transcript.

The problem it solves is real and specific: an agent working for hours produces
a queue of things only a person can finish, and today that queue exists only in
the chat log. A human coming back has to read the whole session to find the
three things that need them.

Worth settling before designing it. Whether an item is its own entity or a typed
variant of something that exists — this is close to 'Agent-authored test
scenarios delivered through the site-review widget', which asks for the same
push in the site-review direction, and the two should share a model rather than
growing separately. Where it surfaces: a page of its own, the dashboard, or the
existing site-review inbox. Whether items carry a state beyond done/not-done,
since "reviewed and rejected" is a different outcome from "done". And how an
item points at what it concerns, given that nothing in the model records a pull
request today — the same missing link 'Let the agent close the loop when a human
approves the work' ran into.

The read side matters as much as the write: the agent should be able to see what
it asked for and what came back, or the next session starts by re-asking.

## Decide which models and harnesses get a first-party Loupe plugin

**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

Owner note (2026-08-05): Loupe should ship plugins for all the relevant models
and harnesses, not just Claude Code.

This is a scope question sitting on top of 'Package Loupe as a Claude Code
plugin and list it in the agent directories', which covers the packaging
mechanics and the directory listings. That entry's 2026-08-03 survey explicitly
ruled some targets out — Droid, Amp, Devin Desktop and Cline have no
third-party publishing path, Zed has no agent lifecycle hooks, Roo Code is
archived, Windsurf/Codeium is gone — so the open question is whether "all
relevant" means revisiting those (shipping a plugin people install by hand,
without a marketplace behind it) or whether it means the set that survey kept:
Claude Code, Copilot CLI, Cursor, OpenCode, Gemini CLI, Pi, Kiro.

Answer that before building anything, because it decides how many packaging
artefacts exist. The survey's finding was that one Claude-Code-shaped bundle is
read by most harnesses directly, so the cost of "all relevant" may be much lower
than it sounds — or much higher, if the ruled-out ones each need their own
format.

## Single-container install so people can try Loupe quickly

**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner note (2026-08-05): there should be a single-container install for people
who just want a quick try.

Today the shortest path to a running instance is `compose.prod.yaml` — web,
worker, Postgres and a Mercure hub, driven by a `compose.prod.env` the user has
to fill in from `compose.prod.env.example`. That is the right shape for someone
who has decided to self-host, and the wrong shape for someone deciding whether
to bother: four services and a config file before the first screen.

What a one-container image has to fold in, each of which is a real decision
rather than a packaging detail: Postgres (embedded, or SQLite, or a bundled
server in the same image), the messenger consumer (which mail, exports and the
verification link all depend on — see the worker notes in `CLAUDE.md` for what
breaks without one), the Mercure hub, and asset build output. Storage has to
survive a container restart or the trial is worse than none, so a single
declared volume is part of it.

Constraints worth stating up front. It must not become a second production
topology anyone is tempted to run for real — `compose.prod.yaml` and
`terraform/` stay the supported paths, and this one should say so. And the
existing per-process split in prod is deliberate (`docker/prod/supervisord.conf`
is the web container's CMD only, never a place to add background programs), so
whatever runs several processes in the trial image must be scoped to that image
and not leak back into the prod one.

## A step-ca thread leak takes down `docker exec` for every other container

**Author:** Claude · **Type:** tooling · **Priority:** medium · **Status:** pending

Observed 2026-08-05. Every `docker exec` into `loupe-php-fpm-1` began failing
with `OCI runtime exec failed: ... procReady not received`, and once with the
more informative `error starting setns process: fork/exec /proc/self/fd/6:
resource temporarily unavailable`. That second message is `EAGAIN` on `fork` —
the VM had run out of process slots. `just exec`, and therefore `just ci`,
`just cs`, `just phpunit` and the whole e2e path, were all dead.

**The container at fault is not this project's.** `docker stats` showed
`traefik-step-ca-1` holding **49,702 PIDs**, against 13 for php-fpm and under
20 for everything else. step-ca lives in the separate `traefik` stack, so
nothing in this repository's logs or containers points at it, and php-fpm looks
blameless because it is.

Recovery is a restart of the offending container, and it is immediate:

```bash
( cd ~/Code/traefik && docker compose restart step-ca )
```

Afterwards `docker exec` works again and TLS still verifies
(`curl -o /dev/null -w '%{ssl_verify_result}'` returns 0), so no certificate
re-issue is needed.

Worth recording for the diagnosis, which is the expensive part: the symptom
appears as a **Docker or php-fpm fault** and invites restarting this project's
stack, which changes nothing. The tell is that exec fails for *every* container
rather than one, and the fix is to find the PID hog with
`docker stats --no-stream --format '{{.Name}} pids={{.PIDs}}'` before
restarting anything. Whether step-ca leaks on a timer, on certificate issuance,
or only after a long uptime is unknown — it had been up for days. If it recurs,
that is worth pinning down, along with whether a `pids_limit` on that service
would turn a whole-machine outage into one failing container.

Same family as 'Host `pkill` does not kill a process inside the php-fpm
container': the host-visible symptom names the wrong process.

## `bin/console cache:clear` runs out of memory in dev

**Author:** Claude · **Type:** tooling · **Priority:** medium · **Status:** pending

`just exec bin/console cache:clear` fails in the dev environment with
`Allowed memory size of 134217728 bytes exhausted`, thrown while warming a
compiled Twig template. Reproduced on `main` on 2026-08-05, four runs out of
four, so it is not branch-specific; an isolated run that succeeds is luck, not
evidence, which is what made it look like a change had caused it.

It passes at a higher limit — `docker exec loupe-php-fpm-1 php -d
memory_limit=512M bin/console cache:clear` succeeds — so the fix is the CLI
memory limit for this container, not the cache itself.

**It is on the gate path, which was not obvious at first.** `just phpstan` runs
`bin/console cache:warmup` before analysing, and that inherits the same 128M —
so against a *cold* cache phpstan dies with exit 255 having analysed nothing,
and the output is a var-dumper stack trace that names neither memory nor
warmup. A warm cache makes warmup nearly a no-op, which is why it normally
passes; it bites exactly after something has invalidated the cache, such as a
dependency change, which is when the gate is most worth trusting.

Nothing sets `memory_limit` anywhere under `docker/dev/`, so this is PHP's
built-in default rather than a considered choice. Note that `just phpstan`
already passes `--memory-limit=1G` to phpstan itself — half of this was fixed
once already, on the line below the one that fails.

Related: 'phpstan runs out of memory in a worktree' — same 128M CLI limit,
different command, and worth fixing in one place rather than per recipe.

## `just e2e-down` makes the worker report a failure it did not have

**Author:** Claude · **Type:** tooling · **Priority:** medium · **Status:** pending

`e2e-worker` loops a consumer and uses a stop marker to tell a limit recycle
(relaunch) from `e2e-down` tearing the target down (stop). The marker is
consulted **only on a clean exit** — the non-zero branch exits 1 immediately
with "consumer exited non-zero — stopping rather than looping on a failure",
without ever looking at it.

Observed 2026-08-05: a normal `just e2e-down` after a fully green suite ended
the worker with exit code 1 and that message. Nothing was actually wrong — no
consumer was left in the container afterwards
(`docker exec loupe-php-fpm-1 sh -c "ps aux | grep -c '[m]essenger:consume'"`
returned 0) — but the recipe reported a failure for a routine teardown.

The comment in the recipe states the assumption that made this invisible:
"Messenger stops gracefully on SIGTERM when pcntl is loaded, so the exit code
cannot separate them." That is a claim about a *clean* exit. When `e2e-down`
removes the compose project first, the `docker compose exec` itself can fail,
and the consumer's database can go out from under it — either way the exit is
non-zero and the marker is never read.

The fix is to check the marker before deciding the non-zero exit is real, in
both branches rather than one. Worth doing because the cost is not the wrong
exit code but the wrong signal: a teardown that always prints "consumer exited
non-zero" teaches a reader to ignore the one time it means something, and
`just e2e` already depends on people trusting worker diagnostics — a missing
consumer is documented as the cause of a ~19-spec failure block.

## Cut the over-budget comment blocks CommentBudgetCheck reports

**Author:** Geoffrey · **Type:** docs · **Priority:** medium · **Status:** pending

`vendor/bin/gamache` reports 147 comment runs of 6+ lines across `src/`,
`templates/`, `assets/`, `config/`, `e2e/`, the `justfile` and `.env` (measured
2026-08-06). The check is advisory (exit 0), so nothing forces this; the sweep
is deliberate work.

The `.env` and `compose.yaml` reports are **not** artefacts of Symfony Flex
section markers fusing neighbouring comments — that was a real gamache bug, and
it was fixed upstream in ubermuda/gamache#32 and pinned here. Fixing it cleared
three findings and moved the rest off the `###>` line onto the first line of
their prose. The ten that remain are genuinely 6–8 line blocks and need the same
keep-or-compress judgement as everything else here.

Compress each to the constraint a reader needs at that line and move the
reasoning to the commit or PR that introduced it — see the `project-comments`
skill for the budget and the keep/cut test.

Not everything long is wrong. A file header documenting a distributed artefact
earns its length: `compose.prod.yaml`'s 33-line header is legitimate and was
confirmed as such on 2026-08-06. Those get `@comment-budget-ignore` on one of
their lines, not a rewrite, so they stop being re-reported. Decide per file
rather than sweeping to zero.

## Rendered front matter and annotations have no accessible name


**Author:** Claude · **Type:** feature · **Priority:** low · **Status:** pending

`MarkdownRenderer` emits the front-matter table with no `<caption>`, so screen
readers announce an unnamed table. Block-level HTML comments carry
`role="note"`, which keeps them out of the landmark list, but they are unnamed
too.

Naming either one needs a translated string, and `MarkdownRenderer` has no
translator — it renders document content rather than UI, and is constructed
directly in tests. Adding one is the decision to make; an untranslated English
label would be worse than none. Note that any visible label would also land in
`plainText()` and shift every anchor below it, so this needs the same re-render
treatment as any other rendering change.

## Malformed front matter puts a phantom entry in the table of contents


**Author:** Claude · **Type:** bug · **Priority:** low · **Status:** pending

When a document's `---` block cannot become a table, `MarkdownRenderer` renders
it as ordinary Markdown — and the closing `---` turns the lines above it into a
setext heading. `HeadingExtractor` reads the rendered HTML, so that heading
becomes an entry in the document's table of contents: `---\njust a string\n---`
yields `<h2 id="heading-just-a-string">`.

Spoofing only — the `heading-` prefix keeps a computed id from colliding with a
real page id — and it is the behaviour every front-matter document had before
the block was tabulated at all, so this is a leftover rather than a regression.
Fixing it means rendering the unparseable block as literal text (a code block)
instead of as Markdown, which changes `plainText()` again and so needs a
re-render; that is why it was left alone rather than done inline.

## An HTML comment inside a raw HTML block still renders as nothing


**Author:** Claude · **Type:** bug · **Priority:** low · **Status:** pending

`<!-- note -->` on its own line renders as a visible annotation. The same
comment wrapped in a block-level element does not:

```
<div>
<!-- note -->
</div>
```

CommonMark treats that whole region as one `HtmlBlock`, and
`HtmlCommentNodeRenderer` only converts literals made up entirely of comments,
so it declines the node and the default renderer emits the region verbatim. The
sanitizer then keeps the wrapper — `div`, `span` and `pre` are all allowed — and
drops the comment, so the reviewer sees an empty box where the note was.

This is an incompleteness in a new capability rather than a regression: before
this work every HTML comment rendered as nothing, and the shape that motivated
it (a `<!-- TODO -->` on its own line) does work. The workaround is to unwrap
the comment.

**Do not fix it by wrapping every comment found anywhere in the literal.** That
is the obvious change and it is unsafe: a comment inside an attribute value —
`<a title="<!-- note -->">` — would have the markers substituted inside the
attribute, and since `a` is allowed to carry `title` they survive sanitization.
The post-sanitization pass would then insert `<span class="…">` inside the
quoted value, whose own quote closes the attribute early and lets document
content add arbitrary attributes to the tag. Telling a comment in text position
from one in attribute position needs an HTML tokeniser, which is the sanitizer's
job — so any real fix has to run after sanitization, on parsed markup, rather
than on the raw literal.

## A document cannot render a checkbox, by either route




**Author:** Claude · **Type:** feature · **Priority:** low · **Status:** pending

Neither route works today, and that is deliberate rather than an oversight.
`MarkdownRenderer` does not allow `<input>`, so a checkbox written as raw HTML is
dropped; and `TaskListExtension` is not registered, so Markdown's `- [ ] item`
renders literally as `[ ] item`.

The allowance existed briefly and was removed for want of a consumer: rendering
every document in the development database that mentions `<input>` produced zero
inputs, because every occurrence is inside a code fence or backticks. Dropping it
costs no text — `input` is void — so the anchor basis is unaffected either way.

**Do not close this gap by registering `TaskListExtension`.** It deletes the two
characters between the brackets from the rendered text and therefore from
`DocumentVersion::plainText()`, so every comment anchor below the first task list
moves; existing document versions use that syntax, and their open comments would
orphan on the next revision. Like the sanitizer default above, it needs a rerender
plus a reanchor pass — see "A renderer change that moves plainText needs a
reanchor pass, not just a rerender". Re-allowing `<input>` is the cheaper half and
has no anchor cost, but on its own it only serves hand-written HTML.

Note the review screen's decision controls are **not** this: they are minted after
sanitization and so never pass through the allowlist.

## Review anchoring — structural fallback anchor (low priority)




**Author:** Claude · **Type:** idea · **Priority:** low · **Status:** pending

Observed while dogfooding the review loop on the site-review spec: revising a
document re-anchors open comments by matching their quoted text, so comments on
a region that gets rewritten come back `orphaned`. This is **expected** and
matches GitHub's "outdated" review comments — not a bug.

Possible future improvement, only if orphaning proves annoying in practice: add
a secondary **structural anchor** (e.g. nearest heading path + relative offset)
alongside the existing quote/prefix/suffix text anchor, and fall back to it when
the text match fails. Would let a comment survive a rewrite of its surrounding
prose by re-attaching to the same section. Not worth doing pre-emptively.

The heading half of that already exists:
`App\Module\Review\Service\HeadingExtractor` returns each heading's level, id and
character offset into `DocumentVersion::plainText()` — the same basis anchors are
measured against — read out of the stored rendered HTML, so it works on versions
that were written long before. Note `DocumentHeading::$text` is trimmed and so is
not guaranteed to equal the plainText slice at that offset.

## Host PHPUnit can't reach Postgres through Traefik




**Author:** Claude · **Type:** tooling · **Priority:** low · **Status:** pending

Running `php vendor/bin/phpunit` on the host fails at bootstrap (drop/create
schema): the DB is only reachable via Traefik's TCP `HostSNI` router on the
`postgres` entrypoint (`compose.yaml`; no direct `ports:` mapping). PHP's
`pdo_pgsql`/OpenSSL can't complete that handshake — `sslmode=require|prefer` →
`tlsv1 alert no application protocol` (ALPN mismatch), `sslmode=disable|allow` →
timeout (no TLS → SNI can't be read). Both `127.0.0.1` and
`db.loupe.dev.localhost` fail. Tests only run via the container
(`docker compose exec -T php-fpm php vendor/bin/phpunit`), which connects to
`database:5432` directly.

Want host phpunit to work. Likely fixes to evaluate: (a) publish the Postgres
port directly on the host (`ports: ["5432:5432"]` on the `database` service) so
host clients bypass Traefik; or (b) adjust the Traefik `postgres` entrypoint so
its TLS layer negotiates the ALPN libpq offers; or (c) document a working
`.env.test.local` DSN. Until then, plan task `verifyCommand`s use the container
form.

## Generalize CORS handling if the API surface grows




**Author:** Claude · **Type:** tooling · **Priority:** low · **Status:** pending

Site-review uses a small `SiteReviewCorsSubscriber` scoped to `^/api/site-review`
(reflects `Origin`, answers preflight before the firewall), mirroring how the MCP
endpoint handles CORS locally. This is intentionally per-endpoint. If we add more
cross-origin API surface, replace these ad-hoc subscribers with a single shared
mechanism — either `nelmio/cors-bundle` or one app-wide CORS subscriber driven by
a path/origin allowlist — so CORS policy lives in one place.

## Revisit: migrate API auth to Symfony's `access_token` authenticator




**Author:** Claude · **Type:** tooling · **Priority:** low · **Status:** pending

We hand-roll `ApiTokenAuthenticator` (custom `AbstractAuthenticator`). Symfony's
built-in `access_token` firewall + an `AccessTokenHandler` is the more idiomatic
mechanism. Deferred during the site-review work — decided to extend the custom
authenticator for now and revisit later. Note: `access_token` has **no** native
scope→role mapping (verified against current Symfony docs), so the migration is
a modernization, not a scope win; per-token scope roles are slightly more
awkward there (you don't own `createToken()`), so weigh that when revisiting.

## Site-review widget: surface per-comment save errors more granularly




**Author:** Claude · **Type:** feature · **Priority:** low · **Status:** pending

All widget API failures render into the single `#lp-error` banner. Fine for a
one-reviewer tool; if bulk operations ever appear, attach errors to the affected
list row instead.

## Site-review widget overlaps the review console's pinned controls (dogfooding)




**Author:** Claude · **Type:** bug · **Priority:** low · **Status:** pending

The site-review widget (loaded on Loupe's own authenticated pages when
`SITE_REVIEW_WIDGET_TOKEN` is set — dev/dogfooding) mounts a `position:fixed`
bottom-right launcher (z-index max). PR 3 pinned the document-review verdict bar
to the bottom of the 388px margin, so the launcher can overlap the "Request
changes"/"Approve" buttons in dogfooding mode. The e2e `review-loop` spec
suppresses the widget (`suppressWidget`, like the debug toolbar) to test the
review screen in isolation. Product decision to make later: the widget isn't part
of the review/site-review console screens' design — consider not loading it on
those routes (scope the `base.html.twig` widget include out of the review console)
so dogfooding a review doesn't cover the console's own controls.

## Site-review widget: navigate to a comment's page from the comment list




**Author:** Claude · **Type:** feature · **Priority:** low · **Status:** pending

In the widget's comment list, each row shows the comment body plus a
text-snippet chip (the anchored element's first line, or "General comment")
— no page URL or other location context at all, for comments made on the
current page or a different one. A reviewer has no way to tell a cross-page
comment apart from one made on the page they're looking at, let alone jump to
it. Add a "go to page" affordance (or at least a page-name label) on
cross-page comments so a reviewer can navigate to where the comment was made.

## Billing paywall answers machine clients with 402




**Author:** Claude · **Type:** feature · **Priority:** low · **Status:** pending

`RequireSubscriptionListener` gates `/api/` and `/mcp` requests like the UI
but answers `402 Payment Required` with `{"error": "subscription_required",
"subscribeUrl": ...}` instead of an HTML redirect. The MCP transport has no
notion of 402, so an agent hitting a paywalled account sees a transport-level
error rather than a JSON-RPC one; if that turns out to be confusing in practice,
give the MCP endpoint a JSON-RPC-shaped error body of its own.

## Billing DomainErrors keys have no translations, by design




**Author:** Claude · **Type:** docs · **Priority:** low · **Status:** pending

`billing.error.disabled`, `billing.error.no_active_price` and
`billing.error.no_customer` are `DomainErrors` payload values only — the
checkout/portal endpoints are fieldless buttons, so nothing renders them; the
controllers flash `billing.flash.checkout_unavailable` /
`billing.flash.portal_unavailable` instead. They are intentionally absent from
`translations/messages.en.xlf`; do not "fix" them by adding trans-units.

## Product idea (long horizon): whole-codebase review, not diff review




**Author:** Geoffrey · **Type:** idea · **Priority:** low · **Status:** pending

Owner note (2026-07-25): a full-blown code review feature over the CURRENT
STATE of a codebase rather than a diff — reviewing what the code IS, not what
changed. Distinct from the existing document-review flow and from PR-style
review. Open questions when this gets picked up: ingestion (connect a repo?
the MCP agent pushes a snapshot?), review unit (file? module? architectural
concern?), how comments anchor to code that keeps moving (the re-anchoring
machinery exists for markdown documents and may generalize), reviewer UX for
navigating a tree vs a linear doc, and how findings feed back (tracker
entries? MCP tool the agent polls, like document_get_review?).

## Personal reviewer tokens as an identity layer for the widget




**Author:** Geoffrey · **Type:** idea · **Priority:** low · **Status:** pending

Long-horizon alternative/complement to the site-review widget's shared
site-wide token: per-reviewer tokens minted via invite links and held in the
reviewer's browser, instead of a credential embedded in page markup. Buys
accountable identity on every submitted review, per-reviewer revocation, and
removes the token from page source. Costs the zero-friction "anyone on the
staging site can comment" UX (reviewers must redeem an invite first), and
needs a lightweight reviewer identity without full accounts plus a tokenless
widget bootstrap mode. Only worth picking up if per-reviewer accountability
becomes a product need — the cheaper mitigation for the exposure concern has
already shipped: `ApiToken::$forwardsToAgent` makes agent forwarding opt-in per
widget token, so a leaked token collects comments but cannot drive the agent.

## Expose the paywall decision as a voter for the view layer




**Author:** Geoffrey · **Type:** tooling · **Priority:** low · **Status:** pending

Owner question (2026-07-26), raised while reviewing the `#[PaywallExempt]`
change: should paywall exemption be expressed through `#[IsGranted]` voters
instead of a route attribute?

Decided **no** for enforcement. `RequireSubscriptionListener` is
deny-by-default (every route is paywalled unless marked exempt), whereas
`#[IsGranted]` on controllers is allow-by-default. Inverting it would turn a
forgotten annotation from "user is wrongly blocked" (loud, user-reported,
revenue-safe) into "feature is silently free" (invisible revenue leak) — the
wrong failure mode for a paywall. Three further blockers: the listener exempts
third-party bundle routes by prefix (`ubermuda_feature_flags_*`,
`app_admin_*`) whose controllers cannot carry our attribute; it performs
content negotiation a voter cannot (302 to the subscribe page for UI, `402`
with a JSON body for `/api/` and `/mcp`), so an exception listener would be
needed anyway and the decision would end up split across two files; and `/mcp`
is a single endpoint dispatching many tools, so per-controller granularity
does not map onto it.

What is still worth doing, additively: `PaywallGate::allows()` is reachable
only from the listener today, so a template wanting to show a "subscribe" CTA
has to re-derive the condition. Add a thin voter delegating to `PaywallGate`
so `is_granted('billing.active')` works in Twig. Scope is the *decision*, not
the exemption list — do not change the listener's deny-by-default polarity.
Confirm a template actually needs it before building it; no current caller was
identified. Attribute-naming and voter-shape conventions live in the
`symfony-authorization` skill.

## Deleting an API token (as distinct from revoking it)




**Author:** Geoffrey · **Type:** feature · **Priority:** low · **Status:** pending

Owner decision (2026-07-26): the existing action becomes a true revocation —
`RevokeApiTokenHandler` sets `revokedAt` and keeps the row so the
`account.api_token.revoked` audit entry still points at something real, rather
than hard-deleting it. That leaves no way to actually remove a token row.

Add a separate delete action for when a user wants the record gone rather than
merely disabled. Decide when picking it up: whether deletion is offered in the
UI at all or only as a retention job (revoked rows are audit evidence, so
purging them on demand partly defeats the point of keeping them); and whether
it should instead be a time-based purge of long-revoked tokens. Related code:
`src/Module/Account/Command/RevokeApiTokenHandler.php` and the token list on
the project connect page.

## Three container-build deprecations remain, all inside vendor bundles

**Author:** Claude · **Type:** tooling · **Priority:** low · **Status:** pending

`bin/console debug:container --deprecations` reported seven on 2026-08-04. Four
were ours and are fixed: the `$exportStorage` named-autowiring alias now carries
`#[Target('export.storage')]` at all five injection sites, and dropping the
deprecated `framework.profiler.collect_serializer_data` option took the three
`WebProfiler` Twig macro warnings with it.

The three that remain are raised inside vendor code and there is nothing to
migrate on our side:

- `Symfony\Component\HttpKernel\DependencyInjection\Extension`, raised through
  `symfony/mercure-bundle`'s `MercureExtension`. Clears when that bundle updates.
- `Symfony\UX\Turbo\Bridge\Mercure\TurboStreamListenRenderer` and
  `Symfony\UX\Turbo\Twig\TurboStreamListenRendererInterface`, both raised by
  `symfony/ux-turbo` registering its own classes.

**The ux-turbo pair was expected to be the one with a migration path we own. It
is not.** Nothing in `templates/`, `src/` or `assets/` renders a stream-listen
tag — no `turbo_stream_listen`, no `turbo_stream_from`, no
`<twig:Turbo:Stream:From>` — so there is no call site to move to
`MercureStreamSourceRenderer`. The deprecation fires from the bundle's service
registration at container build, whether or not the app uses it. Recorded so the
next reader does not repeat the search.

Re-check after any `symfony/mercure-bundle` or `symfony/ux-turbo` release; both
are removals scheduled for the next majors, so they cannot be ignored forever.

## One user-facing list query is still unbounded


**Author:** Claude · **Type:** bug · **Priority:** low · **Status:** pending

PR #79 paginated the projects list and the per-project documents list. The MCP
`document_list` tool (`Mcp/DocumentListTool`) has since gained its own
pagination too (`page`/`perPage`/`hasMore`, backed by
`DocumentRepository::findPaginatedByProject`), and the site-review page moved
to a flat per-project comment list (`SiteReviewCommentRepository::findForProject`),
which removed the cross-review comment-numbering problem that used to block
paginating it.

What remains unbounded: `ReviewRepository::findByReviewer`. No page renders
it — its only consumer is `ReviewExporter`, a full-export service that is
supposed to read everything, so there is nothing to attach pagination controls
to. Bound it only if exports start running out of memory, and then by
streaming, not paging.

## Arbitrary Tailwind values remain in the vendored ubermuda bundles


**Author:** Claude · **Type:** tooling · **Priority:** low · **Status:** pending

After the 2026-07-27 CSS cleanup removed every custom token and arbitrary value
from `assets/styles/app.css`, three arbitrary utilities are still compiled into
the shipped stylesheet, all from vendored bundle templates that this repo does
not own:

- `min-h-[2.5rem]` and `min-w-[8rem]` — `ubermuda/feature-flags-bundle`,
  `templates/admin/create.html.twig` and `edit.html.twig`
- `z-[100]` — `ubermuda/admin-bundle`, `templates/base.html.twig`

They are compiled because `app.css` lists those vendor template directories as
`@source` paths, which it must for the admin UI to be styled at all. Fixing
them means PRs on the two bundles, the same constraint that applies to gamache
rules. Low priority — three utilities, no correctness impact.

Related, and already fixed: Tailwind v4's automatic source detection scans
every committed file, so documentation that merely *names* a class was
compiling it into production CSS. `app.css` now carries
`@source not "../../.claude"` and `@source not "../../docs"`.

## Product idea (long horizon): drag DOM elements in the widget to try layouts


**Author:** Geoffrey · **Type:** idea · **Priority:** low · **Status:** pending

Owner note (2026-07-27), raised with the caveat that it is probably too
ambitious: let the reviewer actually move elements around on the page to try
out a different layout, instead of only describing the change in words.

The moving is the easy part — the widget already has an element picker and a
fixed overlay, and dragging a node is a small amount of DOM work. The hard part
is that the deliverable is not a moved element, it is a change an agent can
act on. A dragged node yields a new position in *this* rendering, at *this*
viewport width, with whatever inline styles the drag applied; none of that
tells the agent which rule to edit, whether the intent was a flex order change
or a margin, or what should happen at the other breakpoints. Getting from
"reviewer moved this box" to a defensible CSS change is the whole feature, and
it is why this stays an idea rather than a scheduled item.

If it is ever picked up, the useful output is probably a description of the
intended relationship ("this belongs above that", "these should be side by
side") captured alongside a before/after screenshot, not a DOM diff. That makes
it an extension of the same capture surface as 'Attach a screenshot to a
site-review comment' and 'Drawing on the page in the site-review widget' — all
three are the reviewer showing rather than telling, and they should share one
composer rather than growing three parallel modes.

**Do 'Anchor a site-review comment to several elements, not just one' first.**
It delivers the stated relationship — the part that actually survives into a CSS
change — for the price of a data-model change, with none of the intent-inference
problem above. Once multi-anchor comments exist, revisit whether dragging adds
enough over them to be worth building at all.

## Dead semantic classes accumulate in app.css with nothing to catch them


**Author:** Claude · **Type:** tooling · **Priority:** low · **Status:** pending

`assets/styles/app.css` defines roughly 380 semantic component classes across
1,777 lines, built with `@apply` inside `@layer components`. Because a class is
declared in CSS rather than emitted on demand from template usage, deleting the
markup that used it leaves the rule behind — and nothing currently notices.
Checking every component class against `templates/`, `assets/js/`, `src/` and
the `vendor/ubermuda/*` bundle templates, then discounting every class built by
interpolation (`lp-flash--{{ label }}`, `lp-ribbon__bar--{{ state }}`,
`status-check-badge-{{ state }}` and similar), leaves 24 that are referenced
nowhere:

```
lp-doc-list  lp-doc-row  lp-doc-row__main  lp-doc-row__meta  lp-doc-row__tags
lp-doc-row__title  lp-doc-row__title--stretched  lp-page  lp-page-header
lp-page-title  lp-section-title  lp-table  lp-select  lp-code
lp-key-values  lp-key-values__row  lp-copy-row  lp-form-hint  lp-anchor
lp-anchor--orphan  lp-btn--warning  lp-comment-composer--untargeted  kbd
admin-badge-off
```

Two of those are whole abandoned families rather than stragglers — the
`lp-doc-*` row component and the `lp-page*` / `lp-section-title` page shell.

Deleting them is the small half. The durable fix is a check that fails when a
class defined in `@layer components` is referenced nowhere, since this will
recur every time a component is replaced. It needs to understand interpolated
class names or it will be too noisy to keep: the safe form is to treat a
defined class as used when some template contains its prefix immediately
followed by a Twig expression, which covers the modifier families above without
whitelisting them by hand. Verify `admin-badge-off` against the admin bundle's
compiled assets before removing it — the scan covered that bundle's templates
and CSS, but a class applied from bundle JavaScript would not show up.

## Anchor offsets still diverge from the browser above the BMP


**Author:** Claude · **Type:** bug · **Priority:** low · **Status:** pending

`AnchorService` counts codepoints (`mb_substr`/`mb_strpos`/`mb_strlen`) while
`assets/controllers/comment_anchor_controller.js` counts UTF-16 code units. The
two agree for every character in the Basic Multilingual Plane, so ordinary
accented Latin, Greek, Cyrillic and CJK text is fine — but each emoji or other
astral-plane character costs one unit on the server and two in the browser. The
observable effect is limited: no offset crosses the wire, so this only shifts
the 32-character context window and the 8-character fingerprint by a character
or two, which at worst reranks two occurrences of a repeated quote differently
on the two sides.

**Fix the browser, not the server** (owner decision, 2026-08-02, deferred rather
than declined). Making PHP count UTF-16 units is the expensive direction: PHP has
no native UTF-16 length, so every window slice would need a conversion or a
surrogate count, inside the context-scoring path that was deliberately moved back
to byte-space search precisely because `mb_*` slicing made resolution quadratic —
6.4 seconds on a 205 KB document before that fix. JavaScript can iterate
codepoints for nothing (`Array.from`, or spread), so changing `#extractAnchor`
and `#findRange` to slice by codepoint costs no server time and makes both sides
agree completely, closing this entry rather than narrowing it.

Wave C already edits that controller for strike, suggest and agent highlights, so
that is the natural moment.

## `comments.anchor_offset_hint` changed units with no backfill


**Author:** Claude · **Type:** bug · **Priority:** low · **Status:** pending

`AnchorService` used to write `Anchor::$offsetHint` as a byte offset into
`DocumentVersion::plainText()` and now writes a character offset. The column was
deliberately not migrated: there was no historical data at the time — the owner
confirmed it, `SeedDevDataCommand` creates no comments, and e2e rows are created
fresh per run. Nothing to do today; this exists so a future deploy over real
data does not rediscover it.

If that ever changes, the danger is a **mixed** version — some rows written
before the switch, some after — not old rows on their own. Two consequences,
both invisible until someone reads the sidebar:

- `CommentRepository` orders threads by `offsetHint`. Byte offsets are monotonic
  in character offsets row-by-row, so a version that is entirely one unit still
  sorts correctly; a version holding both does not, and the sidebar reading
  order is wrong until that version is revised.
- `AnchorService::resolve()` weighs proximity to `offsetHint` when a revised
  document repeats a quote, so a stale byte offset can pull the match to the
  wrong occurrence. That pick is **permanent**: `create()` then writes a
  confident character offset for the wrong span, and nothing afterwards can tell
  it was ever wrong.

Either backfill (`offsetHint = mb_strlen(substr(plainText, 0, offsetHint))` per
comment, against its own version's text) or accept a one-revision settling
period, but decide it before the first deploy that carries real comments across
the change.

## Version diff loses word marks when a revision changes a line and adds one beside it


**Author:** Claude · **Type:** feature · **Priority:** low · **Status:** pending

`jfcherng/php-diff` only marks individual words inside a replaced block when
both sides have the same line count (`AbstractHtml::getChanges()`), so a
revision that rewords a paragraph *and* inserts another right after it produces
one replace block of 1 old line against 3 new ones — and the reworded paragraph
comes out as a whole-line delete plus insert instead of a word-marked pair. The
output is correct and readable, just coarser than it needs to be, and this shape
(edit a sentence, add a paragraph) is common.

The library's own `Combined` renderer handles it by joining both sides with
`\n`, running the word line-renderer over the joined strings, then splitting
back (`markReplaceBlockDiff`). Doing the same in
`App\Module\Review\Service\MarkdownDiffer` means driving
`LineRendererFactory`/`MbString` directly and reproducing the renderer's
escape-then-mark ordering by hand, which is why it was not done up front — the
escaping order is what stops a literal `<del>` in the Markdown being read as a
diff mark. Any fix must keep `DocumentDiff::oldSource()`/`newSource()` exact;
`MarkdownDifferTest` pins that.

## Version diff is only reachable for adjacent version pairs


**Author:** Claude · **Type:** feature · **Priority:** low · **Status:** pending

The `app_document_review_diff` route takes any two version numbers, but the only
links to it are the "What changed since v(n-1)" entries in the version switcher
on the document review page, so comparing v1 with v4 means editing the URL. A
reviewer who left comments on v1 and comes back after three revisions wants
exactly that comparison. Needs a version picker on the diff view itself, not
another set of links in the switcher.

## No table of contents on document versions rendered before headings had ids


**Author:** Claude · **Type:** bug · **Priority:** low · **Status:** pending

`App\Module\Review\Service\HeadingExtractor` reads heading ids back out of
`DocumentVersion::$renderedHtml`. A version rendered before `MarkdownRenderer`
began emitting those ids carries none, so nothing is extracted and the review
screen shows no contents panel for it. New and revised versions are unaffected.

Nothing needs doing on deploy, and no migration was written on purpose: this app
does not keep backward compatibility for stored renderings. The failure is also
quiet in the right direction — a version with no ids shows no panel at all,
rather than a panel of links that go nowhere.

The remedy for a given document is `app:review:rerender-versions`, with one
caveat worth knowing before reaching for it. That command refuses to run when
re-rendering would leave an existing comment unable to resolve, unless
`--accept-comment-orphaning` is passed. Adding heading ids does not itself move
any text — ids live in attributes, which `plainText()` never sees — so a version
that predates only that change re-renders cleanly. A version old enough to
predate a renderer change that *did* move text is the one where the guard fires,
and where the choice is between a table of contents and stranded comments.

What removes that choice is the re-anchoring pass described in "A renderer change
that moves plainText needs a reanchor pass, not just a rerender": with it, an old
version can be brought forward and its comments re-resolved in the same motion.

## Referencing a document changes a page the referrer may not write to


**Author:** Claude · **Type:** security · **Priority:** low · **Status:** pending

Creating a reference requires `McpBoundProjectVoter::DOCUMENT_WRITE` on the
source document and only `DOCUMENT_READ` on the target
(`ReviewSubjectResolver::requireReferences()`), yet the target's rendered page
visibly changes: its "Referenced by" list grows.

Harmless while a project's documents share one owner, since read and write
land on the same people. It becomes a graffiti vector the day a project has
several users with differentiated grants — someone who may only read a
document can still add a line to it. Requiring WRITE on the target is the
wrong fix: it would break pointing at something you are allowed to read,
which is the normal case. Revisit when per-document grants exist.

## Search indexing can fail on a document the app itself accepts


**Author:** Claude · **Type:** bug · **Priority:** low · **Status:** pending

`to_tsvector` caps a vector at 1,048,575 bytes while `MAX_MARKDOWN_BYTES` allows
1,048,576, so there is a narrow band where a document passes the app's own limit
and `App\Module\Review\Service\DocumentSearchIndexer` raises.

Measured on 2026-08-03, the band is narrower than the two numbers suggest:
indexing a full 1 MiB of varied prose succeeded, and 1,154,787 bytes of source
produced a 360,310-byte vector — 34% of the cap. Stemming and de-duplication mean
realistic text shrinks by roughly two thirds, so reaching the cap needs
pathological input (a very large number of long, distinct, unstemmable tokens),
not a long document. Recorded for the asymmetry rather than as a live incident.

It bites unevenly. `ReviseDocumentHandler` indexes inside its transaction, so a
failure rolls the revision back. `CreateDocumentHandler` and
`RenameDocumentHandler` index after their flush, so the row commits and the
document is left permanently unsearchable with nothing to retry it. Closing this
means either bounding what is fed to `to_tsvector` or lowering
`MAX_MARKDOWN_BYTES` below the tsvector cap; making all three handlers
transactional is **not** the answer, because wrapping create would close the
EntityManager on a rejected tag name.

## The display-name maximum length of 150 is written out in ten places

**Author:** Geoffrey · **Type:** tooling · **Priority:** low · **Status:** pending

The `users.full_name` limit is encoded independently as `MAX_LENGTH` in
`src/Module/Account/Service/DisplayNameDeriver.php` and in
`assets/controllers/display_name_suggestion_controller.js`, as
`MAX_FULL_NAME_LENGTH` in `App\Module\Account\Command\CreateAdminUserHandler`
and `App\Module\Account\Command\ResolveSocialLoginHandler`, as
`#[Assert\Length(max: 150)]` on `RegistrationRequest`, `InstallAdminRequest`
and `ProfileRequest`, as `#[ORM\Column(length: 150)]` on
`App\Module\Account\Entity\User`, as `left(..., 150)` in
`migrations/Version20260804225237.php`, and as `mb_substr(..., 0, 150)` in
`src/Module/Account/Controller/Dev/RegisterAndVerifyController.php`. Raising or
lowering the limit means finding all ten, and the JS copy cannot read a PHP
constant at all, so the two derivers can silently disagree on truncation.

Consolidating touches the entity mapping, three form DTOs and the built asset
pipeline, so it is a refactor rather than a fix — worth doing on its own branch,
not folded into a change that happens to touch one of the ten.

## Bump `.skeleton.json` once the Turbo-prefetch PR merges

**Author:** Claude · **Type:** tooling · **Priority:** low · **Status:** pending

The convention itself is ported — `ubermuda/symfony-skeleton#98` adds the
`data-turbo-prefetch="false"` note to that repo's `project-frontend` skill. What
is left is the bookkeeping: `last_ported_commit` in `.skeleton.json` should move
to the merge commit once it lands.

Not done up front on purpose. That field records how far this project has
absorbed the skeleton, so advancing it past an unmerged branch would claim a
merge that has not happened and make the next `update-from-skeleton` run skip
whatever else landed in between.

## A review comment has no timestamp, so its card cannot show an age

**Author:** Claude · **Type:** feature · **Priority:** low · **Status:** pending

`App\Module\Review\Entity\Comment` carries no created-at column — the
constructor takes version, author, body, anchor, parent and replacement, and
nothing else. Every other entity on the review path has one
(`Document::$createdAt`, `DocumentVersion::$createdAt`), so this is an omission
rather than a decision.

The visible cost is on the review screen: a comment card shows its author and
its status but cannot show when it was written, and a thread with several
replies gives no sense of how the conversation unfolded.
`templates/Module/Review/components/CommentThread.html.twig` has a comment
marking where the age would go.

Closing it is a nullable `#[ORM\Column]` on `Comment`, a migration with a real
current-datetime version name, and `|relative_time` in the two places the
template marks — plus a decision about what to show for the rows that already
exist, since backfilling them with the migration timestamp would claim every
old comment was written the day the column shipped. Leaving those blank is
probably the honest answer.

## Connect cannot mask a token, because no part of the raw value is stored

**Author:** Claude · **Type:** feature · **Priority:** medium · **Status:** pending

The Connect screen is meant to show a masked token — `loupe_••••••••••••8f2a`,
where the last four characters are the real tail — so an operator can tell which
token a project is using without the value being readable over a shoulder.

It cannot today. `App\Module\Account\Entity\ApiToken` persists only a sha256
`tokenHash`; the raw value is `bin2hex(random_bytes(32))` and is handed to the
caller once at creation and never stored. There is nothing to mask, and showing
four characters of the hash would display a string that is not part of the
token. The screen renders the token's label and its creation date instead.

The same gap is why both code panels on that screen still emit the literal
`YOUR_TOKEN` on an ordinary page load rather than the configured value: the
snippet needs the token, and only the request that created it ever had one.

Closing it means storing a non-secret tail at issue time — a `tokenTail` column
written alongside `tokenHash` — and a migration. Four characters of a 64-hex
token leaves 60 unknown, so the tail is not usefully brute-forceable, but that
is a decision to take deliberately rather than assume.

## The documents list reports rows on the page as if it were the filtered total

**Author:** Claude · **Type:** bug · **Priority:** medium · **Status:** pending

The filter row on `templates/Module/Review/list_documents.html.twig` renders
"N of M documents". M is the project's unfiltered total and is right. N is
`items|length` — the number of rows on the current page — because
`ListDocumentsView` exposes `totalPages` but no filtered count.

On a single-page list the two coincide and the line is correct. On a paginated
one it under-reports: a search matching 30 documents on a 20-per-page list reads
"20 of 47 documents", which a reader will take to mean the search matched 20.

The fix is to thread the filtered total through `ListDocumentsHandler` into
`ListDocumentsView` and render that. Until then the number is wrong whenever
pagination engages, so this is worth doing before the list grows.

## A verdict cannot be undone, and a resolved comment cannot be reopened

**Author:** Claude · **Type:** feature · **Priority:** medium · **Status:** pending

Both controls are specified in the Chartreuse design and neither has a route.

`src/Module/Review/Controller/` has `SubmitReviewController` but no undo, so
approving a document or requesting changes is final from the UI — the verdict
bar at the foot of the review page reports the outcome with no way back. The
design puts an **Undo** on that bar.

Separately there is `app_comment_resolve` but no reopen, so a thread resolved by
mistake can only be deleted. The comment card shows **Resolve** on an open
thread and, per the design, should show **Reopen** on a resolved one.

Each is a command + handler pair and a route, following the shape of the
existing resolve action. The templates already have the places they go.

## A ready data export offers no way to download it

**Author:** Claude · **Type:** bug · **Priority:** medium · **Status:** pending

`templates/Module/Account/show_account_settings.html.twig` lists past exports
with their timestamp and state, including `Ready` — but renders no download
control, so the only route to a finished export is the emailed link.

The route exists (`app_account_export_download`, `GET
/account/exports/{id}/download`) and takes a `?token=` query parameter.
`DataExport::complete()` returns the raw token once and persists only its
sha256, and `ShowAccountSettingsView` exposes neither it nor anything the
template could derive it from — so a link built from `export.id` alone 404s by
design, which is the correct fail-closed behaviour.

The token is not what authorises the download, so closing this is not simply a
matter of dropping it. `DownloadDataExportController` already sits behind the
`ROLE_USER` catch-all and separately checks that the export belongs to the
signed-in user, so a forwarded link is refused on ownership alone. What the
token actually carries is the **48-hour expiry**: `isDownloadTokenValid()`
bundles the hash comparison, the Ready-status check and `isExpired()` into one
call, and the expiry reaches the request through no other path.

So closing it means splitting `isExpired()` out as a gate of its own and
letting an authenticated owner download without a token. Doing only the first
half — authorising on the session and skipping `isDownloadTokenValid()`
entirely — would hand out expired archives indefinitely, which is the whole
reason the window exists.

## `document_highlight` matches against source-wrapped text, not rendered prose

**Author:** Claude · **Type:** bug · **Priority:** medium · **Status:** pending

A quote handed to `document_highlight` fails with `not_found` whenever the
passage it names was wrapped across two lines in the submitted Markdown, even
though it reads as one continuous sentence on the rendered page. Observed
2026-08-07 submitting an 80-column-wrapped document: two quotes that happened to
sit within a single source line anchored fine, and two that spanned a soft wrap
were both rejected.

The cause is that soft wraps survive rendering. CommonMark emits the source's
newlines inside the `<p>`, and `DocumentVersion::plainTextOf()` is
`html_entity_decode(strip_tags(...))`, which preserves them — so the text
`AnchorService::fromQuote()` searches contains `Until the server says which of\nthree
things went wrong`, while any caller quoting what it read on the page sends a
single space. `SetDocumentHighlightsHandler` already trims the quote's outer
whitespace for exactly this class of reason; interior whitespace gets no such
treatment.

This is worth separating from the constraint the `loupe-documents` skill already
states — that a quote must stay inside one paragraph or list item, because block
boundaries are real line breaks. That one is inherent. This one is an artefact of
how the author happened to wrap their source, which is invisible to a reader and
which nothing warns about. Wrapping prose at 80 columns is the normal shape for
every Markdown file in this repository, so the tool is hardest to use on
precisely the documents it is meant for.

The fix is to collapse whitespace runs on both sides of the comparison rather
than only trimming the ends — but the anchor stored has to keep pointing at the
right offsets in the unnormalised text, so this is a change to how
`AnchorService` locates a quote, not a change to `plainText()`. Altering
`plainTextOf()` would move every stored anchor in every existing version, which
is the failure mode already recorded in 'A renderer change that moves plainText
needs a reanchor pass, not just a rerender'.

## A decision card cannot show its own recorded answer

**Author:** Claude · **Type:** feature · **Priority:** medium · **Status:** pending

The Chartreuse design puts a line inside each decision card reporting what was
chosen — "Chosen: option 1, recorded against v3." What the app has instead is a
single `#decision-status` region for the whole page
(`templates/Module/Review/show_document.html.twig`), replaced by a Turbo stream
from `SelectDecisionOptionController` with one generic saved/failed message. A
page with five cards has one status line between them all, and it never names
the option or the version.

The obvious fix is blocked by anchoring. The card renders inside
`[data-comment-anchor-target="doc"]`, whose text is the basis every comment
offset is measured against — `DocumentVersion::plainText()` is `strip_tags()` of
the stored HTML, and `ShowDocumentControllerTest` asserts the rendered pane's
text still equals it exactly. Injecting a per-card sentence at display time
would add text to the pane that is not in the stored version, so every anchor
below the first card would resolve to the wrong passage.

The way through is to keep the text out of the DOM's text content:
`DecisionBlockService::withSelections()` already post-processes the stored HTML
at display time and deliberately adds **attributes only**, so it could write a
`data-decision-chosen="…"` attribute the CSS renders with `content: attr(...)`.
Generated content is not part of `textContent` and `strip_tags()` never sees it,
so the invariant holds. The cost is that the sentence has to be composed by the
caller — `withSelections()` takes no translator and no version number today —
and that generated content is read inconsistently by screen readers, which
matters more here than for the card's eyebrow because this text is an announced
live region rather than decoration.
