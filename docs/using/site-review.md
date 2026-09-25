---
title: "Site review"
description: "An embeddable widget for notes on live web pages. Each note becomes feedback on a board card. Preview, optional, and not release-ready."
---

The site-review widget brings the select-and-comment flow to any web page. A
reviewer picks an element, or selects a passage of text, and writes a note
about it. The note saves when the reviewer presses Save. Each note becomes
feedback on a card of the project [board](board.md), and the agent reads it
with `feedback_list` or `card_get`. There is no send step.

This is a preview. It works, and this project uses it every day. No release
promise covers it. The hub and the
[command-line bridge](../extending/cli-bridge.md) around it are optional and
unreleased.

## Embedding it

The embed names the project. It carries no credential, so the reviewer signs in
with a Loupe account before they write a note. The project's Connections page
shows the snippet with the project ID filled in:

```html
<script src="https://your-instance/site-review/widget.js" data-project="PROJECT-ID"></script>
```

The ID is the project's UUID, the same one that is in the project's URLs. The
widget reads its backend from its own `script.src`, so the host that serves the
script is the instance it talks to.

Install it on staging and preview environments only. This is a preview, and
only the owner of the project can sign in. On a public page, no visitor can use
the launcher.

### Allowing a site and signing in

1. On the Connections page, add each site that embeds the widget to **Allowed sites**.
   Write one origin on each line, such as `https://staging.example.com`, with no path.
   Use `https`. Plain `http` works for `localhost` only, because the widget needs a secure page to sign in.
   The first label can be a `*`, which covers one level of subdomain: `https://*.example.com` covers
   `https://staging.example.com`, and it covers neither `https://a.staging.example.com` nor `https://example.com`.
   The scheme and the port must still be the same. Loupe refuses a bare `*` and a wildcard over a whole
   registry, such as `*.com` or `*.co.uk`, because one entry would then cover every site under it.
   A preview environment gives each branch its own host, so it needs a wildcard.
2. Paste the snippet into the site.
3. The reviewer opens the widget and presses **Sign in with Loupe**. A pop-up window opens on your Loupe instance.
4. The reviewer signs in, checks the project and the site on the consent page, and presses **Allow**.

The pop-up sends the answer back only to a site on the allowed list, so a copied
snippet does not work on another site. Only the owner of the project can sign in
for now. Loupe refuses any other account on the consent page.

The widget keeps its tokens in the tab's session storage, so a new tab signs in
again. It renews its access in the background. When the renewal fails, the
widget shows the sign-in button again. This happens, for example, after you
revoke **Loupe site-review widget** under **Connected apps**. Removing a site
from the list stops new sign-ins from that site. Revoke the app to end a sign-in
that already exists.

A pop-up blocker must allow pop-ups for the site. Sign in to Loupe with a
password in the pop-up. A social sign-in provider can cut the link between the
pop-up and the page, and the widget then gets no answer.

## Where notes go

Every note lands on a card. Before the first note, the widget asks where notes
go:

| Choice | What a save does |
|---|---|
| A new card for each note | Creates a new card for the note. |
| One card for this review | Adds the note to one card. Pick an open card, or create one. |
| A card for each note, under an epic | Creates a new card for the note, as a child of one epic. Pick an open epic, or create one. |

The widget keeps the choice in the browser's local storage, for this instance
and this project. It holds for every later note, and after a reload, until you
change it. The row under the composer shows where notes go. Click it to choose
again.

The second and third choices open a card picker. Search the open cards, or
type a title and create a card. The title starts as `Review: ` and the page
path, and you can edit it. The third choice lists epics only, and it creates an
epic.

A card that the widget creates has these values:

- The type is `site-review` for a note card or a review card, and `epic` for an epic.
- The column is the board's default column.
- The reporter is `reviewer`.
- A note card takes its title from the first line of the note that is not blank, cut to 80 characters.

After a save that creates a card, the widget names the card and links to it.

When the card or the epic you chose is closed or deleted, the widget forgets the
choice and asks again. A card in a terminal column is closed. Your draft stays.

### A preview page locks the card

A preview deployment can say which card it serves. It sets
`SITE_REVIEW_WIDGET_CONTEXT` to `card:` followed by the card id, and the embed
carries that value in `data-context`. See
[Environment variables](../reference/environment.md).

On such a page, every note goes to that card. The widget does not ask for a
choice, and it does not change the choice you keep for other pages. When the
card is closed or deleted, the widget refuses notes and says so.

### The board must be on

Notes need the board. When `board.enabled` is off, the widget shows "Turn on the
board to use site review". The text box turns read-only and Save stays disabled,
so you can still copy a draft out. The board ships on. See
[Turning the board off](board.md#turning-the-board-off).

## Retrying a save

A failed save keeps the draft open. Press **Save** again to retry it.
The widget keeps the submission ID until you save successfully or cancel the draft.
The server can save the note and lose its response. An unchanged retry then returns the same note.
It creates no second note and no second card.

If you change the content after the server accepts it, the retry reports a conflict and keeps your draft.
Copy the draft before you reload, then review the saved note.
A reload or a cancel starts a new submission. It does not undo a note that already reached the server.

The widget saves through `POST /api/board/feedback`. The request takes an optional UUID in `deliveryId`.
A client must reuse it with unchanged content when it retries.
Its scope is one project, and it lives as long as the stored note.
Reusing it with different content returns HTTP 409 with `delivery_conflict`.

An old copy of the widget script saved through `POST /api/site-review/comments`.
That route now answers HTTP 410 with `widget_outdated`, and the widget tells the reviewer to reload the page.

## Resolving a note

Press the tick on a note, either in the widget's list or on the popover that
opens from its pin. The note leaves the widget, because the widget lists the
notes that are still open.

Resolving keeps the note. It shows as **Resolved** on the Feedback tab of its
card, where you can read it again and reopen it. The Feedback tab also has
**Resolve** for an open note.

A card that finishes resolves its feedback. When a card moves into a terminal
column from an open one, every pending or addressed note on it becomes
resolved. A column delete that moves cards from an open column into a terminal
column does the same. A move back out of the
terminal column leaves the notes resolved.

## Deleting a note

The widget deletes a pending note, and it asks you to confirm first. The delete
also removes the note's card when all of these are true:

- The note created the card.
- The card is still in the default column.
- The card holds no other feedback.
- The card is not an epic.

A card that somebody moved, or that holds other notes, stays. Deleting a card
from the board deletes all of its feedback.

## Quoting a passage of text

Open the widget, then select text on the page as you normally do. A **Comment
on this text** button appears under the selection. Click it, and the composer
opens with that passage quoted. The widget does not pick the whole paragraph.
The selection can run across bold, links and other inline markup.

The offer appears only while the widget panel is open, so ordinary reading and
copying on the page do not change.

A quoted anchor behaves like any other. It gets a pin, an outline around the
words, and a pill in the composer. One note can hold both kinds. You can quote
a sentence and pick a button in the same note, and say that the two disagree. A
quote holds up to 1000 characters. The widget refuses a longer selection and
tells you so. It never stores part of it.

The agent receives the quoted text, so "this sentence is wrong" arrives with the
sentence.

### When the page changes under a quote

The widget stores the quoted words and a little of the text on each side, and
finds them again on your next visit. The text around the quote tells two
identical phrases in one paragraph apart.

If somebody edits or removes the words, the note stays. The anchor falls back to
the element that held the quote. The pin stays, and the outline grows from the
passage to the whole element. Only the precision goes.

## Pointing one note at several elements

Pick an element, then hold **⌘** (**Ctrl** on Windows and Linux) and click
another. The picker stays up while you hold the key, so you can add several
elements at a time. You can also hold the key before the first pick. The
composer shows one pill for each element. A note can hold up to ten elements.
Save once, and the note is about all of them.

Point at a pill, and the widget shows the element that the pill names more
strongly. The other elements stay outlined. Click the pill to scroll to its
element when it is off the screen. Two controls remove an element: the × on its
pill, and the × on the element's own outline when you point at it.

Use this when the feedback is about a relationship. "These two should sit side
by side" is one note about two elements.

Every element gets its own pin on the page, and every pin of one note carries
that note's number. Point at one pin, and the widget outlines every element of
that note. That shows that they belong together.

When you come back to a page and one of a note's elements is gone, the widget
marks the note as degraded. The pins that remain get an amber dashed border.
The popover says how many elements are missing, and the list row reads
"1 of 2 elements". A note on a single element that no longer matches shows no
pin.

## Drawing on the page

**Draw** is the third way to capture something, beside picking an element and
writing a page note. Press it in the panel, press the pen on the launcher
without opening the panel, or press **D** with the panel open. Then drag on the
page to draw. Every drag adds a stroke. **Undo** removes the last one,
**Clear** removes the whole drawing, and **Done** (or **Esc**) puts the caret back
in the text box. Your draft and your elements stay where they are.

A stroke creates no anchor. A drawing over an element does not point the note
at it, and an arrow that ends on a button does not attach to that button. Pick
the element with the picker when you want the note anchored, then draw. One
note can carry elements and a drawing together. That is how you say "move this
box over there".

Where the drawing goes when the page changes depends on the note:

- A note with an element stores its strokes as fractions of the first element's
  box. The drawing moves and resizes with that element. It survives a window
  resize and a responsive breakpoint.
- A note with no element stores its strokes as fractions of the document width.
  The drawing survives a scroll and a reload. It does not follow a reflow. It
  scales with the page width and stays where the page put it, so content that
  moves leaves the drawing behind. Anchor the note to an element when that
  matters.

The first release has no eraser for one stroke, so Undo and Clear are the only
ways to take a stroke back. An edit of a saved note changes its text only. The
drawing and the elements stay as you saved them.

Your agent learns only **that** a note carries a drawing. It does not get the
drawing itself, because it cannot draw vector points over a live page. Discuss
the drawing with the agent, and put the point in words too.

Drawing is behind the `site_review.drawing.enabled` feature flag, which is on
after an install and after an upgrade. Turn it off in `/admin/feature-flags`,
and the widget removes **Draw** from the panel and from the launcher. The API
then refuses a drawing, and it does not save the note without it. Saved
drawings still show on the page, so the switch removes the tool and keeps the
work.

## Moving the launcher

The launcher sits in the bottom-right corner, where many pages pin their own
controls. Move it to another corner in one of two ways:

- Open the panel and press the corner button in its header. Each press moves the
  launcher to the next corner. Tab reaches the button, and Enter or Space
  presses it.
- Drag the launcher. It follows the pointer, and on release it snaps to the
  nearest corner. A drag never opens the panel.

The panel, the composer and every toast follow the launcher, so every corner
keeps them on the screen. The widget keeps the corner in the reviewed page's
own browser storage, so it survives a reload. A private window, or a browser
that blocks site data, gets the bottom-right corner every time. The widget
works the same way otherwise.

The widget also sets `data-loupe-review-corner` on the page's `<html>` element,
beside `data-loupe-review-open`. A page can read either one to move its own
pinned controls out of the way.

## What it needs from the page

The widget needs very little. It is a `fetch` with a bearer header. It uses no
clipboard, no cookies, and no browser API that needs a secure context. It
writes two localStorage keys: `loupe.site-review.corner` for the corner, and
one key that starts with `loupe-site-review:mode:` for where notes go. It works
without them. Cross-origin embedding works because the API answers CORS itself.

So you can serve Loupe over plain HTTP when the reviewed page is also plain
HTTP. The doubtful case is an **HTTPS** page that embeds a Loupe on
`http://localhost`. Mixed content rules exempt localhost, but Chrome's
private-network rules can still block the request. Nobody tested that case. See
[Reverse proxy](../extending/reverse-proxy.md).

## What the reviewer sees, and for how long

The widget lists the notes that still wait on the agent. A note stays in that
list, where you can edit and delete it, until the agent marks it addressed.
Then it **disappears from the widget**.

That is the intended lifecycle. The note is still on the Feedback tab of its
card, where you check the fix and resolve it. So the widget is a list of open
feedback. It is not a record of everything you said, and a note can disappear
while you look at the page.

If you edit a note at the moment the agent picks it up, the widget refuses your
save and tells you so. It does not discard your text without a word.

## Reaching your agent

Notes do not push. Your agent sees them when it calls `feedback_list` for the
whole project, or `card_get` for one card. Ask it to look. Nothing arrives
without a request.

A note carries no author, so an agent cannot tell your notes from anyone else's
on the page. A card that the widget creates does not make its note trusted
either. The shipped `loupe-site-review` skill therefore escalates by category.
It sends to you any note that would change a destination, an identity, a
credential or third-party code, and it does not act on it. For the same reason,
the widget belongs on staging only.

Live push over a Mercure hub, an outbox for undelivered events, and the
[command-line bridge](../extending/cli-bridge.md) are all still present, and
they do nothing now. Nothing publishes an event, so the outbox stays empty, and
the per-project and `/admin/outbox` pages have nothing to show. That part of the
feature is not finished. Pull with `feedback_list`.
