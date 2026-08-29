# Accepted: a widget token reads, edits and deletes every pending comment

What is accepted. Any holder of a widget token can read, edit and delete every
**pending** comment in that token's project, whoever wrote it.
`GET /api/site-review/review` returns bodies, URLs, selectors and quoted page
text for the whole project, not the current page. `PATCH` and
`DELETE /api/site-review/comments/{id}` accept any pending id. The token is a
`data-token` attribute in page HTML, so this covers anyone who can view an
instrumented page. The acceptance covers the staging-and-preview-only deployment
model and no further. A deployment that serves the widget to the public has
handed out all of that, and falls outside it.

Why it is possible. `SiteReviewComment` has no author column:
`AddCommentController` resolves a *project* from the token and stores nothing
about the submitter, so nothing can scope a mutation to its writer.
`UpdateCommentHandler` and `DeleteCommentHandler` both resolve through
`findOnePending($commentId, $project)`, which is project scope only.
`SiteReviewCorsSubscriber` reflects the request `Origin`, so the endpoints work
from any page.

What bounds it. Policy first: `docs/using/site-review.md`, the Connect page and
`ApiToken`'s own docblock document the widget as staging and preview only, so
everyone who can see the token is already entitled to those comments. Then code:

- `Addressed` and `Resolved` comments are immune.
- `/api/site-review/sites` and `/api/site-review/stream` reject widget tokens,
  so there is no project enumeration and no Mercure JWT.
- `RateLimitSiteReviewWrites` slows churn, though not a targeted delete.
- The app's own instance ships `SITE_REVIEW_WIDGET_TOKEN` empty in `.env`, and
  `templates/layout_base.html.twig` gates the widget behind
  `site_review_widget_public or is_granted('ROLE_ADMIN')`.

The attribution half is accepted too, and was accepted first. The two are easy
to confuse, so read this paragraph as attribution only and the one above as
read, edit and delete only. A comment carries no
author, so `site_review_get` cannot tell an agent whether the owner or a passing
visitor wrote it. The compensating control is categorical escalation in the
`loupe-site-review` skill: anything that would change a destination, an
identity, a credential or third-party code goes to the human. That control is
load-bearing. An agent tested without it applied a link-destination change and a
support-email change on its own judgement.

Deferred alternative: a per-project origin allowlist, designed in full and
deferred rather than ruled out. The token binding already blocks cross-project
access, and any non-browser caller can forge `Origin`, so an allowlist would
only stop a browser page on an unregistered origin. It costs a column, a
migration, a backfill over a free-text field, and every widget goes dark
whenever the allowlist is wrong. `LogWidgetOriginMismatch` records the mismatch
instead. Do not re-design it from scratch; the finished plan is a Loupe document
named 'Per-project allowed origins for the site-review widget'.

Per-reviewer identity would close it, and it arrives with the OAuth work rather
than on its own. Both are deferred; see `docs/NEXT_STEPS.md`.
