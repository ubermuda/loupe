---
name: project-site-review
description: Use when working on the site-review widget or its backend — `public/site-review/widget.js`, anything under `src/Module/SiteReview/`, the site-review API routes, the dev harness, or `e2e/tests/site-review/`.
---

# Site review (working on it)

A reviewer opens a widget on any web page, points at an element, and leaves a
comment. It is saved to the project immediately and the owner's agent pulls it
over MCP. There is no draft and no send step.

For *consuming* the site-review MCP tools as an agent, see `loupe-site-review`
— a different job with different hazards.

## The widget is not part of the app's frontend

`public/site-review/widget.js` is a **standalone, self-contained script** that
runs on third-party pages. It is not built, not bundled, and not styled by the
app.

**Do not apply `project-frontend` to it.** That skill's design-token rules —
`@theme`, `@apply`, semantic classes in `app.css`, no arbitrary values — govern
the Symfony app's stylesheet and are wrong here. The widget carries its own
hex-value palette (`CHROME` / `LIGHT` / `DARK` maps near the top of the file)
injected into **two shadow roots**, one for the panel and one for the overlay.
Raw hex and literal pixel values are the norm in this file, not a violation.

Invoke `project-frontend` only when the change is in `assets/` or a Twig
template.

| Trap | What happens |
|---|---|
| Running prettier on it | Prettier's scope is `assets/` and `e2e/` only — `public/` has never been formatted. A `--write` rewrites ~1400 of 1633 lines and buries the real change. `just cs` is safe; reaching for prettier by hand is not. |
| Serena's edit tools | No language server is configured for JavaScript in this project — `replace_content` and friends fail with "No language servers available". Use Edit/Write. Serena reads are unaffected. |
| `text-overflow` in the overlay | Several overlay nodes get their `display` set as an **inline style from JS**, which beats the stylesheet. `text-overflow: ellipsis` has no effect on a flex container's anonymous text item, so a label set to `inline-flex` hard-clips mid-word instead of ellipsing. Check the JS-applied `display` before debugging the CSS. |
| Absolutely-positioned `display` | The overlay's label is `position: absolute`, so `display` is blockified — `inline-block` computes to `block`, `inline-flex` to `flex`. Computed style will not echo what you wrote. |

## The status model

```
Pending  →  Addressed  →  (Resolved)
```

- **`Pending`** — saved by the reviewer, waiting on the agent. Comments are
  created in this state; there is no draft.
- **`Addressed`** — the agent fixed it (`site_review_mark_comment_addressed`).
- **`Resolved`** — the human signed it off in the web UI.

`Draft` was removed when the send step was dropped. If you find a reference to
it, it is stale.

**`findOnePending()` gates both edit and delete**, and its failure mode is
silent: widen or narrow that predicate wrongly and the widget's rehydrate
returns nothing, edits 404, deletes 404 — while the widget still renders
perfectly and simply appears to forget everything. There is no exception and no
log. Exercise an actual edit-and-delete round trip after touching it.

**The reviewer's window closes when the agent acts.** A comment is editable and
deletable only while `Pending`. The moment the agent marks it `Addressed`, the
reviewer's in-flight edit 404s (the widget catches this and says the agent
picked it up) and the comment drops off their list entirely, because the widget
lists only `Pending`.

## Ownership voters do not constrain agents

An MCP request authenticates **as the project owner**, and
`SiteReviewCommentVoter`'s whole rule is `$subject->project->owner ===
$token->getUser()`. So every ownership-based voter in this app returns true for
an MCP call by construction.

What actually stops an agent resolving a comment is **routing and firewall
config**, not authorization: no tool calls the resolve path, and
`ApiTokenAuthenticator` is registered only on the `mcp` and `api` firewalls, so
a Bearer token cannot reach the resolve route on `main` at all.

**A voter result is not a meaningful check on what an agent may do.** Adding a
tool? Do not reason "the voter will stop it."

## The push subsystem has no producer

`SiteReviewEvent`, the outbox, `DrainOutboxHandler`, the drain scheduler, both
outbox pages, `StreamCredentialsController` and the `site_review.push.enabled`
flag are all still present and tested — but **nothing writes an event any
more**. Dropping the send step removed the only producer.

This is deliberate (the push feature is unfinished), not rot. Do not "fix" it
by wiring a trigger without a decision — see the entry in `docs/NEXT_STEPS.md`.
Consequences to expect: the outbox is permanently empty, the "not reached your
agent yet" notice never shows, and a connected bridge CLI receives nothing.

Counters that read from `SiteReviewEvent` therefore report zero forever. The
projects list and the site-review page were re-sourced to count comments;
`unsentCount` and the outbox notice were deliberately left event-sourced,
because zero is *correct* there.

## API surface

| Route | Method | Purpose |
|---|---|---|
| `/api/site-review/review` | GET | Rehydrate the widget's list (Pending only) |
| `/api/site-review/comments` | POST | Save a comment |
| `/api/site-review/comments/{id}` | PATCH | Edit (Pending only) |
| `/api/site-review/comments/{id}` | DELETE | Delete (Pending only) |
| `/api/site-review/sites` | GET | List sites for a token |
| `/api/site-review/stream` | GET | Subscriber credentials — behind the push flag |

Widget tokens are **project-bound and public** (they ship in page source).
Account-level tokens are rejected from widget-scoped endpoints and vice versa;
`/stream` refuses widget tokens outright.

## The dev harness

`/dev/site-review-harness?email=<user>` renders a page with the widget loaded
and the project reset. `&keep=1` preserves existing comments across a reload.
It throws unless the user exists — seed it first:

```bash
curl -sk -X POST https://loupe.dev.localhost/dev/register-and-verify \
  --data-urlencode "fullName=Widget Preview" \
  --data-urlencode "email=e2e-site-review@example.com" \
  --data-urlencode "password=E2eSiteReview1!"
```

### Driving it in a browser

Two things waste time if you discover them by trial:

- **Do not pixel-click the launcher.** Its width changes with the comment-count
  badge, so a coordinate that hit the crosshair on an empty project misses once
  a comment exists.
- **A `keydown` on `document` does not toggle pick mode.** Click the button
  inside the shadow root instead:

```js
const hosts = [...document.querySelectorAll('*')].filter(e => e.shadowRoot);
const pick = hosts.flatMap(h => [...h.shadowRoot.querySelectorAll('button')])
                  .find(b => b.getAttribute('data-tip') === 'Pick element');
pick.click();
```

Hover states need a real `mousemove` with client coordinates — the widget picks
its target with `document.elementFromPoint`.

## Testing

`e2e/tests/site-review/widget.spec.ts` is the real coverage; it asserts the
highlight's **visibility**, not its geometry, so padding and colour changes do
not break it. `tests/Module/SiteReview/WidgetFileTest.php` asserts structural
facts about the file itself.

The e2e suite needs a consumer running or roughly a third of it fails in ways
that look like application bugs (`just e2e-worker` in another shell).

## Common mistakes

| Mistake | Reality |
|---|---|
| Applying `project-frontend`'s token rules to `widget.js` | It is standalone; raw hex and px are correct there. |
| Running prettier on `widget.js` | ~1400-line phantom diff. |
| Trusting a voter to stop an agent | Ownership voters return true for every MCP call. |
| Assuming a comment is private until "sent" | There is no send step. It is live on save. |
| Adding a `Draft` branch | The status no longer exists. |
| Wiring a Mercure trigger to "fix" the empty outbox | Producer-less is a decision, not a bug. |
