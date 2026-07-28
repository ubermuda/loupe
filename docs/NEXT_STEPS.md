# Next steps

Open work and observations worth revisiting. Delete items entirely once resolved.

Entries are ordered by priority (high → medium → low); insert new entries at
the end of their priority band. Format and rules: `project-next-steps` skill.

## MCP: no way to reply to document-review comments



**Author:** Claude · **Type:** feature · **Priority:** high · **Status:** pending

Found while dogfooding the nine-features design review: the MCP exposes
`get_review` (which returns each comment's `thread`) and `revise_document`, but
nothing that *writes* to a review thread. An agent can read comments and submit
a new version, yet cannot reply to a comment, push back on one, or mark one
addressed — `address_site_review_comments` covers only site-review widget
comments. The review conversation is one-directional; the agent's side has to
happen out-of-band. A `ReplyToCommentHandler` now exists
(`src/Module/Review/Command/ReplyToCommentHandler.php`), but it is wired only
to a web-UI controller (`ReplyToCommentController`) — there is still no MCP
tool, so an agent still cannot reply through the MCP surface. Add a
`reply_to_comment` MCP tool (and consider `mark_comment_addressed`) for
document reviews.

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

## Replace explicit document/site-review state with computed state

**Author:** Geoffrey · **Type:** feature · **Priority:** high · **Status:** pending

Owner decision (2026-07-27, via site review): documents and site reviews
should not carry an explicit stored state at all. The only state the product
exposes should be derived from the comments themselves — for a site review,
things like "has drafts" / "has addressed comments"; for a document, "has
addressed comments".

The four-step loop ribbon (Proposed → In review → Revise → Approved) and the
`LoopStage` enum that fed it were the visible half of that system, and are
already gone. What remains is the persisted half:

- `App\Module\Review\Entity\DocumentStatus` and the `status` column on
  `Document`, set by `SubmitReviewHandler` (from the review verdict) and reset
  to `InReview` by `ReviseDocumentHandler`.
- The status badge on the documents list (`@Review/list_documents.html.twig`)
  and the `document.status.*` translation keys.
- The `status` field in the MCP/export payloads — `GetDocumentTool`,
  `ListDocumentsTool`, `GetReviewTool`, `GetReview`, `DocumentExporter`.

Closing this means designing the computed predicates, migrating the column
away, and deciding what the MCP contract exposes instead — agents currently
read `status` to decide whether a document still needs work, so it needs a
replacement, not just a deletion. `e2e/tests/review/review-loop.spec.ts`
asserts the badge and will need rewriting alongside.

## Dashboard document search + status/tag filtering



**Author:** Claude · **Type:** feature · **Priority:** medium · **Status:** pending

Deferred from the SaaS visual redesign (visual-only phase). The dashboard mockup
envisioned a search box + status filter + tag filter, but `autosearch_controller`
submits to the server, so making search real needs backend work: a query param on
the documents controller and a repository filter (title contains, status equals,
tag in). Tag filtering further depends on the not-yet-built tag entity. When tags
land, wire search + status + tag filters into the existing `.bp-doc-list` header.

## Make `revise_document` surface real errors instead of generic `-32603`



**Author:** Claude · **Type:** bug · **Priority:** medium · **Status:** pending

Partially addressed: `ReviseDocumentTool` now maps its known failure modes —
an invalid document UUID, `DocumentNotFound`, and oversized markdown — to
`ToolCallException` with a useful message. Still open: the call to the
handler itself (`($this->handler)(...)`) has no catch-all around it, so any
unmapped failure (e.g. a DB exception) still propagates unwrapped and the MCP
layer flattens it to `-32603 Error while executing tool` with no detail.
Consider a catch-all around the handler call that maps anything else to a
generic `ToolCallException` too, so clients always get a real message.

## Anchor offset-unit mismatch (latent)



**Author:** Claude · **Type:** bug · **Priority:** medium · **Status:** pending

`AnchorService` now slices UTF-8-safely (`mb_strcut`), but offsets are still
byte-based (`strpos`/`strlen`/`offsetHint`) while the frontend computes selection
offsets in JS string units (UTF-16 code units). For documents with multibyte
characters this can mis-place an anchor by a few positions even though it no
longer crashes. If anchors drift on real content, reconcile the offset unit
end-to-end (characters throughout, or bytes throughout).

## Site-review: harden Mercure publish against a hung hub (still open)



**Author:** Claude · **Type:** bug · **Priority:** medium · **Status:** pending

`SubmitReviewHandler::publish()` makes a synchronous HTTP call to the Mercure hub
after `flush()`. `symfony/mercure-bundle` exposes no per-hub `http_client` config
option, so the call uses the default HttpClient timeout — a hung hub could add
latency to every review submit before the catch fires. Wire the default hub to a
scoped HttpClient with a low `timeout`/`max_duration` (custom hub service or a
decorated HttpClient) so a slow hub can never noticeably delay a review submit.

Observability around it has since improved — the failure is logged at `error`
with the project id and topic, and the update carries a project-scoped
monotonic `sequence` as a stable event id — but the latency problem itself is
untouched. The publish is deliberately **not** retried: the hub may accept an
update and still have the client throw, but a duplicate publish is harmless —
the nudge carries no payload beyond its type, and a redundant pull via
`get_site_review` just finds nothing still `Pending`.

## Durable review-event delivery (transactional outbox)


**Author:** Claude · **Type:** feature · **Priority:** medium · **Status:** pending

The durable half is implemented: PR #77 merged, so a `SiteReviewEvent` row is
persisted in the same transaction as the Draft→Pending flip, and a publish
that fails no longer loses the event.

The earlier blocker recorded here — that automatic redelivery would make the
bridge inject the same review into the agent twice — no longer applies: the
nudge is payload-free and idempotent by design (a redundant pull via
`get_site_review` just finds nothing still `Pending`), and
`cli/internal/transport/mercure.go` now tracks the `id:` line and sends
`Last-Event-ID` on reconnect.

What genuinely remains: nothing drains or retries unpublished
`SiteReviewEvent` rows. `SiteReviewEventRepository` has no query for them
today (only `countForProject`) — replay is manual. Add one (e.g. "unpublished,
older than N", so a transient hub outage self-heals) and a worker on the
existing Messenger scheduler transport to drain it.

That query **must** also filter on `SiteReviewEvent::$forwardable`. A row is
written for every submit, including those from collect-only widget tokens
whose review is deliberately never forwarded (`ApiToken::$forwardsToAgent`),
so `publishedAt IS NULL` alone does not mean "still owed to the agent" —
draining on that condition would deliver exactly the reviews the opt-in
exists to withhold.

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

## MCP: `revise_document` cannot update the title



**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Found while dogfooding the nine-features design review: the review scope grew
from eight to nine features, but `revise_document` only accepts `markdown` —
the title set at `create_document` time is frozen. The document is now titled
"Eight features — design spec" while its content says nine. Add an optional
`title` parameter to `revise_document` (and consider surfacing title history
alongside version history).

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

## Admin users need a visible link to /admin from the app



**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner note (2026-07-25): nothing in the app UI links to the admin area — an
admin has to type /admin by hand. Add a nav entry (sidebar or account menu)
rendered only for ROLE_ADMIN (`is_granted('ROLE_ADMIN')` in Twig per the
symfony-authorization skill). The reverse link exists (the admin-bundle shell
links back to the app via its app_route config); only app → admin is missing.

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

## Review anchoring — possible enhancement (low priority)



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

Minor/cosmetic: the `quote` returned by `get_review` uses arbitrary character
boundaries (mid-word, mid-sentence), which makes mapping a comment back to its
section take some inference. Snapping quote boundaries to word/line edges would
read more cleanly. Cosmetic only.

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

## Resolution state is denormalised across every comment in a thread



**Author:** Geoffrey · **Type:** bug · **Priority:** low · **Status:** pending

Owner note (2026-07-26, reviewing the resolved-threads fix): would it be
simpler if `resolved` lived on the thread rather than on each message inside
it?

Today `resolved` is a column on `Comment`, so every row in a thread carries its
own flag. `ResolveCommentHandler` cascades the root's value to its direct
replies, because `findOpenByVersion()` selects on `resolved = false` and
unresolved replies of a resolved thread would otherwise be copied onto the next
version as fresh top-level threads. The alternative shape — leave the flag on
the root only and have the open-set query exclude comments whose thread root is
resolved — models it as a thread-level property and removes the duplication.

Not urgent: there is no UI path to resolve a reply independently (the Resolve
button renders only on the root in
`templates/Module/Review/components/CommentThread.html.twig`), so the replies' own flags are
redundant rather than contradictory. It becomes a real problem if per-message
resolution is ever added, at which point the two representations can disagree.

Touches `findOpenByVersion()`, the re-anchoring copy in `ReanchoringService`,
and the `resolved` field in the `get_review` MCP payload — replies of a
resolved thread currently report `resolved: true` there, which is an
externally-visible contract.

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
Until that happens, `/install` 404s in prod, which blocks creating the first
administrator rather than exposing one. Track this as a pre-launch deploy
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
