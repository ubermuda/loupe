---
title: "Site review"
description: "An embeddable widget for commenting on live web pages. Preview — optional, and not release-ready."
---

The site-review widget brings the select-and-comment flow to any web page. A
reviewer picks an element, or selects a passage of text, and leaves a comment
about it; it is saved to the project the moment they press Save, and the agent
can pull it with `site_review_get` from then on. There is no send step to
remember.

**This is a preview.** It works and is used daily on this project, but it is not
covered by any release promise, and the pieces around it — the hub, the
[command-line bridge](../extending/cli-bridge.md) — are optional and unreleased.

## Embedding it

Mint a widget token from the project (`/projects/{id}/widget-token`, and
`/regenerate` to roll it), then paste the snippet the project page gives you:

```html
<script src="https://your-instance/site-review/widget.js" data-token="..."></script>
```

The widget derives its backend from its own `script.src`, so the host it is
served from is the instance it talks to.

**Install it on staging and preview environments only, never a public site.**
The token appears in page source, so anyone who can view the page holds it — and
that credential reads, edits and deletes every pending comment on the project,
not only the ones its holder wrote. Keeping the widget off public pages is what
bounds who that is. Use a dedicated site-review-scoped token, never an MCP token
or a production credential.

## Quoting a passage of text

Open the widget, then select text on the page as you normally would. A **Comment
on this text** button appears under the selection. Click it and the composer
opens with that exact passage quoted, rather than with the whole paragraph
picked. The selection may run across bold, links and other inline markup.

The offer appears only while the widget panel is open, so ordinary reading and
copying on the page are untouched.

A quoted anchor behaves like any other. It gets a pin, an outline drawn around
the words themselves, and a pill in the composer. One comment can hold both
kinds, so you can quote a sentence and pick a button in the same comment and say
that the two disagree. A quote holds up to 1000 characters. The widget refuses a
longer selection and tells you so, rather than storing part of it.

The agent receives the quoted text, so "this sentence is wrong" arrives with the
sentence attached.

### When the page changes under a quote

The widget stores the quoted words plus a little of the text on each side, and
finds them again on your next visit. The surrounding text is what tells two
identical phrases apart in one paragraph.

If the words are edited or removed, the comment does not disappear. The anchor
falls back to the element the quote came from: the pin stays, and the outline
widens from the passage to the whole element. Only the precision is lost.

## Pointing one comment at several elements

Pick an element, then hold **⌘** (**Ctrl** on Windows and Linux) and click
another. The picker stays up as long as you hold the key, so you can add several
elements in one go. You can also hold the key before the first pick. The composer
shows one pill per element. A comment can hold up to ten elements. Save once,
and the comment is about all of them.

Point at a pill and the widget emphasises the element that pill names, while the
other elements stay outlined. Click the pill to scroll to its element when it is
off screen. Two controls drop an element again: the × on its pill, and the ×
that appears on the element's own outline when you point at it.

Use this when the feedback is about a relationship. "These two should sit side
by side" is one comment about two elements, not two comments.

Every element gets its own pin on the page, and every pin of one comment carries
that comment's number. Point at one pin and the widget outlines every element of
that comment. That is what shows they belong together.

When you come back to a page and one of a comment's elements has gone, the
widget marks the comment as degraded. The surviving pins take an amber dashed
border, the popover says how many elements are missing, and the list row reads
"1 of 2 elements". A comment on a single element that no longer matches simply
shows no pin, as before.

## Drawing on the page

**Draw** is the third way to capture something, beside picking an element and
writing a page note. Press it in the panel, press the pen on the launcher
without opening the panel, or press **D** with the panel open. Then drag on the
page to draw. Every drag adds a stroke. **Undo** drops the last one,
**Clear** drops the whole drawing, and **Done** (or **Esc**) puts the caret back
in the text box. Your draft and your elements stay where they are.

A stroke creates no anchor. Drawing over an element does not point the comment
at it, and an arrow that ends on a button does not attach to that button. Pick
the element with the picker when you want the comment anchored, then draw. One
comment carries elements and a drawing together, which is how you say "move this
box over there".

Where the drawing goes when the page changes depends on whether the comment has
an element:

- **With an element.** The strokes are stored as fractions of the first
  element's box, so the drawing moves and resizes with that element. It survives
  a window resize and a responsive breakpoint.
- **Without an element.** The strokes are stored as fractions of the document
  width. They survive a scroll and a reload. They do not follow a reflow: the
  drawing scales with the page's width and stays where the page put it, so
  content that moves leaves the drawing behind. Anchor the comment to an
  element when that matters.

Two things the first release leaves out. There is no per-stroke eraser, so Undo
and Clear are the whole of what you can take back. Editing a saved comment
changes its text alone, so the drawing and the elements stay as you saved them,
the same way they already do for elements.

Your agent is told only **that** a comment carries a drawing, not what the
drawing looks like. It cannot render vector points over a live page, so treat
the drawing as something you and it discuss, and put the point in words too.

Drawing sits behind the `site_review.drawing.enabled` feature flag, which is on
after an install and after an upgrade. Turn it off in `/admin/feature-flags` and
the widget drops **Draw** from the panel and from the launcher, and the API
refuses a drawing rather than saving a comment without it. Drawings already saved keep rendering on the page,
so the switch takes the tool away and never the work.

## Moving the launcher

The launcher sits in the bottom-right corner, which is where many pages pin
their own controls. Move it to another corner in either of two ways.

- Open the panel and press the corner button in its header. Each press moves the
  launcher to the next corner. The button is reachable by Tab and works with
  Enter or Space.
- Drag the launcher. It follows the pointer, and on release it snaps to the
  nearest corner. A drag never opens the panel.

The panel, the composer and every toast follow the launcher, so
every corner keeps them on screen. The widget remembers the corner in the
reviewed page's own browser storage, so it survives a reload. A private window,
or a browser that blocks site data, gets the bottom-right corner every time and
works the same otherwise.

The widget also sets `data-loupe-review-corner` on the page's `<html>` element,
beside the `data-loupe-review-open` it already sets. A page can read either one
to move its own pinned chrome out of the way.

## What it needs from the page

Very little. The widget is a `fetch` with a bearer header — no clipboard, no
cookies, and no browser API that requires a secure context. It writes one
localStorage key, `loupe.site-review.corner`, and works without it. Cross-origin
embedding works because the API answers CORS itself.

Serving Loupe over plain HTTP is therefore fine when the reviewed page is also
plain HTTP. Embedding it in an **HTTPS** page while Loupe is on
`http://localhost` is the doubtful case — not because of mixed content, which
exempts localhost, but because of Chrome's private-network rules. That is
untested; see [Reverse proxy](../extending/reverse-proxy.md).

## What the reviewer sees, and for how long

The widget lists the comments still waiting on the agent. A comment stays in
that list — editable and deletable — until the agent marks it addressed, and
then it **disappears from the widget**.

That is the intended lifecycle, not a loss: the comment is still on the
project's site-review page in the web UI, where you review the fix and resolve
it. But it means the widget is a worklist of outstanding feedback rather than a
record of everything you have said, and a comment can vanish from under you
while you are looking at the page.

If you are editing a comment at the moment the agent picks it up, your save is
refused and the widget tells you so rather than silently discarding it.

## Reaching your agent

Comments do not push. Your agent sees them when it calls `site_review_get`, so
ask it to look — there is nothing to press, and nothing arrives unprompted.

A comment carries no author, so an agent cannot tell yours apart from anyone
else's on the page. The shipped `loupe-site-review` skill therefore escalates by
category rather than by who asked: any comment that would change a destination,
an identity, a credential or third-party code goes to you instead of being
acted on. That is the same reason the widget belongs on staging only.

Live push over a Mercure hub, an outbox for undelivered events, and the
[command-line bridge](../extending/cli-bridge.md) are all still present but
**currently inert**: nothing publishes an event, so the outbox stays empty and
the per-project and `/admin/site-review-outbox` pages have nothing to show.
That part of the feature is unfinished. Pulling with `site_review_get` is the
supported path today.
