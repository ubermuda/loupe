---
name: project-site-review
description: Use when working on the site-review widget or its backend, `public/site-review/widget.js`, anything under `src/Module/SiteReview/`, the site-review API routes, the dev harness, or `e2e/tests/site-review/`.
---

# Site review (working on it)

A reviewer opens a widget on any web page, points at an element, and leaves a
comment. It saves to the project at once, and the owner's agent pulls it over
MCP. There is no draft and no send step.

To consume the site-review MCP tools as an agent, see `loupe-site-review`. That
job has different hazards.

## The widget is not part of the app's frontend

`public/site-review/widget.js` is a standalone, self-contained script that runs
on third-party pages. The app does not build it, bundle it or style it.

Do not apply `project-frontend` to it. Its design-token rules (`@theme`,
`@apply`, semantic classes in `app.css`, no arbitrary values) govern the Symfony
app's stylesheet and are wrong here. The widget carries its own hex-value
palette in the `CHROME` / `LIGHT` / `DARK` maps near the top of the file. It
injects that palette into two shadow roots, one for the panel and one for the
overlay. Raw hex and literal pixel values are correct here.

Invoke `project-frontend` only for a change in `assets/` or a Twig template.

| Trap | What happens |
|---|---|
| Running prettier on it | Prettier's scope is `assets/` and `e2e/` only; `public/` has never been formatted. A `--write` rewrites ~1400 of 1633 lines and buries the real change. `just cs` is safe; prettier by hand is not. |
| Serena's edit tools | No language server is configured for JavaScript in this project. `replace_content` and friends fail with "No language servers available". Use Edit/Write. Serena reads are unaffected. |
| `text-overflow` in the overlay | JS sets `display` as an inline style on several overlay nodes, which beats the stylesheet. `text-overflow: ellipsis` has no effect on a flex container's anonymous text item, so an `inline-flex` label hard-clips mid-word instead of ellipsing. Check the JS-applied `display` before debugging the CSS. |
| Absolutely-positioned `display` | The overlay's label is `position: absolute`, so `display` blockifies: `inline-block` computes to `block`, `inline-flex` to `flex`. Computed style will not echo what you wrote. |
| Reading the platform from one source | Playwright's Chromium reports `navigator.userAgentData.platform` as `Windows` while running on macOS, and `navigator.platform` as `MacIntel`. The widget's add-anchor modifier takes either Mac signal, because Ctrl+click on a Mac is a right click: the widget's own `contextmenu` handler then stands the picker down, and every pick after the first fails in a way that reads as a lost click. Mirror the same expression in a spec rather than reading `process.platform`. |

## The status model

```
Pending  →  Addressed  →  (Resolved)
```

- `Pending`: the reviewer saved it and the agent has not acted. Comments are
  created in this state. There is no draft.
- `Addressed`: the agent fixed it (`site_review_mark_comment_addressed`).
- `Resolved`: the human signed it off in the web UI.

`Draft` was removed with the send step. Any reference to it is stale.

`findOnePending()` gates both edit and delete, and it fails silently. A wrong
predicate makes rehydrate return nothing, edits 404 and deletes 404, while the
widget renders perfectly and appears to forget everything. No exception, no log.
Test a real edit-and-delete round trip after you touch it.

A comment is editable and deletable only while `Pending`. When the agent marks
it `Addressed`, the reviewer's in-flight edit 404s and the widget says the agent
picked it up. The comment leaves the reviewer's list, because the widget lists
only `Pending`.

## Ownership voters do not constrain agents

An MCP request authenticates as the project owner, and `SiteReviewCommentVoter`'s
whole rule is `$subject->project->owner === $token->getUser()`. Every
ownership-based voter therefore returns true for an MCP call by construction.

Routing and firewall config stop an agent from resolving a comment, not
authorization. No tool calls the resolve path, and `ApiTokenAuthenticator` runs
only on the `mcp` and `api` firewalls, so a Bearer token cannot reach the
resolve route on `main` at all.

So an ownership voter's result is not a meaningful check on what an agent may
do. When you add a tool, do not reason "`SiteReviewCommentVoter` will stop it."

`App\Security\McpBoundProjectVoter` (`site_review.mcp_read` /
`site_review.mcp_write`) is the one voter that does constrain an agent. It is
shared with the review and board modules, and it reaches a subject's project
through `App\Security\ProjectScopedSubject`. It compares the subject's project
against the project the *token* is bound to, which is narrower than ownership:
a token minted for project A cannot reach project B, even when one user owns
both. Every caller-supplied site or comment id reaching an MCP tool goes
through `SiteReviewSubjectResolver`, which asks that voter and nothing else.
Resolve a new tool's ids there instead of re-deriving the scope.

## The push subsystem has no producer

`SiteReviewEvent`, the outbox, `DrainOutboxHandler`, the drain scheduler, both
outbox pages, `StreamCredentialsController` and the `site_review.push.enabled`
flag are all present and tested. Nothing writes an event any more: dropping the
send step removed the only producer.

This is deliberate, not rot, because the push feature is unfinished. Do not wire
a trigger without a decision; see the entry in `docs/NEXT_STEPS.md`. Expect a
permanently empty outbox, a "not reached your agent yet" notice that never
shows, and a connected bridge CLI that receives nothing.

Counters reading `SiteReviewEvent` report zero forever. The projects list and
the site-review page now count comments instead. `unsentCount` and the outbox
notice stay event-sourced on purpose, because zero is *correct* there.

## API surface

| Route | Method | Purpose |
|---|---|---|
| `/api/site-review/review` | GET | Rehydrate the widget's list (Pending only) |
| `/api/site-review/comments` | POST | Save a comment |
| `/api/site-review/comments/{id}` | PATCH | Edit (Pending only) |
| `/api/site-review/comments/{id}` | DELETE | Delete (Pending only) |
| `/api/site-review/sites` | GET | List sites for a token |
| `/api/site-review/stream` | GET | Subscriber credentials, behind the push flag |

Widget tokens are project-bound and public, because they ship in page source.
Widget-scoped endpoints reject account-level tokens, and account-scoped
endpoints reject widget tokens. `/stream` refuses widget tokens outright.

## Accepted: a widget token reads, edits and deletes every pending comment

The owner accepted this exposure. It is not an open finding.

- Do not re-file it in `docs/NEXT_STEPS.md`.
- Do not report it as a new discovery in an audit.
- Do not narrow `findOnePending()` or the CORS policy without a maintainer
  decision.

The acceptance covers the staging-and-preview-only deployment model and no
further. A deployment that serves the widget to the public has handed that
access out, and falls outside it.

`references/accepted-exposure.md` records exactly what is accepted, why it is
possible, what bounds it, the attribution half, and the deferred per-project
origin allowlist. Read it before you touch this area.

## The dev harness

`/dev/site-review-harness?email=<user>` renders a page with the widget loaded
and the project reset. `&keep=1` preserves existing comments across a reload. It
throws unless the user exists, so seed the user first:

```bash
curl -sk -X POST https://loupe.dev.localhost/dev/register-and-verify \
  --data-urlencode "fullName=Widget Preview" \
  --data-urlencode "email=e2e-site-review@example.com" \
  --data-urlencode "password=E2eSiteReview1!"
```

### Driving it in a browser

- Do not pixel-click the launcher. Its width changes with the comment-count
  badge, so a coordinate that hit the crosshair on an empty project misses once
  a comment exists.
- A `keydown` on `document` does not toggle pick mode. Click the button inside
  the shadow root instead:

```js
const hosts = [...document.querySelectorAll('*')].filter(e => e.shadowRoot);
const pick = hosts.flatMap(h => [...h.shadowRoot.querySelectorAll('button')])
                  .find(b => b.getAttribute('data-tip') === 'Pick element');
pick.click();
```

Hover states need a real `mousemove` with client coordinates, because the widget
picks its target with `document.elementFromPoint`.

## Testing

`e2e/tests/site-review/widget.spec.ts` is the real coverage. It asserts the
highlight's **visibility**, not its geometry, so padding and colour changes do
not break it. `tests/Module/SiteReview/WidgetFileTest.php` asserts structural
facts about the file itself.

The e2e suite needs no messenger consumer. Every message dispatched during a
request carrying `X-Playwright: 1` is handled inline.

## Common mistakes

| Mistake | Reality |
|---|---|
| Applying `project-frontend`'s token rules to `widget.js` | It is standalone; raw hex and px are correct there. |
| Running prettier on `widget.js` | ~1400-line phantom diff. |
| Trusting an ownership voter to stop an agent | Ownership voters return true for every MCP call; `App\Security\McpBoundProjectVoter` is the one that does not. |
| Assuming a comment is private until "sent" | There is no send step. It is live on save. |
| Adding a `Draft` branch | The status no longer exists. |
| Wiring a Mercure trigger to "fix" the empty outbox | Producer-less is a decision, not a bug. |
