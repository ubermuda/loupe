# Next steps

Open work only. Delete items entirely once resolved.

Observations, decisions and reference notes do not belong here — they live in
the relevant skill or in `docs/`. An entry that asks nothing of anyone is not a
tracker entry.

Entries are ordered by priority (high → medium → low); insert new entries at
the end of their priority band. Format and rules: `project-next-steps` skill.

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
   having no ACLs at all. See `docs/known-gaps.md`.
2. **The missing-object path is code-verified, not run.** `AsyncAwsS3Adapter`
   throws `UnableToReadFile` and `DownloadDataExportController` catches
   `FilesystemException` and 404s, so the path is sound by inspection — but no
   run has deleted an object and requested its link.

Closing this means one export against a real Spaces bucket with the ACL
Terraform sets.


## The authenticated app has no mobile layout — deferred by decision, not overlooked

**Author:** Geoffrey · **Type:** bug · **Priority:** high · **Status:** pending

A mobile usability audit on 2026-08-13 measured every surface at a 375px
viewport against the running dev app. Full report is in Loupe:
https://loupe.dev.localhost/projects/019fde6f-6b71-781e-a358-bf44c8cf3c2f/documents/019ffbf4-9009-7e55-92e8-45dbcb0c9ec9/review
Every finding is stated numerically there, so nothing depends on the
screenshots: those were verification aids written to the gitignored
`var/mobile-audit/` and are not retained. Re-measure at 375px rather than
hunting for the captures.

Read this as a standing choice. Asked how far mobile support for the
authenticated app should go, the owner chose to defer rather than to build it or
to declare the app desktop-only. So the items below are open *by decision*, and
a future session should not treat them as a backlog miss and quietly start on
them. The auth flow and landing page were fixed separately (PR #179).

The structural fact behind all of it: before PR #179, `assets/styles/app.css`
contained 13 responsive variants in 3,027 lines and all 13 sat inside the
`.lp-landing-*` block (lines 2562–2886). Nothing else in the stylesheet, and no
template, had any responsive treatment. Measured consequences at 375px:

- `.lp-sidebar` is a fixed 236px that never collapses, leaving `.lp-main` 129px
  wide on every authenticated screen (the `<aside>`/`<main>` pair in
  `templates/base.html.twig`, `.lp-sidebar` in `app.css`).
- `.lp-review-block` is a fixed 972px (`w-243`, not a max-width) with the
  comment margin absolutely positioned at `left-169`, so on the review screen
  139px of the 640px prose column is visible and comments start 537px off-screen
  (`app.css`, `templates/Module/Review/show_document.html.twig`).
- The comment flow binds only `mouseup`/`mousemove`/`mouseenter`; there is no
  `touchend`, `pointerup` or `selectionchange` handler in
  `assets/controllers/comment_anchor_controller.js`, so text selection likely
  cannot trigger the comment toolbar on touch. Needs confirming on real hardware.
- The review topbar sits outside the `.lp-main` scroll container, so it widens
  the whole page to 517px and the verdict buttons overprint the breadcrumb
  (the topbar block in `templates/Module/Review/show_document.html.twig`).
- Four controls are 14px and will trigger iOS Safari's zoom-on-focus:
  `.lp-comment-composer textarea`, `.lp-comment-reply-form textarea`,
  `.lp-filter-input`, `.lp-filter-select` (all four in `app.css`).
  `.lp-input` and `.auth-input` are already correct at 16px.

One cost of deferring rather than declaring the app desktop-only: a phone
currently gets a broken layout instead of an honest "not supported here" notice.
If this stays deferred for long, that notice is the cheap interim step.

## No database restore has ever been rehearsed

**Author:** Claude · **Type:** tooling · **Priority:** high · **Status:** pending

Nobody has restored a Loupe database from a dump, on either deployment path.
`docs/operating/restoring.md` is a runbook written from how the stack is built,
not from a drill somebody ran, and both it and `docs/operating/backups.md` say
so. An unrehearsed restore is not a backup; this entry closes by proving one on
a scratch instance and correcting whatever the runbook gets wrong.

The single-host stack now takes dumps: the `backup` service in
`docker/compose/prod.yaml`, behind `--profile backup`, runs
`pg_dump --format=custom` on an interval, uploads to S3-compatible storage and
prunes past a retention window. What remains untested is putting one back.

The DigitalOcean path is still uncovered: `terraform/main.tf` provisions a
managed cluster, so DigitalOcean's own daily backups apply, but nothing adds an
independent copy or a retention window beyond the platform default. There is no
second location and no second custodian, and the compose backup job does not run
there.

Found by an audit on 2026-08-20.

## `digitalocean_app` shows a perpetual diff, so every apply redeploys

**Author:** Claude · **Type:** tooling · **Priority:** low · **Status:** pending

`terraform plan` never reaches "no changes" against a live deployment. It
reports the app as updated in place every time, with ten `env` blocks removed
and ten re-added (the same ten, reordered — the provider treats them as a set
and does not stabilise the order), `job.instance_count` drifting `1 -> null`,
and the `image` blocks re-rendering.

Nothing is wrong with the result and no value actually changes, but each apply
consequently rolls a fresh deployment, so an apply is never free and `plan` can
no longer be used to answer "is anything outstanding". This matches the upstream
report at
https://github.com/digitalocean/terraform-provider-digitalocean/issues/1075.

Observed on provider 2.99.1. Not established whether 2.93.0 was clean — the
lockfile was upgraded mid-deploy, so the two were never compared on the same
state. Worth checking before anything more elaborate: if it is a regression, the
fix is pinning rather than chasing the provider.

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

Owner decision (2026-09-04): an ADR is written on judgement. No class of change
requires one.

Write the ranked list of architectural priorities first. It is the cheaper
artifact, and it changes agent behaviour immediately.

## A better framework for planning and running multi-branch waves


**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** parked

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

Per-agent containers do not remove most of this. They remove the exclusive e2e
slot, and the hand coordination that keeps a sibling's `just ci` off an
in-flight gate. Costs 2, 3 and 4 above survive containers, because merge
ordering is a property of git and of the gate protocol. 'Give each agent its own
container in the cloud instead of sharing one dev stack' is parked as well, so
waiting on it means building nothing.

The cheapest restart is a shared findings log: one committed file that every
agent reads at start. It answers cost 3 directly, and it needs no new tooling.
The owner parked it rather than start it, for one reason worth recording. Such a
log overlaps `docs/NEXT_STEPS.md` and the per-project memory files. A second
committed file that competes with those has a real cost, and nobody has priced
it.

## Shrink the e2e suite and push its assertions down to functional tests


**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

The e2e suite is 26 spec files carrying 92 tests, and cannot be parallelised
(shared Mailpit), so it is a serial gate every branch queues behind. Most of it
is asserting things that do not need a browser.

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

Owner decision (2026-09-04): route deprecations to their own always-on file,
independent of the `fingers_crossed` main handler. The `when@prod` block already
has that split. It declares a `deprecation` stream handler on the `deprecation`
channel, and its `main` handler excludes that channel. Mirror the same shape in
`when@test`.

Verify the change like this. Reinstate a `nullable: false` on a many-to-many
join column, then confirm the deprecation still appears. A logging change that
passes its own tests while removing the ability to answer "did this not happen"
has made things worse.

Rotation or per-run truncation is still worth doing on its own. One checkout's
`var/log/test.log` reached 181 MB on 2026-09-04.

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

Known cycles to break, re-confirmed in the code on 2026-08-17: Project↔Review and
Project↔SiteReview (`Project/Command/ListProjectsHandler.php` imports
`Review\Repository\DocumentRepository` and
`SiteReview\Repository\SiteReviewCommentRepository`), Account↔Project
(`Account/Command/ShowHomeHandler.php`, `DeleteAccountHandler.php`), and
Account↔Billing (`DeleteAccountHandler.php` imports `BillingProfileRepository` +
`StripeGatewayInterface`). The duplicated project-list count block is the natural first
extraction seam — one provider service fixes the reverse edges and the
3-counts-per-project N+1 together. The shape to copy is
`UserDataExporterInterface`, which already solves exactly this: a
`ProjectStatsProviderInterface` declared in Project and implemented by Review and
SiteReview reverses both edges without Project naming either module.

Three specific misplacements to fix while here, each confirmed at source:

- **Account reaches into Project to clear tokens.** `Account/Command/RevokeApiTokenHandler.php`
  nulls `Project.widgetToken` and `Project.mcpToken` directly, with a comment
  acknowledging it. Emitting an `ApiTokenRevoked` event, the way `ProjectDeleting`
  already works, keeps the write inside the module that owns the field.
- **The home page lives in Account but is about Projects.** `Account/Command/ShowHomeHandler.php`
  and `ShowHomeView.php`.
- **Project depends on the two modules that depend on it** — the `ListProjectsHandler`
  imports above.

The diagnostics report is the worked example of the target shape: the
aggregation lives in `ubermuda/health-check-bundle`, imports nothing from any
module, and Billing and Account contribute their own tagged checks to it.

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

`site_review_comments` now has a nullable `strokes` JSON column, added by the
multi-anchor work so the agent-facing payload widens once. Nothing reads or
writes it yet, so this entry owns it.

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

- Today the widget only renders when a token exists and the instance opts in or
  the viewer is an admin — the gate in `templates/layout_base.html.twig` is
  `{% if site_review_widget_token and (site_review_widget_public or is_granted('ROLE_ADMIN')) %}`,
  driven by the `SITE_REVIEW_WIDGET_PUBLIC` env var. Its token is minted per
  project with the `ROLE_API_SITE_REVIEW` scope that
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
4. **Addressed comments are readable again.** `site_review_get` used to filter
   on `status = Pending`, so a comment vanished from every MCP tool the moment
   the agent marked it addressed. It now takes a `status` argument covering
   pending, addressed, resolved and all, so enumerating "the comments this PR
   closed" works — this is no longer a blocker for the approval half below.

What remains open here is the **document-approval** half — letting an agent
record that a human approved a document. It carries the same evidence problem
and is now sharper than the original note assumed, for two reasons found while
mapping it.

`Review` is append-only and requires a non-nullable `reviewer: User`. An MCP
request already holds that `User`, because `ApiTokenAuthenticator` authenticates
as the project owner. So a tool writing a `Review` would produce a row
**indistinguishable from one the owner clicked** — their name on an approval
they never gave. The audit trail separates the two afterwards, and the `reviews`
row itself still does not (see 'An agent's writes look like the owner's, and one
global user signs them all').

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

The review UI already renders a diff between any two versions of a document
(`app_document_review_diff`); that view gets considerably more useful once
humans are producing versions too.

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

## Two patterns now exist for fieldless POST actions — converge them


**Author:** Claude · **Type:** tooling · **Priority:** medium · **Status:** pending

Archiving and unarchiving a document were converted (2026-08-02, review comment
on pull request #118) from a hand-written `<form>` plus
`#[CsrfToken('document-archive')]` to a real Symfony form —
`Module/Review/Form/ArchiveDocumentFormType`, built per row by
`ReviewExtension::documentArchiveForm()` and rebuilt by name in the controller,
with the form component issuing and checking the token. The
`document-archive` entry came out of `config/packages/csrf.yaml` with it.

`ArchiveDocumentFormType` is the outlier, and the attribute is the norm. A
count on 2026-09-04 found 32 `#[CsrfToken]` attribute sites under `src/`, with
`grep -rn "^#\[CsrfToken(" src/`. `config/packages/csrf.yaml` declares 30 ids in
`stateless_token_ids`, and the framework owns three of them: `submit`,
`authenticate` and `logout`. So the divergence is much wider than the four
comment controllers this entry first named.

Those four carry the comment actions:

- `Module/Review/Controller/DeleteCommentController` and `ResolveCommentController`
  (`comment-action`), submitted from `templates/Module/Review/components/CommentThread.html.twig`
- `Module/SiteReview/Controller/ReopenSiteReviewCommentController` and
  `ResolveSiteReviewCommentController` (`site-review-comment-action`), submitted
  from `templates/Module/SiteReview/show_site_review.html.twig`

They were left alone deliberately, because they sat outside the branch that made
the archive change. The divergence is known rather than accidental.

Owner decision (2026-09-04): convert every fieldless POST action to a Symfony
form, not only the four comment controllers.

Three hybrid admin forms fall inside that scope. `SuspendUserFormType`,
`DeleteUserFormType` and `InviteOldestWaitlistFormType` are real forms that set
`'csrf_protection' => false`. Each one leans on the controller's `#[CsrfToken]`
attribute instead. Convert them so the form owns the token.

The work is large enough for its own wave. Do not attach it to an unrelated
branch.

`SubmitReviewController` (`submit-review`) is not part of this. It submits a
verdict value, so it is not the fieldless shape.

One thing the conversion surfaced that the attribute form hides: a per-row
fieldless form still needs a unique name (`createNamed`), or every row renders
the same DOM id on its hidden token input.

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
`review.markdown_front_matter_not_tabulated` whenever it takes the fallback,
which is the hook to watch: a document that starts or stops logging it across a
dependency bump has moved. Worth deciding whether the fallback path should be
pinned by storing which path a version used, rather than recomputed.

## The e2e suite is still serialized, and nothing has proved it parallel-safe

**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

`playwright.config.ts` keeps `workers: 1` and the gate still runs one worktree
at a time. During the 2026-07-26 audit-remediation wave that serialized gate was
the single largest cost: ~15 branches each needing a full suite run, back to
back, while the DB-free static gate parallelised freely.

The shared-Mailpit coupling that used to force it is gone: every worktree now
gets its own Mailpit sidecar and its own `MAILER_DSN`, and `just e2e` exports a
worktree-scoped `MAILPIT_URL` that the specs read. Playwright-headed requests
also deliver mail synchronously, so there is no worker to scope either.

What is left is proof. Nothing has yet gated two branches concurrently, so no
run has established that the rest of the suite — fixtures, feature flags, the
destructive `install-reset` and `trial-end-lifecycle` projects, any other shared
host state — tolerates it. Close this by running two branches' suites at once,
finding what collides, and only then lifting `workers: 1`.

## OAuth for the MCP and site-review widget, with project selection at consent

**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

**Deferred by owner decision: pasted API tokens stay, and nobody should start
this now.** The entry survives the decision because the strategic case below is
unchanged and the work may still be picked up — but nothing waits on it, and two
other entries are parked behind it rather than being worked around it.

The idea: let the MCP endpoint and the site-review widget authenticate by OAuth
rather than by a pasted API token, with the consent screen offering project
selection — and project *creation* — at authorization time.

What this replaces: both surfaces today use `ApiToken` rows minted per project
and bound to it (`mcp` and `site_review` scopes), pasted into an agent's MCP
config or embedded in the widget snippet built by
`templates/Module/Project/connect_agent.html.twig`. The binding is fixed at
mint time, so switching project means minting a new token, and the widget's
credential is visible in page source.

Things this would interact with. The per-token agent-forwarding flag that
already ships (`ApiToken::$forwardsToAgent`) — an OAuth scope is the natural
home for it, and the opt-in must survive the migration rather than being granted
by default. "Personal reviewer tokens as an identity layer for the widget",
which is no longer an independent track: per-reviewer identity for the widget
arrives with OAuth and not before, so that entry is folded into this one and
waits on it. "Revisit: migrate API auth to Symfony's `access_token`
authenticator", which is parked for the same reason — OAuth would rewrite that
layer anyway, so migrating the authenticator first is doing the work twice. And
the deny-by-default `^/api` rule in `config/packages/security.yaml`, since OAuth
scopes would need the same per-scope `access_control` discipline. Note
`symfony/mcp-bundle` is on 0.12 and tracks the MCP protocol's own authorization
spec — check what it provides before hand-rolling a server.

## Drop the dead selector and text columns from site_review_comments


**Author:** Claude · **Type:** tooling · **Priority:** medium · **Status:** pending

`site_review_comments` still has a `selector` column and a `text` column.
`Version20260904185305` made both nullable. The anchor data now lives in
`site_review_comment_anchors`, one row per element, so nothing reads the two
columns any more.

`SiteReviewComment` still maps them, and every new comment writes anchor 0 into
them. A page note writes two empty strings. They are write-only, and they exist
for one reason: a previous image maps both as non-nullable `string`, so it can
hydrate a row only when they carry a value. Without the write, a rollback onto
this schema raises a TypeError on read rather than rendering something poor.

That migration expands only, so a rollback lands on a schema the previous image
still tolerates. The drop is the contract phase, and it belongs in a later
release. Write it once no deployed image reads the scalars.

**The contraction is what stops the write.** Remove the two properties from
`SiteReviewComment`, and the assignment in `addAnchor()`, in the same release as
the drop. Do neither on its own. A drop without the mapping change breaks every
insert, and a mapping change without the drop leaves two columns written and read
by nothing for good.

The contraction migration must run the backfill again before it drops anything.
`Version20260904185305` backfilled once, and an instance that predates the anchors
table can still write a comment straight into the scalar columns after that. Such
a row has a selector and no anchor row, and the current code shows it as a page
note with the element lost. Re-run the backfill for every comment with a
non-empty selector and no anchor row, then drop:

    INSERT INTO site_review_comment_anchors (id, comment_id, position, selector, text)
    SELECT gen_random_uuid(), c.id, 0, c.selector, c.text
    FROM site_review_comments c
    WHERE c.selector <> '' AND c.selector IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM site_review_comment_anchors a WHERE a.comment_id = c.id);

    ALTER TABLE site_review_comments DROP selector;
    ALTER TABLE site_review_comments DROP text;

The re-run stays necessary even though new comments write the scalars. It covers
the reverse case: an old instance writes a scalar and no anchor row, and the
current code then shows that comment as a page note with its element lost. No
dual-write and no trigger guard that window. The owner decided the window is too
short to justify either, and the backfill above is the repair.

This defers the hazard in 'Rolling a production image back can leave the schema
ahead of the code'. That entry has to settle the expand and contract policy, and
this column pair is a concrete case of it.

## Rolling a production image back can leave the schema ahead of the code

**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

`docker/prod/release.sh` applies migrations unconditionally, and no
expand/contract policy is written down anywhere. Until one is, rolling an image
back leaves the database schema ahead of the code that runs against it, with no
documented way to get back to a consistent pair.

This was originally recorded as the second half of a version-checker entry, on
the reasoning that version identity and a rollback-safe migration policy had to
land together. The first half has since landed — `src/Service/BuildIdentity.php`
derives the running build's version and `src/Service/UpdateCheck.php` compares it
against GitHub releases behind an off-by-default flag — so what remains is the
policy, which nothing forced along with it.

Settling it means deciding what a migration is allowed to do in a release that
might be rolled back (expand-only, contract in a later release), and making
`release.sh` honour that rather than migrating on every deploy regardless.

## Worker heartbeat, so "is a worker running?" can be answered positively


**Author:** Claude · **Type:** feature · **Priority:** medium · **Status:** pending

The `worker` check on `/admin/status` (`WorkerCheck` in
`ubermuda/health-check-bundle`) can only prove the *failure*
case: it measures the age of the oldest available-and-unclaimed row in
`messenger_messages`, so a
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

## Public /open page fed by real data


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Owner note (2026-07-27): build an `/open` page in the spirit of open-startup
dashboards, populated from live sources rather than hand-maintained numbers —
the application database, Umami, Stripe, DigitalOcean, and Claude token spend.

Rough shape of each source: **database** for product metrics (projects,
documents, reviews, registered users) — all already queryable; **Umami** for
traffic, now installed under `src/Module/Analytics/` but flag-gated off by
default, so an instance must enable it before there is anything to read;
**Stripe** for revenue
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
the token means finding every copy by hand, and a stale copy surfaces to an
agent as "can't connect" rather than as an obviously expired credential.

Worth deciding the shape of the fix before building one. The cheap version is
presentational: offer the `-s user` form (or both, labelled) in the connect
instructions, since a user-scoped registration is visible from every directory
and would make one Loupe project reachable everywhere in one step. The larger
version is "OAuth for the MCP and site-review widget, with project selection at
consent", which replaces the pasted token entirely — but that still authorizes
per directory unless the resulting credential is stored user-wide, so the scope
question outlives the token question and should be answered on its own.

## An agent's writes look like the owner's, and one global user signs them all


**Author:** Claude · **Type:** feature · **Priority:** medium · **Status:** pending

The audit trail now exists. `audit_log` records an operation, an outcome, an
actor, a credential, a subject and a context for each recorded decision. An
admin reads it at `/admin/audit-log`. `AuditLogPurger` holds it to the window
the `audit.retention_days` flag sets, which is 180 days by default. The trail
settles provenance: an MCP write carries the API token in `credential_id`, so a
reader can tell a token write from a click. Attribution in the data itself stays
open.

This was three separate entries and is one question: **what is an actor here,
and how is an agent acting through an owner's token distinguished from the
owner?** Two faces of it are still open.

1. **`Review` cannot be attributed at all.** It requires a non-nullable
   `reviewer: User`, and an MCP request authenticates as the project owner
   (`ApiTokenAuthenticator` builds its passport from `$token->owner`), so any
   agent-written `Review` is byte-for-byte identical to one the owner clicked.
   The audit trail records that a token acted, and the `reviews` row a later
   reader opens still says the owner. This is what blocks the
   document-approval half of "Let the agent close the loop when a human
   approves the work".

2. **Every agent comment comes from one global user.** Comments written through
   the MCP are authored by `App\Module\Account\Entity\User::AGENT_ID` (inserted
   by `migrations/Version20260803000402.php`), one account for the whole
   instance, so two projects, two API tokens and two different agents produce
   replies that are indistinguishable in the thread and in
   `document_get_review`. That was the deliberate choice when
   `document_reply_to_comment` shipped: a per-project agent account multiplies
   rows in `users` for a distinction nobody had asked to see, and every count
   and sweep that must skip the agent would have to skip a set instead of an
   id. The trail separates those replies by credential; the thread the human
   reads does not.

**Decided.** The choice was between a per-operation audit log and actor columns
on each subject. The audit log was built. A subject-column scheme is not the
open question any more, and neither is the nullable `ApiToken` reference this
entry once proposed for `Comment`: the credential rides on the audit row
instead.

What is left is whether the reading surfaces need the same distinction the
trail has. A reader of a `Review` row, or of a comment thread, still cannot see
which agent or which credential produced it without opening the trail beside
it.

## There is no JavaScript test harness, and the JS is no longer trivial


**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

`package.json` carries `eslint`, `prettier`, Tailwind and `esbuild` 0.28.2,
plus a `build:widget` script. It carries no test runner, no DOM environment and
no `test` script. So Playwright is the only way to execute any JavaScript in
this project. Playwright needs a booted app, a database and a mail catcher, and
it costs minutes.

That was proportionate when the front end was a handful of small Stimulus
controllers. It is not any more: `assets/controllers/comment_anchor_controller.js`
is ~1130 lines carrying the selection capture, the anchor extraction and the
highlight painting that document review depends on, and
`public/site-review/widget.js` is ~1650 lines that ships to other people's sites.

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

Owner decision (2026-09-04): add vitest and a `just ci` leg in the same change.
Cover `#findRange` in `assets/controllers/comment_anchor_controller.js` first.
Cover the site-review widget's fatal-state machine second. Both are pure enough
to test without a browser.

The widget already has a build step. `docker/prod/Dockerfile` runs `npm run
build:widget`, which minifies `public/site-review/widget.js` with esbuild. The
production artefact is a minification of the source. So the tests run against
the source, and e2e verifies the build.

Sequencing: the one-off reformat in "Running prettier on the site-review widget
reformats all 1600 lines" must land before this work.

## Package Loupe as a Claude Code plugin and list it in the agent directories


**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

Distribution research on 2026-08-03 (written up in the ubermuda.xyz repo under
`gtm/loupe/strategy/`) found that a comparable tool reached ~7,500 GitHub stars
with essentially no help from Reddit, Hacker News or Product Hunt — a flat
23–39 stars/day for seven months with no decay. What drove it was being
installable from inside the tools people already use: a one-line installer, a
plugin marketplace entry, and listings in auto-generated directories.

**The packaging half has landed** (commit 142db56):
`.claude-plugin/marketplace.json` sits at the repo root, and
`plugins/loupe/.claude-plugin/plugin.json` plus `plugins/loupe/.mcp.json` sit at
the plugin root, so the MCP server and the skill bundle install as one plugin.
That covers most of the ecosystem, because the de facto standard is Claude
Code's file layout — Copilot CLI, Cursor, OpenCode, Cline, Amp, Pi and Droid all
read parts of it directly.

What is left is distribution: the plugin is not listed anywhere. Self-serve, no
gatekeeper: Gemini CLI (add the GitHub topic `gemini-cli-extension` plus a root
`gemini-extension.json`, crawled daily), OpenCode (PR to their ecosystem page),
skills.sh, and the MCP registry (still in preview). Curated but open: the Claude
Code plugin directory, Cursor's marketplace (manual review, plugins must be open
source — AGPL qualifies), and Kiro's Powers.

The Pi listing is not in that set. 'Ship a Pi plugin for Loupe' owns it, and the
owner parked that entry. Do not start the Pi listing from here.

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

## Enable and disable individual MCP tools per instance and per project


**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** parked

Make each MCP tool switchable, at two levels that behave differently. Instance
is the operator deciding which tools exist on their deployment. It is a policy
switch, set once, that applies to everyone on that instance. Project narrows
that set further for one project's agents, and behaves like a permission grant.

The mechanism for the instance level already exists.
`App\Mcp\FlagGatedToolInterface` binds a tool to a feature flag,
`FlagGatedListToolsHandler` hides a disabled tool from `tools/list`, and
`App\Module\Project\Mcp\AdvertisedTools` reads the same gates for the connect
page. `DocumentHighlightTool` is the only tool that uses it today, behind the
`review.highlights.enabled` flag.

Which of the remaining tools get a flag stays open. Where per-project state
lives also stays open, because a per-project override may not fit the
feature-flag bundle and may need its own storage.

What makes this concrete: the archive tools were withheld from the MCP surface
entirely on 2026-08-02, on the reasoning that an agent able to archive can take
its own work out of a reviewer's list. They were added on 2026-08-03 when the
capability proved useful for retiring duplicate uploads. A per-tool switch
turns that into a setting rather than a one-way decision. The cautious posture
stays available to whoever wants it, and the tool stays available to everyone
else. The same shape returns for any tool that most projects want and some do
not.

## MCP tool: hand the human a list of what needs their attention

**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** parked

Owner note (2026-08-04): a tool that lets an agent push "todos for the human".
Examples are pull requests waiting to be reviewed, test scenarios to walk
through by hand, and decisions the agent could not make. An agent that works for
hours produces a queue of things only a person can finish, and today that queue
exists only in the chat log.

Notes for whoever restarts this. The tool shares an "agent asks a human" model
with 'Agent-authored test scenarios delivered through the site-review widget',
so the two need one model rather than two. The read side has to be built first,
because the agent must see what it asked for and what came back. Without that,
the next session starts by re-asking.

## Ship a Pi plugin for Loupe

**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** parked

Owner decision (2026-09-04): Loupe ships a plugin for Pi. That settles the older
scope question about which models and harnesses get a first-party plugin.

The work is parked rather than urgent, because one bundle already reaches most
harnesses. The 2026-08-03 survey in 'Package Loupe as a Claude Code plugin and
list it in the agent directories' found that the plugin layout plus its
`.mcp.json` is read directly by Copilot CLI, Cursor, OpenCode, Cline, Amp, Pi
and Droid. A Pi plugin therefore adds a package and a listing, and no second
packaging format.

Pi's publishing path is self-serve: an npm package that carries the `pi-package`
keyword. This entry owns that listing. The packaging entry named above lists the
other directories, and it does not list Pi.

## A step-ca thread leak takes down `docker exec` for every other container

**Author:** Claude · **Type:** tooling · **Priority:** medium · **Status:** pending

Observed 2026-08-05, and again 2026-08-09. Every `docker exec` into `loupe-php-fpm-1` began failing
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
docker compose -f <your traefik stack> restart step-ca
```

Afterwards `docker exec` works again and TLS still verifies
(`curl -o /dev/null -w '%{ssl_verify_result}'` returns 0), so no certificate
re-issue is needed.

Worth recording for the diagnosis, which is the expensive part: the symptom
appears as a **Docker or php-fpm fault** and invites restarting this project's
stack, which changes nothing. The tell is that exec fails for *every* container
rather than one, and the fix is to find the PID hog with
`docker stats --no-stream --format '{{.Name}} pids={{.PIDs}}'` before
restarting anything.

**A second tell, cheaper to spot: several unrelated containers report
`(unhealthy)` at once.** On 2026-08-09 `database`, `mailer` and `mercure` were
all unhealthy while the app served 200s against that same database — because a
healthcheck has to fork a process too, and there were none left. All three
returned to healthy on the step-ca restart with nothing else touched. The dev
`worker` had also died and stayed dead despite `restart: unless-stopped`, for
the same reason: the daemon could not fork it back up. So a spread of unhealthy
containers plus a missing worker is this bug, not several bugs — do not go
restarting them one by one.

**The second occurrence points at a timer rather than at load.** Both times
step-ca had been up for days (8 on 2026-08-05, 4 on 2026-08-09) and both times
it landed within three PIDs of the same number — 49,702 then 49,699. A leak
driven by certificate issuance or by request volume would not converge on the
same figure from two different uptimes and two very different weeks of use; a
thread spawned per tick, against a ceiling the VM imposes, would. The remaining
unknown is what the ceiling actually is, since ~49.7k is suspiciously close to
a `threads-max`-style limit rather than to anything step-ca configures.

**Worth doing regardless of the root cause: put a `pids_limit` on that service.**
Both outages took down `docker exec` for every container on the machine —
`just ci`, `just cs`, the whole e2e path — when the fault was one container in
an unrelated stack. A limit turns that into one failing container that names
itself, which is the difference between a five-minute fix and the hour the
diagnosis cost the first time.

Same family as 'Host `pkill` does not kill a process inside the php-fpm
container': the host-visible symptom names the wrong process.

## Give each agent its own container in the cloud instead of sharing one dev stack

**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** parked

Every git worktree gets its own nginx sidecar, Mailpit sidecar, database and
URL, but they all share one php-fpm container and one Postgres. That sharing is
the root of most of the parallel-work pain, and on 2026-08-03 it cost most of a
day across nine concurrent branches.

What sharing causes, all observed rather than predicted:

- php-fpm worker exhaustion. One pool serves every worktree plus all e2e
  traffic. The logs carry both failure modes: 71 "seems busy … 0 idle"
  spawn-rate warnings, and 4 "server reached pm.max_children setting". A request
  that gets no worker returns nothing: no response body, no fatal, no log line,
  and a submit button left disabled with no validation error. That signature is
  documented elsewhere as a cold-cache symptom, and it has been misattributed
  that way more than once.
- Any sibling's `just ci` starves an in-flight e2e run, so gating has to be
  coordinated by hand. That coordination does not survive parallel agents. It
  has to be remembered by whoever is orchestrating.

Some pressure has come off without the cause being removed. Messenger dispatch
is handled inline under `X-Playwright` (`PlaywrightSyncMiddleware`), which took
one process out of the shared pool. It also removed the class of failure where a
forgotten worker made an e2e run hang. The pool limits were raised too:
`docker/dev/php-fpm/zz-pm.conf` now sets `pm.max_children = 32`,
`pm.start_servers = 12` and `pm.min_spare_servers = 8`, against an observed peak
demand of 17 to 20 concurrent. Those changes are why this is no longer `high`.

Neither change lifts `workers: 1`, so the per-branch throughput ceiling is
unchanged. See 'The e2e suite is still serialized, and nothing has proved it
parallel-safe'.

Per-agent containers would remove both causes by construction rather than by
convention. They would also end the class of bug where a stop or a kill reaches
only the host-side wrapper. A process started inside a container can only be
observed and stopped from inside it, which `CLAUDE.md` now states.

Worth deciding alongside it: whether the e2e suite still needs to be destructive.
The `install-reset` project truncates every table as its last act. `just e2e`
now re-seeds a worktree afterwards rather than leaving it broken, so this is a
question of design rather than a live breakage.

## Decide what `users.disabledAt` is for, now that the paywall does not read it

**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

Owner note (2026-08-21): `User::$disabledAt` should eventually move into the
Billing module rather than sit on the Account module's `User`. The premise has
changed since then, so the open question is now what the column is for.

Access does not depend on the flag. `App\Module\Billing\Entity\Subscription`
gives each grant of access its own row, with a kind of trial, Stripe or comp,
and a `startsAt` and `endsAt` pair.
`BillingProfile::hasCurrentSubscription($now)` answers whether an account has
access now, and `App\Module\Billing\Service\PaywallGate` reads it. The paywall
does not read `disabledAt` at all.

Billing writes the flag from five handlers: `RunTrialSweepHandler`,
`SyncStripeSubscriptionHandler`, `SeedBillingStateHandler`,
`Admin/GrantCompHandler` and `Admin/RevokeCompHandler`.
`App\Module\Billing\Scheduler\SweepEndedTrialsTask` runs hourly, so the flag
lags a lapsed grant by up to one hour. Access stays correct through that hour,
because the paywall reads the rows.

Account reads the flag in `UserRepository::countHumans()` with `activeOnly`, in
the admin user list `state` filter in `UserRepository::findPaginatedForAdmin()`,
and in `JoinWaitlistHandler`. Billing reads it back in `SubscriptionRepository`
for the cancel survey, and in the capacity checks in `StartCheckoutHandler` and
`ShowSubscribeHandler`. Those two capacity checks are why the flag still decides
something. A disabled account that returns to subscribe is refused when
registration is closed, the cap is full, and it holds no valid invite.

The encapsulation work this entry was once sequenced behind is done.
`PaywallExemptions` lives inside Billing, no `#[PaywallExempt]` marker survives
outside the module, and phparkitect enforces Billing as a leaf. That leaf rule
also stops Account from querying Billing tables. To derive the state, Account
declares a port and Billing implements it. `App\Module\Account\Admin\AdminUserPanelInterface`
already uses that shape.

`User` still carries two inactive flags, `disabledAt` and the admin-owned
`suspendedAt`. A different module owns each one.

**Decision needed.** Choose one:

1. Drop the column, and read account state through a port that Billing
   implements.
2. Keep it on `User`, and document it as a Billing-written cache of "no current
   grant".
3. Move it onto `BillingProfile`. This pays the same port cost, adds a
   migration, and keeps the lag.

## The `cli-test` CI check is not required, so a broken CLI cannot block a merge

**Author:** Claude · **Type:** tooling · **Priority:** medium · **Status:** pending

`just ci` now ends in `cli-test`, and `.github/workflows/ci.yml` runs it as its
own job, but the branch ruleset on `main` still requires only the original eight
checks — `lint`, `cs-check`, `phpstan`, `arkitect`, `gamache`, `audit`,
`phpunit`, `e2e`. A red `cli-test` therefore reports failure and merges anyway,
which makes the job decoration rather than a gate.

Adding it is a repository setting and cannot be done from a branch. The ruleset
is readable with:

```bash
id=$(gh api repos/ubermuda/loupe/rulesets -q '.[0].id')
gh api repos/ubermuda/loupe/rulesets/$id
```

Until it is required, treat a green merge as saying nothing about the Go bridge.

## Three external bundle and package PRs are open, and their tracker entries stay open until the pins move

**Author:** Claude · **Type:** tooling · **Priority:** medium · **Status:** pending

Work that fixes this app but lives in another repository does not land when the
PR merges — it lands when this project's pin moves. Three are in flight:

1. `ubermuda/gamache` gained three rules (a skill-reference check, an MCP
   tool-name rule and a delegated-shape rule) as PRs 37, 38 and 39. Two of them
   touch the same lines of `extension.neon` and the README rule count, so
   whichever merges second needs both entries kept and the count bumped again.
2. `ubermuda/admin-bundle` PR 8 moves the admin sidebar pinning into the bundle.
   Only after it merges and the pin moves can `assets/styles/app.css` drop its
   `.admin-sidebar { position: fixed }` and `.admin-sidebar + div` rules — the
   interim state renders identically, so there is no rush, but the app rules are
   dead weight from that point on.
3. `ubermuda/feature-flags-bundle` PR 7 replaces the arbitrary Tailwind values.

After any of these merges, repoint the pin per the `ubermuda/*` pinning rule in
`CLAUDE.md` and only then delete the corresponding entry here.

## A site-review comment on a worktree preview does not know which pull request it belongs to

**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Every worktree serves its branch at `https://<slug>.loupe.dev.localhost`, and the site-review widget works there like anywhere else. A comment left on that preview lands against the project, with nothing recording that it was made against a branch under review. The reviewer then has to carry the connection by hand, and the agent picking the feedback up through `site_review_get` cannot tell which branch the comment is about.

The wanted behaviour is that a comment made on a worktree preview attaches to that branch's pull request. What "attaches" means is open, and the options differ a lot in cost: store the branch or PR number on the comment so `site_review_get` reports it, post the comment to the PR as a review comment, or link the two so the widget shows the PR and the PR shows the comments.

Resolving the PR from the request is the first question to answer. The widget knows the host it is loaded on, `bin/worktrees/` resolves a slug to a worktree, and `gh pr view --json number` resolves a branch to a PR, so the chain exists but nothing joins it up today. Note that a worktree can exist before its PR does.

Relevant code: `public/site-review/widget.js`, `src/Module/SiteReview/`, and the site-review API routes. See also "Site-review comments have no agent-reply data model" and "Let the agent close the loop when a human approves the work".

## The listeners' `/logout` exemptions are dead in production but live in their unit tests

**Author:** Claude · **Type:** tooling · **Priority:** low · **Status:** pending

`RequireTermsAcceptanceListener::isExempt()` and
`RequireNotSuspendedListener::isExempt()` both exempt `/logout`. In the real HTTP
stack the branch never runs: the firewall's `LogoutListener` answers that path at
priority 8 and calls `setResponse()`, which stops propagation, so neither gate
(priority 3 and 6) sees it. Verified with a throwing probe that never fired.

Removing them is not free, though, and an attempt on 2026-08-21 was reverted for
this reason. `RequireTermsAcceptanceListenerTest` instantiates the listener
directly and passes it a synthetic `Request::create('/logout')` with no firewall
involved, so in that context the exemption **is** load-bearing — deleting it
fails the test. The test case is the only place the invariant "a gated user can
always leave" is written down at that layer, and that is precisely the invariant
the terms Decline bug violated.

Sequence it like this. Once the Decline fix has merged, `AcceptTermsControllerTest`
covers the invariant functionally (it submits the page's own control and asserts
the redirect to `/login`). At that point the unit-test entries can go along with
the exemptions, because the guarantee has a better home. Doing it in the other
order trades a real assertion for three lines of tidiness.

## Consider scoped mutation testing, because a green suite proved little here

**Author:** Geoffrey · **Type:** tooling · **Priority:** medium · **Status:** pending

No mutation-testing tooling is installed — checked against `composer.json`,
`package.json` and the `justfile`. Infection is the PHP tool for it.

What prompted this: three tests written for the account-suspension work passed
regardless of the code they were meant to guard. One asserted the page body
contained "suspended", which the heading renders unconditionally. One asserted a
redirect that the firewall produced at priority 8 for an unrelated reason. One
used a fixture that stamped terms acceptance and so never triggered the gate
under test. All three were defects in the plan they were written from, and the
suite was green throughout.

Each was caught by hand: revert the change the test guards, confirm the test
fails, restore. That is one mutant, placed where a weakness was already
suspected. It gives no score and covers none of the mutants nobody thought of —
which is exactly the gap a tool closes.

Scope Infection to the directories where a silently-passing test costs most:
`src/Module/Account/Security/` and `src/Module/Account/EventListener/`. Both
hold code that runs on every authenticated request. A wrong answer there is an
outage or an authorization hole rather than a broken page.

Owner decision (2026-09-04): make it a manual scoped `just` command, not a `just
ci` leg. Run it on a regular cadence of about one week. Do not tie the run to
which code a branch touches.

Build the scoped command over those two directories. Measure one run, because
Infection reruns the suite once per mutant, and a full-repo pass against ~1450
tests is coffee-break length. Then decide how the weekly run is triggered. That
trigger mechanism is the remaining open question.

## `countActiveAdmins()` depends on two rules that live somewhere else

**Author:** Claude · **Type:** tooling · **Priority:** low · **Status:** pending

`UserRepository::countActiveAdmins()` decides whether an admin action would
strand an instance with no way back in — the `AdminUserGuard` G4 rule. Its
correctness rests on two facts it cannot see, and neither is asserted anywhere.

**It ignores `disabled_at` on purpose.** A billing-disabled admin still counts as
a recovery path, because the paywall exempts every route beginning `app_admin_`,
so it never blocks the admin area. That exemption is the `app_admin_` prefix in
`App\Module\Billing\Service\PaywallExemptions`. If it is ever removed, a disabled
admin can no longer reach the admin area, and the query needs
`AND disabled_at IS NULL` or G4 stops protecting anything.

**It matches `ROLE_ADMIN` literally**, via `jsonb_exists(roles::jsonb, 'ROLE_ADMIN')`.
There is no `role_hierarchy` configured in `config/` today, so the literal is the
whole admin surface. Introducing a hierarchy where some other role inherits
`ROLE_ADMIN` would make this query undercount, and G4 would start refusing safe
deletions while believing it was preventing lockout.

Neither is broken now. Both are the kind of coupling that breaks silently and
far from the change that caused it, which is why they are written down rather
than commented in the query.

## Drop `.env` from CommentBudgetCheck's patterns

**Author:** Geoffrey · **Type:** tooling · **Priority:** low · **Status:** pending

The repo-wide sweep took `CommentBudgetCheck` from 151 findings to 10, and every
other file — `src/`, `templates/`, `assets/`, `config/`, `tests/`, `e2e/`, the
`justfile`, the compose topologies — is at zero. The 10 that remain are all in
`.env`, and none of them is obviously wrong.

Its first block is Symfony's own shipped header, which `composer
recipes:update` would restore if rewritten. The rest document environment
variables for whoever deploys the app, which is that file's whole purpose.

Owner decision (2026-09-04): drop `.env` from the check's `patterns` in
`gamache.php` at the project root. Marking each block with
`@comment-budget-ignore` trades documentation for a number, which is the
opposite of what the check is for.

Accepted cost: the check then polices nothing in that file. A long comment block
could land in `.env` and nothing would report it.

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
plus a reanchor pass, which `bin/console app:review:rerender-versions --reanchor`
now performs. Re-allowing `<input>` is the cheaper half and
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

**Parked by owner decision: the custom `ApiTokenAuthenticator` stays as it is,
and this is revisited only if the OAuth work lands.** The reason is that OAuth
replaces how both API surfaces authenticate, so it rewrites this layer anyway —
migrating the authenticator first means doing the same work twice. The OAuth
entry is "OAuth for the MCP and site-review widget, with project selection at
consent", and it is itself deferred, so the practical answer for now is: leave
this alone.

We hand-roll `ApiTokenAuthenticator` (custom `AbstractAuthenticator`). Symfony's
built-in `access_token` firewall + an `AccessTokenHandler` is the more idiomatic
mechanism. Note: `access_token` has **no** native scope→role mapping (verified
against current Symfony docs), so the migration is a modernization, not a scope
win; per-token scope roles are slightly more awkward there (you don't own
`createToken()`), so weigh that when revisiting.

## Site-review widget: surface per-comment save errors more granularly




**Author:** Claude · **Type:** feature · **Priority:** low · **Status:** pending

All widget API failures render into the single `#lp-error` banner. Fine for a
one-reviewer tool; if bulk operations ever appear, attach errors to the affected
list row instead.

## Billing paywall answers machine clients with 402




**Author:** Claude · **Type:** feature · **Priority:** low · **Status:** pending

`RequireSubscriptionListener` gates `/api/` and `/mcp` requests like the UI
but answers `402 Payment Required` with `{"error": "subscription_required",
"subscribeUrl": ...}` instead of an HTML redirect. The MCP transport has no
notion of 402, so an agent hitting a paywalled account sees a transport-level
error rather than a JSON-RPC one; if that turns out to be confusing in practice,
give the MCP endpoint a JSON-RPC-shaped error body of its own.

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

**Folded by owner decision into "OAuth for the MCP and site-review widget, with
project selection at consent".** Per-reviewer identity for the widget arrives
with OAuth, so this stopped being a track of its own: do not start it as a
standalone piece of work, and pick it up as part of the OAuth work or not at
all. That work is itself deferred, so in practice this waits.

The shape, kept here for whoever picks OAuth up. Per-reviewer tokens minted via
invite links and held in the reviewer's browser, instead of the site-review
widget's shared site-wide credential embedded in page markup. Buys accountable
identity on every submitted review, per-reviewer revocation, and removes the
token from page source. Costs the zero-friction "anyone on the staging site can
comment" UX (reviewers must redeem an invite first), and needs a lightweight
reviewer identity without full accounts plus a tokenless widget bootstrap mode.
The cheaper mitigation for the exposure concern has already shipped:
`ApiToken::$forwardsToAgent` makes agent forwarding opt-in per widget token, so
a leaked token collects comments but cannot drive the agent.

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

## Dead semantic classes accumulate in app.css with nothing to catch them


**Author:** Claude · **Type:** tooling · **Priority:** low · **Status:** pending

`assets/styles/app.css` defines its semantic component classes with `@apply`
inside `@layer components`. Because a class is declared in CSS rather than
emitted on demand from template usage, deleting the markup that used it leaves
the rule behind — and nothing currently notices.

The dead rules found on 2026-08-21 are gone: the `lp-doc-*` row family, the
`lp-page*` / `lp-section-title` shell, `lp-table`, `lp-code`, `lp-key-values`,
`lp-copy-row`, `lp-anchor--orphan`, `lp-btn--warning` and `.kbd` were all
removed. Three names that sweep had listed were **not** dead and stay:
`lp-anchor` (the landing mock), `lp-comment-composer--untargeted` (applied from
`comment_anchor_controller.js`) and `admin-badge-off` (the admin users list and
detail screens).

What is still open is the durable half: a check that fails when a class defined
in `@layer components` is referenced nowhere, since this recurs every time a
component is replaced. It needs to understand interpolated class names
(`lp-flash--{{ label }}`, `lp-ribbon__bar--{{ state }}`,
`status-check-badge-{{ state }}`) or it will be too noisy to keep — the safe
form is to treat a defined class as used when some template contains its prefix
immediately followed by a Twig expression, which covers the modifier families
without whitelisting them by hand. It must also read the `vendor/ubermuda/*`
bundle templates and any class a bundle applies from JavaScript, which is what
made `admin-badge-off` look dead. The check itself belongs in `ubermuda/gamache`
rather than here.

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

## Running prettier on the site-review widget reformats all 1600 lines

**Author:** Claude · **Type:** tooling · **Priority:** low · **Status:** pending

`just prettier` and `just lint`'s prettier leg both scope to `assets/` and
`e2e/` only, so `public/site-review/widget.js` has never been formatted by it.
Running `npx prettier --write` on that file rewrites a large share of it —
~414 insertions, ~318 deletions on a file of 1649 — because the whole file is
being formatted for the first time.

Nothing is broken today; the trap is that a small widget change plus a reflexive
prettier run produces a phantom several-hundred-line diff, and the real change is
unreviewable inside it. Hit on 2026-08-13 while dropping the widget's send step;
the fix was to revert and re-apply the edit by hand.

Owner decision (2026-09-04): reformat the file in one commit that changes
nothing else. Then add `public/` to the prettier recipes in the `justfile`, so
the file stays formatted.

The widget now goes through an esbuild build step. `docker/prod/Dockerfile` runs
`npm run build:widget`, so the shipped bytes are no longer the source bytes.

This reformat commit must land before the vitest work in "There is no JavaScript
test harness, and the JS is no longer trivial".

## Connect's code panels still emit the literal `YOUR_TOKEN`

**Author:** Claude · **Type:** feature · **Priority:** medium · **Status:** pending

On the Connect screen, `templates/Module/Project/_connect_instructions.html.twig`
and `templates/Module/Project/_widget_snippet.html.twig` fall back to the string
`YOUR_TOKEN` whenever the page is loaded without having just minted a token. So
the CLI one-liner, the plugin install, the `.mcp.json` block and the widget
`<script>` tag are all copyable but not runnable: the reader has to substitute a
value they no longer have.

`ApiToken` now stores a four-character `tokenTail`, which is enough to *identify*
a token on that screen but nowhere near enough to reconstruct one. A working
snippet needs the whole 64-hex value, and only the request that issued the token
ever held it — `ApiToken::issue()` returns the raw string once and persists only
`sha256(raw)` plus the tail.

So there is no cheap fix. Storing the token reversibly would close it and was
rejected: it puts a decryptable credential at rest, which is a security-posture
change rather than a feature. The open options are all UI-side — keep the
placeholder but say plainly in copy that the reader must paste their own token,
or move the snippets behind a "regenerate to see this filled in" affordance that
is honest about destroying the old token.

## The site-review push subsystem has no producer left

**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

Dropping the widget's send step (2026-08-13) removed the only code that ever
created a `SiteReviewEvent`. Everything downstream of it is still in place and
still tested — the outbox table, `DrainOutboxHandler`, the drain scheduler, the
per-project and admin outbox pages, `StreamCredentialsController`,
`SiteReviewTopicBuilder`, and the `site_review.push.enabled` feature flag — but
nothing writes a row, so the outbox is permanently empty, the "N reviews have
not reached your agent yet" notice on `/projects/{id}/site-review` never shows,
and a connected bridge CLI receives nothing.

This is deliberate, not an oversight: the push feature is still under
development and the owner accepted a temporarily producer-less state rather
than deleting it. Open work is deciding what re-triggers a push now that there
is no batch boundary to hang it on — per comment on save (which makes any
visitor of a public page able to nudge the owner's agent, the exact risk the
`widgetToken.forwardsToAgent` check was added for), debounced per project, or
something else. Whatever it becomes, it belongs in
`src/Module/SiteReview/Command/AddCommentHandler.php` or a listener beside it,
and the forwardable decision from the deleted `SubmitReviewHandler` is worth
re-reading in git history before rebuilding it.

## The admin user detail page shows no project or billing context

**Author:** Claude · **Type:** feature · **Priority:** medium · **Status:** pending

`/admin/users/{id}` (`src/Module/Account/Controller/Admin/ShowUserController.php`,
served by `ShowUserHandler` → `UserDetailView`) shows only Account-owned context:
roles, status, verification, terms, connected accounts, active API token count and
data exports. It shows nothing about how many projects the account owns or what its
billing state is, which is usually the first thing an admin wants before suspending
or deleting someone.

Both live in other modules, and reaching for them from Account would add two more
edges to the module graph that the boundaries sweep is meant to remove. The shape
that fits is the one `AccountDataPurgerInterface` and `UserDataExporterInterface`
already use here: a tagged `AdminUserContextProviderInterface` declared in Account,
implemented by Project and Billing, iterated by `ShowUserHandler`, with the view
rendering whatever labelled values come back. Deliberately not built up front — an
unused tagged interface with no implementations is dead code.

Do this with, or after, the boundaries sweep; see "Domain boundaries sweep — and the
arkitect gate that has never rejected anything".

## The admin sidebar is pinned from the app's CSS, reaching into the bundle's markup

**Author:** Claude · **Type:** bug · **Priority:** medium · **Status:** pending

`.admin-sidebar` is `position: fixed` in `assets/styles/app.css`, and because
that takes the nav out of flow, the sibling that holds the page content carries
a matching `ml-56`. That offset is applied through an adjacent-sibling selector,
`.admin-sidebar + div`, because the element belongs to
`ubermuda/admin-bundle`'s `templates/base.html.twig` and the app cannot put a
class on it.

Two things to know. Sticky is not an option here: the bundle gives `<html>` a
hard `100dvh` height, so a sticky item has no travel range and rides the scroll
away — measured, not assumed. And the offset is silently fragile: if the bundle
ever wraps or reorders that content div, the selector stops matching, the
content slides under the fixed nav, and nothing fails loudly. A bundle version
bump is what would trigger it.

The fix is a PR on `ubermuda/admin-bundle` — the aside and the content wrapper
have to change together, which is exactly why it belongs there. Then delete
both rules from `app.css`.

Landed 2026-08-22 in PR #241 (admin user page redesign), in response to a
site-review comment asking for a fixed admin menu.

## A recorded decision cannot be undone

**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

A decision card in a document review records an answer and then offers no way
back: a reviewer who picks the wrong option, or who changes their mind after
reading further, cannot revise it. The verdict side of this already shipped —
`src/Module/Review/Command/UndoVerdictHandler.php` withdraws a verdict — so
there is a pattern to follow for what an undo does to the stored answer and to
anything derived from it.

Related: 'A decision card cannot show its own recorded answer'.

## A decision card offers no free-text option

**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

A decision card presents a fixed set of choices. A reviewer whose answer is
none of them has nowhere to put it, so the answer ends up in a comment,
decoupled from the decision it belongs to. Wanted: an "other" choice carrying
a free-text field, stored alongside the predefined options.

Related: 'Decision controls: multi-select, and whether a choice should carry a comment'.

## A decision card cannot mark one option as recommended

**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

An agent authoring a decision card presents its options as a flat set, with no
way to say which one it would pick. The recommendation is the agent's actual
opinion and it currently has to go in surrounding prose, where it is detached
from the choice it refers to and easy to miss. Wanted: an option can be tagged
as recommended by the authoring agent, through the document MCP surface
(`document_create` / `document_revise`), and the review UI renders that tag on
the option itself.

Related: 'Decision controls: multi-select, and whether a choice should carry a comment'.

## Some log lines stay diagnostics, and one of them is worth revisiting

**Author:** Claude · **Type:** feature · **Priority:** medium · **Status:** pending

The audit migration is complete for every operation an actor asked for. The
lines below stay on `LoggerInterface` on purpose, because no actor asked for
them: a scheduler tick reporting counts, an infrastructure row, a service
reporting its own internals, or an external read that degraded.

Per-tick counts: `site_review.outbox_drained`,
`site_review.outbox_drain_skipped_push_disabled`, `billing.trial_sweep_completed`,
`billing.trial_sweep_skipped_billing_disabled`,
`account.data_export_purge_completed`, `audit.purge_completed`.

Infrastructure and internals: `site_review.outbox_publish_failed`,
`account.data_export_purge_unlink_failed`, `account.data_export_purge_failed`,
`billing.trial_sweep_skipped_after_lock`, `billing.webhook_tie_broken_by_lookup`,
`billing.webhook_malformed`, `billing.webhook_unknown_customer`,
`review.markdown_front_matter_not_tabulated`,
`site_review.widget_origin_mismatch`,
`account.system_status_agent_account_unreadable`,
`billing.survey_skipped_no_url`.

Input normalisation on a request that succeeded: `project.list_page_clamped`,
`review.document_list_page_clamped`, `account.api_token_return_to_rejected`.

External reads that degraded: `billing.price_fetch_failed`,
`update_check.unavailable`, `update_check.failed`.

The one worth revisiting is `site_review.widget_origin_mismatch` in
`src/Module/SiteReview/EventListener/LogWidgetOriginMismatch.php`. It is a
security observation about a token holder, so it reads like a record. Every
value it carries is banned from an audit context: the `Origin` header and the
project's free-text `domain`. A record would hold the project id alone and say
less than the log line does. Decide whether a project-scoped
"traffic arrived from an unregistered origin" record earns its place with no
detail at all.

## Audit operation names have two shapes, and nothing keeps them to one

**Author:** Claude · **Type:** tooling · **Priority:** medium · **Status:** pending

The trail holds 78 operation names in two shapes. `project.created` and
`account.deleted` have two segments. `review.comment.added` and
`site_review.comment.resolved` have three. A reader who filters the admin screen
by prefix must first know which shape the module chose.

The plan is one shape for every name, `<module>.<outcome>`. A gamache PHPStan
rule then reads the first argument of every `Auditor::record()` call and fails a
name that does not match. Open a PR on https://github.com/ubermuda/gamache for
the rule, because gamache is an external package.

Do this before the table carries much history. A rename does not rewrite the
rows already written, and the admin filter is a prefix `LIKE` on `operation`, so
the old name and the new name read as two separate operations from that day on.

## A document cannot be marked as implemented, so the work it specifies has to be re-verified

**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

An agent can create, revise, rename and archive a document. Nothing records that
the work a document specifies is now built. The two signals that exist do not
carry that meaning. `DocumentStatus` holds `in-review`, `approved` and
`changes-requested`, which state review agreement rather than delivery.
`Document::$archivedAt` hides the document, which is not the same as shipping it.

The cost shows up on every re-read. On 2026-09-03 a session was asked whether two
decisions documents had been implemented. It had to grep the tree for each
decision, one by one, because the documents themselves said nothing. Both were
fully implemented.

Three shapes to choose between:

1. A fourth `DocumentStatus` case. Cheapest, but it conflates delivery with
   review agreement, and a document can be implemented without ever being
   approved.
2. A separate nullable `implementedAt`, orthogonal to status the way
   `archivedAt` already is. An approved document can also be implemented, so
   the orthogonality argument in `Document::$archivedAt`'s docblock applies here
   too.
3. Per decision rather than per document. A decisions document holds many
   decision blocks, and they land in separate branches at separate times.

An agent does the implementing, so whichever shape wins needs an MCP tool beside
it. Note the precedent: the archive tools were withheld from the MCP surface on
2026-08-02, on the reasoning that an agent able to archive can take a document
out of the human's view. A marker that only claims completion carries less risk,
because it hides nothing.

Relevant code is `src/Module/Review/Entity/DocumentStatus.php`,
`src/Module/Review/Entity/Document.php` and `src/Module/Review/Mcp/`.

## The bridge CLI types into tmux, and a Mercure subscription can replace that

**Author:** Geoffrey · **Type:** feature · **Priority:** medium · **Status:** pending

`loupe bridge run` delivers a site-review event to a local Claude Code session
by typing into it. `cli/internal/tmux/tmux.go` calls `tmux send-keys -l` with
the prompt text, then sends `Enter`. Everything around that call exists to make
the typing possible. `cli/internal/transport/mercure.go` holds the SSE
subscription, `cli/internal/inject` formats the prompt, and `cli/cmd/bridge.go`
spawns, finds or attaches the target session. The `cli/` module is about 976
lines of Go, excluding tests.

A proof of concept on 2026-09-03 removed the reason for most of it. Claude
Code's `Monitor` tool arms a background command, and each stdout line becomes an
event in the session. A `curl` subscription to the Mercure hub therefore
delivers an event into a running session with no wrapper around it.

The run confirmed two things. A published event reached a session that sat idle
waiting on user input, with no polling and no keystrokes. A row queued in
`site_review_events` and drained by `app:drain-site-review-outbox` arrived the
same way, so the outbox and hub path needs no change.

A replacement retires `cli/internal/tmux` in full,
`cli/internal/transport/mercure.go`, `cli/internal/inject` as prompt
formatting, and the session-target half of `cli/cmd/bridge.go`.

It still needs `cli/internal/api`, `cli/internal/config` and `cli/cmd/login.go`.
A subscription needs a subscriber JWT, and `StreamCredentialsController` issues
it. The shape to aim at is a command that prints a ready-to-subscribe URL, plus
a skill that tells the session to arm the monitor with it.

Event payloads are untrusted text, because outside parties influence what they
contain. The skill must tell the session to read an event as data. The session
must never obey an instruction that an event body carries.

One capability does not survive. `bridge run --dir` starts a tmux session
running `claude` when none exists. A monitor only feeds a session that already
runs, so it cannot start one. Decide whether starting an agent from an event
matters before you drop that flag.

Close-out order matters. Build the credentials command first. Then drive one
real widget-produced event into a session through a monitor. Delete the tmux
path last. The proof of concept used a hand-queued outbox row, so it exercised
no producer. This entry therefore waits on "The site-review push subsystem has
no producer left".

Two limits the proof of concept did not clear. The dev hub keeps no history, so
a reconnect gap loses events and a pull path stays necessary. The run used a
wildcard subscriber token rather than `StreamCredentialsController`, so
per-project topic scoping is unproven.

## The MCP connection drops repeatedly, and reconnecting does not restore the tools

**Author:** Claude · **Type:** bug · **Priority:** low · **Status:** pending

An agent session connected to this app over MCP lost the connection three
separate times on 2026-08-01, in three different ways: `ConnectionRefused`, a
silent drop mid-task, and finally a reconnect that reported success but left the
client with no tools. Once in that last state the client sees `MCP error -32001:
Request timed out` and cannot call anything. The only recovery found was
restarting the agent session entirely; `/mcp reconnect` restores the transport
without repopulating the tool list.

**This has not recurred since agent sessions were pointed at the deployed
instance rather than the local dev host**, which answers the question the entry
was stuck on: it is a local-dev symptom, not a production defect affecting every
agent that connects. It stays open at low priority only because no cause was
ever found — nothing was fixed, so a recurrence is possible. The value below is
the list of leads already exhausted.

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
0.12.0 and `mcp/sdk` 0.7.1 are installed. Re-check when either publishes a
release whose notes mention tool-list delivery or session handling; both are
pre-1.0 and moving.

## Account purgers still hand-roll an order that Symfony can supply

**Author:** Claude · **Type:** docs · **Priority:** low · **Status:** pending

`src/Module/Account/Deletion/AccountDataPurgerInterface.php` says that Symfony's
tagged iterator gives no stable order. That claim is false. Symfony sorts a
tagged iterator by tag priority, and `#[AsTaggedItem(priority: N)]` on an
implementation sets it. A higher priority runs first.

So `deletionOrder()` and the `usort` in `AccountPurger` duplicate a built-in
feature. Replace them with `#[AsTaggedItem]` on each purger, and correct the
docblock. Mind the direction flip: `deletionOrder()` runs the lowest number
first, and priority runs the highest number first. `ProjectAccountPurger` must
keep running first, so give it the highest priority.

The admin user panel extension point took this route in
https://github.com/ubermuda/loupe/pull/309. The purgers stayed untouched there,
because they were outside that branch's diff.

## Collect the launch directories LocalDock lists, before announcing Loupe

**Author:** Geoffrey · **Type:** idea · **Priority:** low · **Status:** pending

The "featured on" section of https://www.localdock.dev/#faq lists a set of
launch and directory sites. It is a ready-made starting list for announcing
Loupe, rather than assembling one from scratch on the day.

Work when this is picked up: read that section, write down which sites it names,
and check each for whether it fits a self-hostable document-review tool with an
MCP server — several launch directories are AI-product or developer-tool
specific, and several want a paid slot. Record the shortlist somewhere durable,
because the source page can change or disappear.

Worth doing before the launch rather than during it: most of these sites want a
tagline, a description, screenshots and a category chosen in advance, and some
gate submissions behind a queue measured in weeks.

## `project.deleted` outlives an account deletion that rolls back

**Author:** Claude · **Type:** bug · **Priority:** low · **Status:** pending

`ProjectDeleter` records `project.deleted`. `AccountPurger` reaches it through
`ProjectAccountPurger`, inside `EntityManagerInterface::wrapInTransaction()`.
`DoctrineAuditSink` buffers its rows and drains them after the unit of work
ends, which is what makes a record survive a rolled-back transaction the way a
log line does. That is right for the trail in general and wrong here. An account
deletion that rolls back leaves records that say the projects went, while the
projects are still there.

The fix wants a post-commit seam. A handler inside a transaction hands the
auditor a record, and the sink drops it if the transaction rolls back. No such
seam exists. DBAL 4 removed its event system, and the ORM has no post-commit
event. So the work starts with a choice of mechanism. One option is a buffer
that the outermost `wrapInTransaction` closure releases on commit. Another is a
transaction-aware wrapper around the DBAL `Connection`.

Rare in practice. An account deletion rolls back only when a purger throws.


## No ubermuda bundle runs gamache on its own code

**Author:** Claude · **Type:** tooling · **Priority:** low · **Status:** pending

`admin-bundle`, `audit-bundle`, `feature-flags-bundle` and `health-check-bundle`
each ship a `phpstan.neon` with level 8 and no gamache extension. The convention
checks, the gamache PHPStan rules, the Rector rules and the two fixer layers
therefore run on this application's code and on none of the code it vendors.

The gap showed up when the audit bundle was extracted on 2026-09-03. Its files
passed every gamache layer while they lived in `src/`, and nothing keeps them
passing now.

The work is one change repeated four times: include `extension.neon` from
`ubermuda/gamache` in each bundle's `phpstan.neon`, add the package to
`require-dev`, and fix whatever the first run reports. Do all four together, so
the four repositories do not drift into four different standards.

## `document_mark_comment_addressed` reports a best-effort skip reason, and nothing says so

**Author:** Claude · **Type:** bug · **Priority:** low · **Status:** pending

`App\Module\Review\Mcp\DocumentMarkCommentAddressedTool` settles its write with
a conditional `UPDATE ... WHERE status = 'pending'`, in
`App\Module\Review\Repository\CommentRepository::markAddressedIfPending()`. The
write is authoritative. When zero rows change, the tool calls `currentStatus()`
to learn why, then reports `already_addressed`, `resolved` or not-found.

That second read is a separate statement outside any row lock. The status can
move between the two reads, so the reported reason can name the wrong status.
Only the label is wrong. No comment changes state because of this.

The site-review tool has the same race, and the owner accepted it there as
best-effort. Pull request #338 wrote that into the `#[McpTool]` description of
`SiteReviewMarkCommentAddressedTool`, into its `__invoke` docblock, into the
`loupe-site-review` skill and into `docs/using/mcp.md`.

Locking was rejected on the site-review side. The fix needs `wrapInTransaction`,
plus `lock(PESSIMISTIC_WRITE)`, plus `refresh()`. `refresh()` fails on these
entities, because Doctrine refuses to rehydrate a readonly `createdAt`. The cost
is one lock per comment on every batch, to sharpen a string that is only wrong
when two writers race the same comment.

The open question is how the document tool records the same contract. It can
take the same caveat wording as the site-review tool, or another treatment.
Decide that first. Then apply it to the `#[McpTool]` description, the `__invoke`
docblock, the `loupe-documents` skill, which lists no skip reasons today, and
the `document_mark_comment_addressed` row in `docs/using/mcp.md`.
