# Next steps

Open work and observations worth revisiting. Delete items entirely once resolved.

Entries are ordered by priority (high → medium → low); insert new entries at
the end of their priority band. Format and rules: `project-next-steps` skill.

## Review UI: version diff view



**Author:** Geoffrey · **Type:** feature · **Priority:** high · **Status:** pending

Found while dogfooding the nine-features design review: after `revise_document`,
the reviewer sees only the new version — there is no way to see what changed
since the version they commented on. Add a diff view between document versions
(at minimum current vs previous; ideally any two), so re-review means reading
the delta, not the whole document again.

## Proper HTTP API + outbound webhooks



**Author:** Geoffrey · **Type:** feature · **Priority:** high · **Status:** pending

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

**Author:** Geoffrey · **Type:** feature · **Priority:** high · **Status:** pending

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

**Author:** Geoffrey · **Type:** tooling · **Priority:** high · **Status:** pending

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

## `phparkitect.php` enforces nothing — the CI leg has never rejected anything

**Author:** Claude · **Type:** tooling · **Priority:** high · **Status:** pending

`phparkitect.php` at the project root contains no rules — only the
commented-out example from the package's own documentation. `just arkitect`
runs on every commit as part of `just ci`, passes every time, and has never
checked a single thing. A gate that reports success for doing nothing is worse
than no gate: it occupies the slot where architecture enforcement is supposed
to be, so nobody notices the enforcement is absent.

This is the same class of failure as the php-cs-fixer finder that matched zero
files and let a formatting bug through a green pipeline — fixed since by
switching `.php-cs-fixer.dist.php` to explicit excludes plus a throw when the
finder matches nothing. The arkitect equivalent has no such guard.

Two pieces of work, in order:

1. Write real rules. The obvious first candidates are the module boundaries
   under `src/Module/` (a module must not depend on another module's internals)
   and the domain/infrastructure direction: a dependency rule catches
   infrastructure leaking *into* the domain, which is the half that is
   mechanically checkable — it says nothing about domain logic leaking *out*
   into an adapter, since adapters may depend on everything. See also "Domain
   boundaries sweep (after the current feature wave)", which this overlaps.
2. Prove each rule red before green. Point it at a real violation, confirm it
   fails, fix the violation, confirm it passes. A rule that has never been seen
   rejecting anything is how this entry came to exist in the first place.

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
the client was in the broken state: `bin/console debug:mcp` lists all seven
tools; `var/log/dev.log` shows every tool registering on each handshake
(`create_document`, `get_document`, `get_review`, `list_documents`,
`revise_document`, `address_site_review_comments`, `get_site_review`); `POST
/mcp` answers 200; unauthenticated requests answer 401; and all app containers
were up with the app serving normally.

**Two dead ends, recorded so they are not chased again.** `GET /mcp` answering
405 and `POST /mcp` answering 202 both look like smoking guns and are neither —
405 on GET is a legal way to say the server offers no server-initiated stream,
and 202 is the correct response to a notification, which has no reply by design.
Both were mistaken for the cause before being ruled out.

**The one real anomaly worth pulling on:** every tool registers **twice** per
handshake, with two separate `Manual element registration complete` lines in the
log. That is either benign client retry or the bundle mishandling session state.
`symfony/mcp-bundle` is pinned `^0.12.0` and resolves `mcp/sdk v0.7.0` — both
pre-1.0 — so checking whether a newer release mentions tool-list delivery or
session handling is the cheapest next step.

**Open question that sets the real severity:** every observation here was against
the local dev host. Whether a deployed instance drops connections the same way is
unknown, and it decides whether this is a local annoyance or a production defect
affecting every agent that connects. Establishing that comes before any fix.

## Dashboard document search + status/tag filtering



**Author:** Claude · **Type:** feature · **Priority:** medium · **Status:** pending

Deferred from the SaaS visual redesign (visual-only phase). The dashboard mockup
envisioned a search box + status filter + tag filter, but `autosearch_controller`
submits to the server, so making search real needs backend work: a query param on
the documents controller and a repository filter (title contains, status equals,
tag in). Tag filtering further depends on the not-yet-built tag entity.

The filter bar now exists: `.lp-list-filters` in
`templates/Module/Review/list_documents.html.twig`, sitting outside the
`{% if items|length > 0 %}` block, carrying the archived filter. Add search and
status/tag filters to it rather than building a second one.

Three things that will bite whoever picks it up:

- Every filter param must be threaded through `App\Module\Review\View\DocumentListQuery`
  — its `routeParams()` is what the clamp redirect, the `Pagination` component's
  `routeParams`, the rename link and the archive actions all merge. A filter read
  straight off the request and not added there vanishes from whichever of those
  four forgets it.
- The empty-state copy (`review.dashboard.empty`, "No documents yet.") is worded
  for "no documents at all". The archived filter can only widen the list so it
  never needs different copy, but a search or status filter can match nothing —
  that needs a second key chosen by whether any filter is narrowing.
- `findPaginatedByProject()` is shared by this controller and the MCP document
  listing, so an added filter argument must be optional or both callers change
  (`$includeArchived` is the existing example).

Note the list already issues one `countOpenByVersion()` query per row; adding
per-row tag lookups without batching would compound an existing N+1.

## Site-review: harden Mercure publish against a hung hub (still open)



**Author:** Claude · **Type:** bug · **Priority:** medium · **Status:** pending

`SubmitReviewHandler::publish()` makes a synchronous HTTP call to the Mercure hub
after `flush()`. `symfony/mercure-bundle` exposes no per-hub `http_client` config
option, so the call uses the default HttpClient timeout — a hung hub could add
latency to every review submit before the catch fires. Wire the default hub to a
scoped HttpClient with a low `timeout`/`max_duration` (custom hub service or a
decorated HttpClient) so a slow hub can never noticeably delay a review submit.

Everything around it has since improved — the failure is logged at `error` with
the project id and topic, the update carries a project-scoped monotonic
`sequence` as a stable event id, and a failed publish is now replayed by
`DrainOutboxHandler` off a five-minutely tick — but the latency problem itself
is untouched, because the submit still publishes inline before the drain ever
sees the row. The drain makes the same untimed call, though a slow hub there
only stalls a worker rather than a visitor's request.

Note that retrying is only safe because the nudge is payload-free: a duplicate
publish is harmless, since a redundant pull via `get_site_review` just finds
nothing still `Pending`.

## CSP is report-only until inline scripts carry nonces



**Author:** Claude · **Type:** security · **Priority:** medium · **Status:** pending

`config/packages/nelmio_security.yaml` sends the policy under `report` rather
than `enforce`, so it blocks nothing today. Switching to enforcing needs
per-request nonces on the inline scripts (importmap, theme styles) — Nelmio's
`csp_nonce()` Twig helper — because `script-src` currently relies on
`'unsafe-inline'`.

Also revisit the allowlist when flipping it: `connect-src` does not include the
Mercure hub origin. That is fine today (browser-side Mercure turbo streams are
disabled in `assets/controllers.json`; the only subscriber is the Go bridge,
which CSP does not govern), but enabling browser SSE would need it added.

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
field — the MCP `address_site_review_comments` tool only flips the status to `Addressed`, it
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

## Domain boundaries sweep (after the current feature wave)



**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

Owner decision (2026-07-25): once the 2026-07-25 feature wave and the
trial-end sweep / post-trial lifecycle work all land, do a dedicated
domain-boundaries sweep across `src/Module/`: check module-to-module
dependencies against the phparkitect rules, tighten or add boundary rules
where modules have grown entangled (Billing ↔ Account is the likely hotspot —
the trial-end work adds cross-module touchpoints: `UserRegistered` event,
`disabledAt` on User consumed by Billing, waitlist ↔ checkout), and move any
misplaced code to the module that should own it. Treat it as its own branch,
not a rider on feature work.

Audit input (2026-07-26, owner deferred these two findings here): the sweep
has no working gate today — `phparkitect.php` contains zero rules (only the
skeleton's commented example), so `just arkitect` passes vacuously. Writing
the rules is part of this sweep. Known cycles to break, confirmed in the
code: Project↔Review and Project↔SiteReview (`ListProjectsController.php`
and `CreateProjectController.php` import `DocumentRepository`,
`SiteReviewCommentRepository`, `SiteReviewEventRepository`), Account↔Project
(`HomeController.php`, `DeleteAccountHandler.php`), Account↔Billing
(`DeleteAccountHandler.php` imports `BillingProfileRepository` +
`StripeGatewayInterface`). The duplicated project-list count block in the
two Project controllers is the natural first extraction seam (one provider
service fixes the reverse edges and the 3-counts-per-project N+1 together).

## Review UI: clickable table of contents



**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner request (2026-07-25): the document review screen needs a clickable ToC.
Long specs (e.g. the trial-end sweep design, ~15 sections) currently require
scrolling to navigate; generate a ToC from the rendered document's headings
(anchor links, probably a sticky sidebar or collapsible panel) so a reviewer
can jump between sections while commenting.

## Review UI: per-section approval



**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner request (2026-07-25): alongside the whole-document verdict, let a
reviewer approve individual sections. During the trial-end sweep spec review,
each revision orphaned most open comments and there was no way to mark "these
sections are settled, only re-review the delta" — per-section approval state
(persisting across revisions when a section's content is unchanged) would make
multi-round spec reviews much cheaper. Interacts with the ToC item above
(section identity comes from headings) and with comment re-anchoring.

## Decide fate of PlaywrightSyncEmailMiddleware (async-email follow-up)



**Author:** Claude · **Type:** tooling · **Priority:** medium · **Status:** pending

src/Messenger/Middleware/PlaywrightSyncEmailMiddleware.php is unregistered AND its target `sync` transport is commented out in messenger.yaml; playwright.config.ts still sends the X-Playwright header solely for it. Either wire it properly (register middleware + uncomment sync transport) — which would make Playwright-headed requests deliver mail synchronously and remove the worktree-consumer requirement for mail-asserting e2e specs — or delete the class + header. Deciding beats letting it rot; the worktree-blind-mail item above is the forcing function.

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

## Gamache: ship the controller direct-state-access rule the skill cites



**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

The `project-command-handler` skill cites a `controller.directStateAccess`
gamache rule ("controllers may not touch repositories directly") that does
not exist anywhere in ubermuda/gamache (all five layers checked 2026-07-26),
while ~25 rendering controllers inject repositories and assemble view data
inline. Owner decision (2026-07-26): upgrade gamache — add the rule via a PR
on https://github.com/ubermuda/gamache (per CLAUDE.md, gamache rules never
land in this repo). Decide the rule's scope while writing it: forbid all
repository injection in controllers (forcing query handlers for reads,
matching the skill) or only mutation paths; then fix or baseline the ~25
existing controllers when the rule lands.

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
currently flows agent→app by the agent *polling* `get_site_review` over MCP,
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
disclosure per comment, and the MCP `get_site_review` payload exposes
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
in the agent-facing queries — do not. `GetSiteReviewTool` reads
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

## Registration should not ask for full name or username

**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner note (2026-07-28): the registration form collects Full name and Username
on top of email and password. Neither is needed to sign up — drop them and cut
the form to email + password (+ terms).

**No schema change is required, because the "don't ask" path already exists.**
`ResolveSocialLoginHandler` creates users without either field being supplied:
it mints a username via `UsernameGenerator::fromPreferred()` and falls back to
the email local-part for the display name. Self-service registration can use
the same derivation, leaving `username` and `full_name` as populated NOT NULL
columns and avoiding a migration. Removing the columns outright is a second,
optional step.

What each field is actually worth today:

- **`username`** is close to vestigial. `User::getUserIdentifier()` returns the
  **email**, so it is not the login handle; it survives as a unique column, a
  `findOneByUsername` lookup and the `NotReservedUsername` validator. Check
  whether anything user-facing still needs it before deciding to keep deriving
  one at all.
- **`fullName`** has real consumers, so it cannot simply vanish: the review
  byline (`@Review/show_document.html.twig`) and comment author names and
  avatar initials (`@Review/components/CommentThread.html.twig`) all render it.
  Deriving it from the email local-part keeps those working; showing the raw
  email there instead is a visible product decision, not a refactor.

Also decide whether the install wizard's admin form (`InstallAdminFormType`)
follows — it asks for the same two fields and has the same argument against
them. When the fields go, delete their orphaned `account.form.*` /
`account.registration.validator.username_*` translation keys in the same
change, per the `project-translations` skill: nothing flags unused keys and
they rot silently.

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
4. **Addressed comments become invisible to the agent.** `get_site_review`
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

## The worktree-scoped e2e worker OOMs instead of recycling

**Author:** Claude · **Type:** tooling · **Priority:** medium · **Status:** pending

The `project-worktrees` skill tells you to run a worktree-scoped consumer for
mail-asserting e2e specs:

    bin/worktrees/compose-exec.sh bin/console messenger:consume scheduler_default async

That command carries no limits, unlike the shared `worker` compose service,
which runs the same transports with `--time-limit=3600 --memory-limit=128M`.
Running in the **dev** environment, Doctrine's `BacktraceDebugDataHolder`
accumulates a backtrace per query for the lifetime of the process, so a
long-lived consumer climbs until PHP's 128M limit and dies with a fatal
`Allowed memory size of 134217728 bytes exhausted` (observed 2026-07-28 after
roughly an hour and several full e2e runs).

Why it matters more than a crashed side process: **the failure is silent and
its symptom is misleading.** Nothing consuming `async` means no mail is
delivered, and mail-asserting specs then fail on `getEmailWithSubject` timeouts
that look like application or Mailpit problems. A full suite that started green
can fail later in the same session for no reason visible in the diff.

A 3-minute suite finishes well inside the window, so this bites manual sessions
and back-to-back runs rather than a single gate. Fix is probably to document
(and use) the limits the compose service already applies — with the messenger
memory limit set *below* PHP's, e.g. `--time-limit=3600 --memory-limit=100M`,
so the worker stops gracefully between messages instead of dying inside one.
Worth deciding at the same time whether the skill should simply tell you to
restart it, since even a graceful exit leaves nothing consuming.

Related: 'Worktree e2e runs now require a worktree-scoped worker' and
'Decide fate of PlaywrightSyncEmailMiddleware (async-email follow-up)' — the
latter would remove the need for this consumer altogether.

## Social linking leaves a live email-verification link outstanding

**Author:** Claude · **Type:** security · **Priority:** medium · **Status:** pending

`ResolveSocialLoginHandler` (the match-by-email branch) and
`LinkSocialAccountHandler` both do `$user->emailVerifiedAt ??= new
\DateTimeImmutable();` when a provider proves ownership of an address, but
neither calls `clearEmailVerificationToken()`. So a user who registers through
the form — `VerificationEmailSender` generates and emails a token — and then
signs in with Google or GitHub before clicking is left verified with a working
link outstanding. `VerifyEmailHandler` never checks `isVerified()`, and
`VerifyEmailController` calls `Security::login()` on any valid token, so that
link still logs its bearer straight in.

`MarkEmailVerifiedHandler` now revokes such a token on every path, so
`app:user:verify` and `app:admin:create` clean it up — but only for an operator
who runs them. The fix at the source is to pair each `emailVerifiedAt ??=` with
`clearEmailVerificationToken()` in both social handlers, which also makes the
handlers' own "a pending click-through verification is superseded" comments
true rather than half-true.

Graded medium rather than high deliberately: the token expires an hour after it
is issued, and it was emailed only to the address the provider just verified
ownership of, so this is a stale credential outliving its purpose rather than a
path to another account. Re-grade if that reasoning does not hold.
## Data-export object storage has never run against a real bucket

**Author:** Claude · **Type:** tooling · **Priority:** medium · **Status:** pending

Data-export archives now go through a Flysystem storage (`export.storage` in
`config/packages/flysystem.yaml`), selected at runtime by `EXPORT_STORAGE`:
a local directory, or an S3-compatible bucket via
`league/flysystem-async-aws-s3`. Everything automated exercises the **local**
adapter — the unit and integration tests build a `LocalFilesystemAdapter`, and
dev, test and e2e all leave `EXPORT_STORAGE=local`. The S3 adapter has only
been proven to *wire up*: booting with `EXPORT_STORAGE=s3` constructs an
`AsyncAwsS3Adapter` over an `AsyncAws\S3\S3Client`, and nothing beyond that.

So no run has yet confirmed the parts that only a live bucket can answer:
that `DataExportArchiveBuilder`'s upload-to-`<key>.tmp`-then-`move()` really is
a server-side copy rather than a download-and-re-upload, that
`DownloadDataExportController` streams a `GetObject` body correctly at size,
that a missing object surfaces as a `FilesystemException` (a 404) rather than
some other failure, and that the two
provider-shaped knobs are right — path-style addressing
(`EXPORT_STORAGE_USE_PATH_STYLE`) and the canned ACL (`EXPORT_STORAGE_ACL`,
whose whole reason to exist is that `private` and `bucket-owner-full-control`
are each rejected by *some* provider).

Closing this means running one export end to end against a real bucket —
MinIO in compose is enough, and is closer to the self-hosting story than AWS —
and confirming the emailed link downloads a valid ZIP. Until then, treat
`EXPORT_STORAGE=s3` as configured-but-unverified, which matters because
`terraform/main.tf` makes it the **default** for the shipped deployment (see
"Known gaps" in `DEPLOY.md`).

## Review renderer: front matter renders as prose, HTML comments render as nothing

**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

A Markdown document that opens with a YAML front-matter block — the `---`
fenced key/value header that Hugo, Jekyll, Astro and most static site
generators put at the top of every source file — renders as garbage in the
review UI. The opening `---` becomes a horizontal rule, the keys become a
paragraph of `title: "…"` / `date: …` / `tags: […]` text, and the closing
`---` becomes a second rule. The reviewer sees three lines of noise before the
first real sentence, and can select and comment on them as if they were
content.

This surfaced on 2026-08-01 submitting blog drafts for review: the workaround
was to strip the front matter by hand before calling `create_document`, which
means the reviewed document no longer matches the file on disk. That is the
actual cost — a revision cannot be pasted straight back, and anything the
reviewer might want to say about the tags or the publish date has nowhere to
land.

**Decision needed** — what the renderer should do with a front-matter block:

1. Parse it and render it as a small metadata panel above the document
   (recommended: it is real content, and reviewers have opinions about titles
   and tags).
2. Parse it and hide it, keeping it in the stored source so a round-trip is
   lossless.
3. Detect it and strip it on ingest, which is the current manual workaround
   moved server-side — simplest, but throws information away.

Whichever is chosen, detection is the same: a leading `---` line, YAML up to
the next `---`, and nothing before it. Note that a document may legitimately
*begin* with a horizontal rule, so the parse must fail closed and treat an
unterminated block as prose.

HTML comments are the same bug with a worse failure mode, and belong in the
same fix. `<!-- … -->` renders as *nothing* — the reviewer sees no gap, no
placeholder, and cannot select or comment on it. Front matter at least looks
wrong; a hidden comment looks like clean prose. This bit on the same day: two
of the submitted blog drafts carry `<!-- TODO: link the skeleton repo here -->`
markers, which are exactly the open questions their author most wants a
decision on, and they are invisible in the review UI. Decide the same three
ways — surface as a visible annotation, keep but hide, or strip on ingest —
and prefer surfacing, since an author who wrote a comment into a document
meant it for a reader.

The two places this can live are `src/Module/Review/Mcp/CreateDocumentTool.php`
(ingest — where stripping or extraction would happen once, at submission) and
`src/Module/Review/Service/MarkdownRenderer.php` (render — where it would
happen on every view, and where the anchor offsets that comments depend on are
computed). Ingest is the better home for options 2 and 3; option 1 needs both,
since the panel has to render somewhere. Whichever is picked, check the effect
on comment anchor offsets before shipping — removing characters from the source
after comments exist would shift every anchor in the document.

## Documents have no organizing structure — tags, categories or something else

**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

A project's documents are a flat list ordered by creation. That is fine at five
documents and stops working well before fifty. Submitting a blog series on
2026-08-01 put seventeen related documents into one project — eleven posts, six
companion threads — with nothing expressing that the threads belong to the
posts, that both belong to one series, or that some are drafts and some are
outlines. The only structure available was baking it into the titles by hand
("Post 5 — …", "Thread 5 — …"), which is a naming convention pretending to be
a data model: nothing enforces it, nothing can filter on it, and it breaks the
moment a title is wrong.

**Decision needed** — what the organizing primitive should be:

1. Tags, many-to-many and free-form (recommended: cheapest to build, and
   "Dashboard document search + status/tag filtering" is already written
   against a tag entity that does not exist yet, so this unblocks work already
   scoped).
2. Categories or folders, one-to-many and hierarchical — better for a series,
   worse for documents that belong to several groupings at once.
3. Both, as most document tools end up doing.

Whichever is chosen, it needs to be settable from the MCP at `create_document`
time, not only in the UI — the agent submitting the batch is the one that knows
how the documents relate, and asking a human to tag seventeen documents
afterwards means it does not happen.

Related: "Reference another document from a document", which is the other half
of the same problem.

## Reference another document from a document

**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

There is no way for one document to point at another. A companion thread that
accompanies a blog post, an implementation plan that supersedes a spec, an
audit that references the design it audited — all of these currently say so in
prose, if at all, and the reader has to go find the other document by name.

Submitting the blog series on 2026-08-01 made this concrete: each thread
document opens with "Companion thread for post 4" as plain text, which the
reviewer cannot click, and which will be wrong if the post is ever renumbered.
A real reference would survive both.

Two things worth separating when this is designed. A **link** is one document
mentioning another and is cheap. A **relationship** is typed and directional
("thread-of", "supersedes", "audits") and is what makes a document list
navigable rather than just cross-linked. Start with the link; do not build the
typed graph before something needs it.

Worth checking how this interacts with revisions: a reference to a document
that is later revised should still resolve, and probably to the current
version rather than the one that existed when the link was written.

Related: "Documents have no organizing structure — tags, categories or
something else".

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
`Module/Review/Mcp/CreateDocumentTool` and `ReviseDocumentTool` are thin
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

## The tracker's own prose names MCP tools and classes that no longer exist

**Author:** Claude · **Type:** docs · **Priority:** medium · **Status:** pending

Every MCP tool was renamed with a feature prefix (`create_document` →
`document_create`, `get_review` → `document_get_review`, `get_site_review` →
`site_review_get`, and so on; the full mapping is in the `CHANGELOG.md` entry
for the `feat/mcp-scoped-authz` branch), and each tool class was renamed to
match its tool name (`CreateDocumentTool` → `DocumentCreateTool`,
`GetSiteReviewTool` → `SiteReviewGetTool`, …). Roughly 25 tool-name references
and 8 class-name references in this file still use the old spellings, so a
reader looking one up finds an identifier that exists neither on the server nor
on disk.

The renaming branch deliberately left them: this file is the one guaranteed
merge-conflict surface when several branches are in flight, and rewriting 25
lines across it would have collided with every sibling. The fix is a docs-only
commit straight to `main` once the current wave has merged.

No stale name is a heading any more, so this is a body-text pass only.

## Archiving a document stays a human action — no `document_archive` MCP tool

**Author:** Geoffrey · **Type:** docs · **Priority:** medium · **Status:** pending

Decided while building archiving (2026-08-02): the MCP gets no tool that sets
archive state, and the asymmetry is deliberate rather than an omission someone
should later "complete".

What the agent *can* do: `document_list` takes `includeArchived` and returns an
`archived` flag per row, and `document_get` reports `archived` too. So an agent
can see archive state and filter on it — enough to avoid revising a document
the human has put away, and enough to explain why one is missing from a
default listing.

What it cannot do is set it. Archiving is what decides which documents a human
sees on their own dashboard, which makes it curation rather than authoring, and
the party whose work is being reviewed should not be the party that can take a
document out of the reviewer's list. The sharp version: an agent that can
archive can make its own mistakes disappear.

`ArchiveDocumentHandler` and `UnarchiveDocumentHandler` are ordinary handlers
with no MCP tool wired to them, so exposing this later is a small change — but
it needs a decision reversing the one above, not just the observation that the
handlers already exist.

Related: "Edit a document in the app, not only through an agent", which is the
same question from the other side (what a *human* may do to a document the
agent authored).

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

## Re-rendering stored versions un-highlights comments without flagging them

**Author:** Claude · **Type:** bug · **Priority:** medium · **Status:** pending

`app:review:rerender-versions` (`RefreshDocumentVersionsHtmlHandler`) rewrites
`document_versions.rendered_html` and touches nothing else. Any renderer change
that alters `DocumentVersion::plainText()` can therefore leave a stored comment
whose quote no longer appears in the new text — and nothing notices. The browser
re-locates each anchor by quote and context (`comment_anchor_controller`'s
`#findRange`), so it simply adds no highlight; the comment stays in the sidebar
looking healthy, and `comments.orphaned` is only ever set later, by
`ReanchoringService`, when someone next revises the document.

Measured on seeded data while adding front-matter and HTML-comment rendering:
of four comments placed around the affected regions, two resolved cleanly
against the re-rendered text (shifted +2 and +35 characters) and two resolved to
nothing — one anchored on front-matter text that is now table cells, one whose
quote spanned the point where a previously invisible HTML comment now
contributes text. The re-render reported "1 of 3 versions" and left all four
`comments` rows byte-identical, `orphaned` still false.

**Mitigated, not fixed.** The command now inspects every version before writing
anything and refuses outright when any version carries an anchored comment whose
plain text the re-render would move, reporting the count and exiting non-zero.
Passing `--accept-comment-orphaning` proceeds anyway and still reports the count
as a warning. So the silent data problem is now a loud one — but the damage is
unchanged if the flag is passed, and the flag is the only way to re-render a
document whose rendering has legitimately changed.

Untargeted comments (empty anchor quote) are deliberately not counted: they are
never relocated, and an alarm that cannot come true is how an opt-in flag turns
into something people pass by reflex.

The real fix is still a reanchoring pass — resolve every open comment against
the new text and set `orphaned` where the quote is gone — so the damage is
recorded when it happens rather than surfacing at the next revision. Add a
`--dry-run` that reports the counts before writing, since that is what you want
before running a renderer migration. Once that lands, the refusal and its flag
should go away rather than being kept alongside it.

Two things make this more than it looks. `ReanchoringService::reanchor()` cannot
be reused: it builds *new* `Comment` rows against a *new* `DocumentVersion`,
whereas this needs an in-place update of the existing rows — a different
operation against the same `AnchorService::resolve()` predicate. And
`RefreshDocumentVersionsHtmlHandler` runs without a transaction, so a reanchor
pass has to wrap rewrite-plus-reanchor per version; a mid-run crash that left
new HTML beside stale anchors would be worse than today's uniform silent
de-highlight.

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

## Port Turbo prefetch convention to the skeleton



**Author:** Claude · **Type:** tooling · **Priority:** low · **Status:** pending

Turbo 8 prefetches links on hover, which silently fires the GET behind any
side-effecting link — we hit this with the `logout` link logging users out on
hover. Fixed here by adding `data-turbo-prefetch="false"` to the logout link and
documenting the convention in `.claude/skills/project-frontend/SKILL.md`
("Disable prefetch on side-effecting GET links").

The skeleton has **no logout link**, so there's nothing to fix literally — port
the *convention* instead: copy the SKILL.md note into the skeleton's
project-frontend skill so future consumers know to opt side-effecting GET links
out of prefetch. Open a PR against the skeleton (`main`), then update
`.skeleton.json`.

## Revisit: migrate API auth to Symfony's `access_token` authenticator



**Author:** Claude · **Type:** tooling · **Priority:** low · **Status:** pending

We hand-roll `ApiTokenAuthenticator` (custom `AbstractAuthenticator`). Symfony's
built-in `access_token` firewall + an `AccessTokenHandler` is the more idiomatic
mechanism. Deferred during the site-review work — decided to extend the custom
authenticator for now and revisit later. Note: `access_token` has **no** native
scope→role mapping (verified against current Symfony docs), so the migration is
a modernization, not a scope win; per-token scope roles are slightly more
awkward there (you don't own `createToken()`), so weigh that when revisiting.

## Site-review widget: send during an in-flight delete



**Author:** Claude · **Type:** bug · **Priority:** low · **Status:** pending

`send()` doesn't check `state.deleting` — a Send clicked while a delete is
in flight could submit a review that still contains the being-deleted comment.
Minor for a single-reviewer tool; track only.

## Site-review widget: surface per-comment save errors more granularly



**Author:** Claude · **Type:** feature · **Priority:** low · **Status:** pending

All widget API failures render into the single `#lp-error` banner. Fine for a
one-reviewer tool; if bulk operations ever appear, attach errors to the affected
list row instead.

## e2e tsconfig triggers TS5107 under bare tsc



**Author:** Claude · **Type:** tooling · **Priority:** low · **Status:** pending

`e2e/tsconfig.json` uses `moduleResolution: node` (node10), deprecated in
TypeScript 5.x — a bare `npx tsc --noEmit` in `e2e/` fails with TS5107. Nothing
in the gates runs bare tsc today (Playwright transpiles specs itself), so this is
latent. Modernize the tsconfig (`module`/`moduleResolution` `nodenext`, or
`bundler`) when convenient.

## Regenerate token handlers are check-then-set without locking


**Author:** Claude · **Type:** bug · **Priority:** low · **Status:** pending

`RegenerateProjectWidgetTokenHandler` and `RegenerateProjectMcpTokenHandler`
delete the previous token and persist a new one with no lock, so two concurrent
regenerations can leave the loser's token valid but bound to no project.

Both mint handlers are now guarded — `MintProjectWidgetTokenHandler` and, since
PR #66, `MintProjectMcpTokenHandler` — each taking a `PESSIMISTIC_WRITE` on the
project row and re-checking committed state through a repository query. Mirror
that shape here.

Note the mint fix deliberately avoids `EntityManager::refresh()`: it throws on
`Project::$createdAt`, which is `readonly`, which is why the committed-state
check is a repository query rather than a refresh.

Impact stays low — regeneration is a single-owner action, and an unbound token
resolves no project so project-scoped consumers reject it.

## Widget-token mint flow still uses site-era CSRF id and translation keys



**Author:** Claude · **Type:** tooling · **Priority:** low · **Status:** pending

`MintProjectWidgetTokenController` keeps CSRF token id `mint-site-token` and
the `site_review.site.token.*` translation-key family (template ↔ controller
pairs are consistent). The Connect page now owns the token UI, so these can be
renamed to `project.*` as a cosmetic follow-up (coordinated template + controller
+ csrf.yaml + xlf change).

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

## Worktree e2e runs now require a worktree-scoped worker



**Author:** Claude · **Type:** tooling · **Priority:** low · **Status:** pending

The manual procedure is documented in the `project-worktrees` skill
("Running the full e2e suite from a worktree"); what remains open is the
automation — a `just e2e` pre-hook or `just e2e-worktree` recipe owning the
worker lifecycle (see docs/AUTOMATIONS.md, 2026-07-25 section). Mind the
worker's --time-limit: a consumer that expires mid-run fails the export spec.
Related: "Decide fate of PlaywrightSyncEmailMiddleware".

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
entries? MCP tool the agent polls, like get_review?).

## Deduplicate the waitlist idioms (convert + invite-validation)



**Author:** Claude · **Type:** tooling · **Priority:** low · **Status:** pending

Two waitlist idioms have each hit the rule of three (as of 2026-07-26):

1. Convert-on-account-activity: `findOneByEmail(...)` → `null === convertedAt`
   → `markConverted()` in `RegisterUserHandler`, `ResolveSocialLoginHandler`,
   and `SyncStripeSubscriptionHandler`. Fix: a
   `WaitlistEntryRepository::convertMatching(string $email): void` helper or
   an idempotent `markConverted()`.
2. Invite validation: token lookup + email match in `StartCheckoutHandler`
   and `ShowSubscribeHandler` (verbatim twins), near-verbatim (plus
   lock/refresh) in `RegisterUserHandler::resolveMatchingInvite`. Fix: a
   `WaitlistEntryRepository::findValidInviteFor(string $token, string $email):
   ?WaitlistEntry` collapses the Billing copies and serves the register
   handler's pre-lock lookup.

Candidate for the domain-boundaries sweep (see "Domain boundaries sweep
(after the current feature wave)").

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

## Clear the Symfony 8.1 deprecation notices


**Author:** Claude · **Type:** tooling · **Priority:** low · **Status:** pending

Surfaced by a Codex review during the 2026-07-27 audit wave, while warming the
prod cache. The Symfony 8.1 upgrade (PR #63) left three deprecations firing at
container-build time. They do not fail any gate today, but they are removals
scheduled for the next majors:

- `Symfony\Component\HttpKernel\DependencyInjection\Extension` is deprecated in
  favour of `Symfony\Component\DependencyInjection\Extension\Extension`. Raised
  through `symfony/mercure-bundle`'s `MercureExtension`, so this one clears when
  that bundle updates — not ours to fix, worth re-checking on its next release.
- `Symfony\UX\Turbo\Bridge\Mercure\TurboStreamListenRenderer` and
  `Symfony\UX\Turbo\Twig\TurboStreamListenRendererInterface` are deprecated
  since Symfony UX 3.1 and removed in 4.0, in favour of
  `MercureStreamSourceRenderer` with `turbo_stream_from()` or the
  `<twig:Turbo:Stream:From>` component.

The ux-turbo pair is the one with a migration path we own. Note browser-side
Mercure turbo streams are currently disabled in `assets/controllers.json` — the
only subscriber is the Go bridge — so check whether anything actually renders a
stream-listen tag before migrating, and whether the deprecation is reachable at
all beyond container build.

## Set a real INSTALL_TOKEN value in the production deploy config

**Author:** Claude · **Type:** security · **Priority:** medium · **Status:** pending

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

## One user-facing list query is still unbounded

**Author:** Claude · **Type:** bug · **Priority:** low · **Status:** pending

PR #79 paginated the projects list and the per-project documents list. The MCP
`list_documents` tool (`Mcp/ListDocumentsTool`) has since gained its own
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

## Document review: render selectable radios and checkboxes for decision points

**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner idea (2026-07-27): let the review UI render selectable controls for
decision points, so a reviewer picks an option instead of writing a comment
saying which one they want. Proposed authoring syntax, produced by the agent in
the document markdown and handled by Loupe from there:

- `- ( )` renders a radio (single choice within its group)
- `- [ ]` renders a checkbox (multi-select) — this is already GFM task-list
  syntax, so it round-trips through other markdown tooling

Why it matters: every design document currently ends in decision points the
reviewer has to answer in prose. The `loupe-documents` skill tells agents to
format them as a "**Decision needed:**" lead-in plus a numbered sub-list
precisely so a reviewer can write "option 2" in a comment. That works, but it
makes the reviewer transcribe a choice the document already enumerated, and the
agent then has to parse intent back out of free text. A worked example already
exists: the 2026-07-27 site-review design document carried three such decisions,
each answered in a comment and then written back into the document by hand. That
round trip is what this would remove.

Questions to settle when picking this up:

1. How a radio group is delimited — consecutive `- ( )` items, or an explicit
   fence. Consecutive-run detection is simpler to author but ambiguous when two
   groups sit back to back.
2. How a selection is stored. It is document state, not comment state, but it
   belongs to a version — decide whether selections carry across a
   `revise_document` the way comments re-anchor, or reset per version.
3. How the agent reads the result. `get_review` returns verdict plus threaded
   comments today; selections need a place in that payload, or a companion
   tool.
4. Whether a selection needs an accompanying comment for the "why", and
   whether selecting should be able to resolve the thread attached to that
   passage.
5. What happens to the `loupe-documents` skill guidance, which should switch to
   teaching the new syntax once this ships.

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

## Unset optional config should disable a feature, not break it

**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

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

## Sessions, cache and rate-limiter storage are container-local

**Author:** Claude · **Type:** bug · **Priority:** medium · **Status:** pending

Found by the self-hosting audit (2026-07-28); the owner accepted the current
behaviour for now and asked for a note.

`config/packages/framework.yaml` leaves `handler_id` unset, so sessions are
native files in the cache directory; `config/packages/cache.yaml` keeps the
filesystem app cache; and the rate limiters in the same `framework.yaml` are
backed the same way. Two consequences, neither documented: **every deploy logs
all users out**, because the cache directory does not survive a new container;
and with more than one web replica, sessions and rate limits are per-replica,
so the per-IP registration and password-reset limits are effectively multiplied
by the replica count.

The whole configuration therefore assumes a single web replica and tolerates
session loss on deploy. That assumption is invisible to an operator reading
`DEPLOY.md`.

Fix when it becomes worth it: `PdoSessionHandler` for sessions and a
Doctrine-backed pool for the rate-limiter storage. Postgres is already a hard
dependency, so this adds no new infrastructure. Until then, `DEPLOY.md` should
say out loud that the app expects one web replica.

## No backup and restore guidance for self-hosted installs

**Author:** Claude · **Type:** docs · **Priority:** medium · **Status:** pending

Found by the self-hosting audit (2026-07-28); the owner decided backups are the
operator's responsibility for now, so this entry is about saying so rather than
about building anything.

Nothing in any markdown file mentions backup, restore or `pg_dump`. An operator
gets no statement of what constitutes the durable state of an instance, and two
parts of that are genuinely non-obvious:

1. Losing `APP_ENCRYPTION_KEY` makes every encrypted column permanently
   unreadable. `DEPLOY.md` mentions this once, inside the secrets section,
   where someone planning backups will not look for it.
2. Data-export archives currently live on container-local disk, so they are not
   covered by a database dump. This changes once exports move to object storage
   — see the self-hosting audit's decision on export storage — at which point
   the bucket becomes the second thing to back up.

Close this by adding a short "Backing up" section to `DEPLOY.md`: what to dump,
what else holds state, that restore has never been rehearsed, and that the
operator owns the schedule.

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

## MCP: an agent cannot highlight a passage in a document

**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Anchoring is one-directional. A human reviewer selects text in the review UI and
their comment carries the quote, so `get_review` hands the agent an exact
passage to act on. The agent has no equivalent: `create_document` and
`revise_document` take whole-document Markdown and nothing else, so an agent
that wants to point at one sentence can only describe where it is ("line 15",
"the third paragraph"). The reader then has to find it by hand, and the
reference goes stale as soon as the document is revised.

Surfaced on 2026-08-01 while revising a blog post through the MCP: reporting
which sentences had been rewritten, and which were deliberately left alone,
needed line numbers that mean nothing in the rendered review UI.

Wanted: a way for the agent to attach a highlight to a quoted span. **The
purpose is directing attention** (owner clarification, 2026-08-02): the agent
marks the passages it judges most important so the reviewer reads those first,
rather than flagging its own uncertainty. On a long document that is the
difference between a reviewer starting where it matters and starting at the top.

That settles the design question this entry used to leave open. A highlight is
**a separate, lighter annotation with no thread** — not an agent-authored
anchored comment. It carries no body, expects no reply, and does not belong in
the comment ladder. It therefore does not depend on "MCP: no way to reply to
document-review comments", though both need the agent to be able to write into a
review.

Most of the machinery is already there and unused. `comment_anchor_controller.js`
re-locates a quote in the live DOM from anchor context (`#findRange`), and its
`STATUS_HIGHLIGHTS` map plus the `::highlight()` rules in `app.css` already
register and style rungs that no template emits. An emphasis highlight needs its
own rung rather than borrowing the `addressed` one, whose meaning is different,
but it needs no new highlight mechanism. Note `::highlight()` honours only
colour, background-colour and text-decoration, which caps how a highlight can
look without mutating the DOM — and mutating it is not an option, because the
document pane's `textContent` must stay identical to `DocumentVersion::plainText()`
or every anchor offset shifts.

Related: "Review comments should be able to express an edit, not just describe
one".

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

## "Unreachable from MCP by design" describes a guard that is not the one in force

**Author:** Claude · **Type:** security · **Priority:** medium · **Status:** pending

`AddressSiteReviewCommentsTool`'s class docblock says "The agent's only write:
Pending → Addressed. Resolved is reserved for the human in the web UI and is
unreachable from MCP by design." The property holds today, but not for the
reason a reader would infer, and the gap matters the next time someone adds a
tool.

An MCP request is authenticated **as the project owner**:
`ApiTokenAuthenticator` builds its passport with
`new UserBadge($token->owner->getUserIdentifier(), fn () => $token->owner)` and
grants `[...$user->getRoles(), $scopeRole]`. `SiteReviewCommentVoter`'s entire
rule is `$subject->project->owner === $token->getUser()`. So the voter **would
grant** `site_review_comment.resolve` to an MCP-token request. It is not what
stops it.

What actually stops it is two things the docblock does not mention: no MCP tool
calls the resolve path, and `ApiTokenAuthenticator` is registered only on the
`mcp` and `api` firewalls, so a Bearer token cannot authenticate against
`/site-review/comments/{id}/resolve` on the `main` firewall at all — a route
that additionally carries a session-backed `#[CsrfToken]`.

The risk is a future tool that calls a voter and reads the result as a
meaningful check. Every ownership-based voter in the app returns true for an
MCP request by construction. Fix the comment to name the real guard, and
consider whether ownership voters should be unreachable from tool context
rather than merely unused there.

## No audit trail distinguishes agent-written state from human action

**Author:** Claude · **Type:** security · **Priority:** medium · **Status:** pending

There is no audit entity, no audit table and no dedicated Monolog channel —
`config/packages/monolog.yaml` declares only `deprecation`. What exists is
scattered `LoggerInterface::info` calls, and of every review-related write only
`review.document.verdict_submitted` records an actor at all. The MCP write in
`AddressSiteReviewCommentsTool` logs nothing.

This is becoming load-bearing rather than merely untidy, because the agent is
gaining writes: a singleton agent `User` authoring comments, a reply tool and a
mark-addressed tool. Attribution on a `Comment` covers those. It does not cover
`Review`, which requires a non-nullable `reviewer: User` — and since an MCP
request authenticates as the project owner, any agent-written `Review` would be
byte-for-byte identical to one the owner clicked.

`SiteReviewEvent` looks like an event log and is not one: it is a Mercure
delivery outbox recording deliveries rather than decisions, and it carries no
actor.

Worth deciding what the app needs before something writes state that cannot be
attributed afterwards — a real audit record, or at minimum an actor field on
every write that a human could be blamed for.

## Addressed site-review comments disappear from the MCP

**Author:** Claude · **Type:** bug · **Priority:** medium · **Status:** pending

`GetSiteReviewTool` calls `SiteReviewCommentRepository::findPendingForProject`,
which filters on `status = Pending`. So the moment an agent marks a comment
addressed through `address_site_review_comments`, that comment's id is returned
by no MCP tool at all.

The agent therefore cannot re-read what it addressed, report on it, or act on it
in a later session — its own write makes the record unreachable. Anything that
needs to enumerate previously-addressed comments is blocked by this, including
the parked PR-driven half of 'Let the agent close the loop when a human approves
the work'.

The fix is a read path that can return non-pending comments — either a status
filter parameter on `get_site_review` or a companion tool. Note the document
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
  therefore inert in diff mode — no toolbar, no composer, no highlight painting
  — reusing the same `readOnly` mechanism that already disables writes when
  viewing an older version.
- The diff renderer must emit segments tagged unchanged, inserted and deleted,
  so that **either side's plain text can be reconstructed from the diff markup**:
  unchanged plus inserted yields the new version, unchanged plus deleted the
  old. That is what gives a comment made in diff mode a well-defined anchoring
  basis to resolve against.

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

## Agent-written comments have no per-agent provenance

**Author:** Claude · **Type:** feature · **Priority:** medium · **Status:** pending

Every comment written through the MCP is authored by one global agent `User`
(`App\Module\Account\Entity\User::AGENT_ID`, inserted by
`migrations/Version20260803000402.php`). One account for the whole instance, not
one per project — so two projects, two API tokens, and two different agents all
produce replies that are byte-for-byte indistinguishable in the thread and in
`document_get_review`. A reader can tell "an agent said this" and nothing more.

This was the deliberate choice when `document_reply_to_comment` shipped: the
alternative, a per-project or per-token agent account, multiplies rows in the
`users` table for a distinction nobody had asked to see yet, and every count and
sweep that must skip the agent would have to skip a set instead of an id.

If provenance is ever wanted, the shape is a **nullable `ApiToken` reference on
`Comment` alongside the existing non-nullable `author`** — the token already
carries a name and a project binding, so it identifies which credential wrote
the reply without inventing an identity. Attribution stays on the singleton user
and provenance rides beside it. Related: 'No audit trail distinguishes
agent-written state from human action'.

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
`status-check-badge-{{ state }}` and similar), leaves 25 that are referenced
nowhere:

```
lp-doc-list  lp-doc-row  lp-doc-row__main  lp-doc-row__meta  lp-doc-row__tags
lp-doc-row__title  lp-doc-row__title--stretched  lp-page  lp-page-header
lp-page-title  lp-section-title  lp-table  lp-tag  lp-select  lp-code
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

## `site_review_get` reveals whether a site name exists

**Author:** Claude · **Type:** security · **Priority:** low · **Status:** pending

`SiteReviewGetTool` (`src/Module/SiteReview/Mcp/SiteReviewGetTool.php`) answers
its optional `site` argument with two different messages: `No site "%s" found.`
when the lookup misses, and `Token is not bound to that project.` when it hits
but is not the bound one. That difference tells a caller which site names exist
— the kind of existence oracle `ReviewSubjectResolver::requireDocument()`
returns one message for, on purpose.

Minor because the lookup is already narrowed to the token owner's own projects
(`ProjectRepository::findOneByIdOrNameForOwner()`), so a caller can only probe
names it is entitled to see. The fix is to collapse both branches onto a single
message the way the document resolver does.

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

## Nothing tests the anchor capture path from browser to database

**Author:** Claude · **Type:** tooling · **Priority:** medium · **Status:** pending

Every anchoring test builds an `Anchor` object by hand and hands it straight to
`AnchorService` or `ReanchoringService`. Nothing exercises the path the data
actually travels: a DOM selection in `comment_anchor_controller.js`, three hidden
form fields, `AddCommentFormType`, `AddCommentRequest`, `AddCommentHandler`, then
the row. Every test therefore starts from data that is already correct by
construction, which is exactly the shape that cannot catch corruption occurring
*before* the service sees it.

That is not hypothetical — it is how one bug survived from the feature shipping
until 2026-08-02. Symfony's form `trim` option defaults to `true` and
`HiddenType` inherits it, so the boundary whitespace was being stripped off every
captured `prefix` and `suffix`. `contextScore()` compares the last 8 characters
of the stored prefix against the document: the document reads `…ains a ` before a
quote and the trimmed fingerprint was `…ins a`, which can never match. Context
disambiguation had been silently scoring zero and falling back to
earliest-position for every selection whose neighbouring character is whitespace
— which is nearly all of them. The whole unit suite passed throughout.

Two things worth doing, and the second is cheap:

- Add tests that bind through the real Form component rather than constructing
  the DTO. Two now exist in `AddCommentHandlerTest` (the ones that caught the
  trim), but as specific regressions rather than as coverage of the path.
- Have `e2e/tests/review/review-loop.spec.ts` assert the stored **prefix and
  suffix** through `/dev/review/{id}/state`, not only the quote. That run caught
  the trim bug only by luck: word-edge snapping happened to make the corruption
  visible in the one field the spec already asserted. Had snapping not shipped in
  the same branch, the suite would still be green and the anchors still wrong.

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
