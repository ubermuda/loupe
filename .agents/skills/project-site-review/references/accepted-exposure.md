# Accepted: a site-review sign-in reaches the comments and the board

The credential is no longer a token in page source. The embed names a project
with `data-project`, and the reviewer signs in through an OAuth pop-up. The
grant carries the `site-review` scope and lives in the tab's session storage.
`PrepareWidgetAuthorizationHandler` runs two checks before the consent page
shows: the page's origin must be on the project's allowed sites, and only the
owner of the project may sign in.

That bounds the holder to a person the project allows. What the scope reaches
did not change, so this record stands. Read it as what a holder of the scope
can do, and expect it to matter more when sign-in widens past the owner.

## The board half

A holder of the `site-review` scope lists every open card in its project and
creates new ones. `GET /api/board/cards` returns the number, title and status of
the project's open cards, and `POST /api/board/cards` files a new one. Card
titles are planning content.

This was decided rather than inherited. The Loupe document 'Picking and creating
cards from the site-review widget' records the choice, the alternatives and what
each cost, including the observation that it reverses the reasoning behind
`/sites` refusing the widget's scope. Read it before you reopen this.

What bounds it. `board.enabled` ships off, and both endpoints re-check it, so an
instance that never switched the board on exposes nothing. Creation takes no
`status` and no `pullRequestUrls`, so a caller cannot file into a column or
attach a URL of their choosing. A card records `CardReporter::Reviewer`, which
says the app could not name who raised it. The write joins the
`site_review_write` limiter through `WidgetApiPaths`, so the board is not an
unbounded spam target. Nothing bounds the read beyond the twenty-card page.

## The comment half

What is accepted. Any holder of the `site-review` scope can read, edit, resolve
and delete every **pending** comment in that grant's project, whoever wrote it.
`GET /api/site-review/review` returns bodies, URLs, selectors and quoted page
text for the whole project, not the current page. `PATCH`,
`DELETE /api/site-review/comments/{id}` and
`POST /api/site-review/comments/{id}/resolve` accept any pending id.

Resolve joined the list after delete, and widens nothing in kind: a holder who
can delete a comment outright can already do worse than sign it off. It is also
the only one of the four a person can undo, from the project's site-review page.
The acceptance covers the staging-and-preview-only deployment model and no
further.

Why it is possible. `SiteReviewComment` has no author column:
`AddCommentController` resolves a *project* from the grant and stores nothing
about the submitter, so nothing can scope a mutation to its writer.
`UpdateCommentHandler`, `DeleteCommentHandler` and `ResolveCommentHandler` all
resolve through `findOnePending($commentId, $project)`, which is project scope
only. `SiteReviewCorsSubscriber` reflects the request `Origin`, so the endpoints
work from any page once a grant exists.

What bounds it. Policy first: `docs/using/site-review.md` and the Connect page
document the widget as staging and preview only. Then code:

- Only the owner of the project signs in, and the page's origin must be on the
  project's allowed sites.
- `Addressed` and `Resolved` comments are immune.
- `/api/projects` and `/api/events` need `ROLE_API_AGENT`, which the
  `site-review` scope does not carry, so there is no project enumeration and no
  Mercure JWT.
- `RateLimitSiteReviewWrites` slows churn, though not a targeted delete.
- The app's own instance ships `SITE_REVIEW_WIDGET_PROJECT` empty in `.env`, and
  `templates/_site_review_widget.html.twig` gates the widget behind
  `site_review_widget_public or is_granted('ROLE_ADMIN')`.

The attribution half is accepted too, and was accepted first. The two are easy
to confuse, so read this paragraph as attribution only and the one above as
read, edit and delete only. The grant names an account and the comment row does
not, so `site_review_get` cannot tell an agent who wrote a comment. The
compensating control is categorical escalation in the `loupe-site-review`
skill: anything that would change a destination, an identity, a credential or
third-party code goes to the human. That control is load-bearing. An agent
tested without it applied a link-destination change and a support-email change
on its own judgement.

The per-project origin allowlist shipped with the OAuth work, after it was
designed in full and deferred once. It gates the sign-in rather than the API
call: `PrepareWidgetAuthorizationHandler` checks the origin through
`SiteOrigins::allows`, and the pop-up posts the code back to that same concrete
origin. The API endpoints still accept any `Origin`, because a non-browser
caller forges it and the check would buy no guarantee.
`LogWidgetOriginMismatch` records a mismatch there and refuses nothing. The
design document is a Loupe document named 'Per-project allowed origins for the
site-review widget'.

Per-reviewer identity is still open. A reviewer signs in, and the comment row
records no account, so the agent still reads an anonymous comment. See the
project board.
