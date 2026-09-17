---
title: "The inbox"
description: "Read and answer agent questions, review requests, and to-dos in the project inbox."
---

An agent that works for a long time produces questions and tasks that only a
person can finish. The inbox holds them. Each project has one inbox, and only
the project owner can read it or answer it.

The inbox is behind the `inbox.enabled` feature flag, and the flag ships off.
See [Turning the inbox on](#turning-the-inbox-on).

## Items and asks

An **item** is one question, review request, or to-do. Each item has a number that counts
from 1 inside the project, so you can say "item 12" to an agent.

- A **question** offers options, a written answer, or both. The agent says
  whether you can pick one option or several.
- A **review** asks you to approve a document or pull request, or request changes.
- A **to-do** asks you to do something, such as publishing release notes.

An **ask** is the set of items that one agent session hands over at once. The
agent writes a short context for the ask, and the page shows that context above
the items. An item that blocks the agent carries a **Blocking** label.

## The inbox page

Open **Inbox** in the project sidebar. An amber count next to the link shows how
many items are still open. The link is absent while the flag is off.

The count changes without a reload when an agent asks, when you or an agent
close an item, and when a finished card makes an item obsolete. It goes away at
zero. This needs a Mercure hub and the `live_updates.enabled` flag. Without
them, the count is correct each time a page loads. See
[Mercure hub](../extending/mercure.md).

The search field keeps its compact width beside **Clear**.
After a search, the result count remains visible without covering either control.

The page lists three groups, in this order:

1. **Open asks**, oldest first. Each ask shows the id of the agent session that
   asked, when it asked, its context and its items.
2. **Open items outside an open ask**. No agent waits on these items, but
   nobody has closed them yet. A to-do from an ask that already closed is the
   usual case.
3. **Closed asks**, newest close first, ten to a page.

An item can show in more than one place, for example in a closed ask and in the
second group. Its forms show once, where the page first lists it, and the other
places link there.

## Searching

Type in the search field above the asks. The page then lists the items whose
title or body holds your words, best match first, 25 to a page. Closed items
match too. The search matches whole words in the search language of the
project, so in English "exports" also finds "export". An item in the results
shows its forms when it still takes a response. Select **Clear** to go back to
the asks.

## When a bridge goes quiet

An agent that a CLI bridge started names that bridge when it asks. The bridge
can resume the agent after the ask closes. Each open ask from such an agent
shows when its bridge last sent a heartbeat.

The line turns amber when no heartbeat arrived in the last three heartbeat
intervals. With the default interval of 60 seconds, that is three minutes. It
also turns amber when no heartbeat from the bridge ever reached Loupe. A
running bridge keeps its interval until it reconnects, so the warning never
waits less than three default intervals, even after you lower the flag. While
the bridge stays quiet, no resume will come. The page cannot tell why the
bridge is quiet. The machine may be asleep, the bridge may have stopped, or
the network may be down. The warning reads only the heartbeat, and it does not
check whether the bridge still follows this project.

An ask from an interactive session names no bridge and shows no line. A closed
ask shows no line either. The `bridge.heartbeat_interval_seconds` flag sets the
interval. See [Bridge heartbeat API](../reference/bridge-heartbeat.md).

## Answering

Every item that takes a response shows its forms under its text.

- **Answer a question.** Pick an option, write an answer, or do both, as the
  question allows. Then select **Answer**. The page needs JavaScript to send
  the options you pick.
- **Mark a to-do done.** Select **Mark done**.
- **Decline an item.** Open **Decline**, write an optional note for the agent,
  and select **Decline this item**. Decline any item that you cannot or will not
  answer, including a question whose options do not fit.

The item then shows your response and its new state: answered, done or
declined.

Unsent question answers keep their text and selected options when you close and reopen a card drawer in the same browser tab.
Decline notes also survive drawer replacement. A restored note opens its Decline section so you can find it.
A successful submission clears that draft. A rejected submission keeps it for correction.
Drafts stay in memory and do not survive a reload or a closed tab.
If the request closes before you respond, its page shows your unsent answer or decline note separately from the recorded response.
Copy that draft if needed, or select **Discard draft** to clear it from this tab.

## Changing a response

Review results are final when submitted, even if no agent waits on the request.
The result keeps the verdict, note, reviewer, time, and reviewed document version.
Withdrawing a document verdict reopens document review and adds a withdrawal notice beside the original result.
It does not reopen the completed request, change its answer, or resume the agent again.

For questions and to-dos, you can change a response until an ask that holds the item closes. After that,
the response is final, because an agent may already act on it. Send a
correction to the agent in some other way.

Each closed item says which case applies:

- "You can still change this response" shows the forms again, so you can pick
  other options, mark the item done or decline it.
- "This response is final" shows no forms. A change sent from an older copy of
  the page is refused with the same message.

An agent can also close an item itself, when it withdraws the item or the work
behind it finishes. Such an item takes no response from you.

## Review requests

Open the linked document or pull request before choosing **Approve** or **Request changes**.
Request changes requires a note that explains what to change.
Select **Submit review** in the inbox or card conversation.

A document submission records the same verdict as the document review page.
It completes open Review requests that target that document; questions with document links stay open.
A PR submission records a result in Loupe only. It does not post a review to the code host.

A stale form cannot replace a newer document verdict or review a changed PR address.
The error keeps your note so you can copy it before reloading.
A removed target shows an unavailable state, while completed results retain their original target label and answer.

## Replies

Use **Reply to this thread** to add context without changing an answer.
Replies show their author and time in both the inbox and the card conversation.
You can reply to completed requests. A reply does not reopen the request or resume an agent.
The form accepts up to 2,000 characters and keeps an invalid draft for correction.
Unsent replies stay in this browser tab when you close a card drawer or follow an in-app link.
The browser asks before a reload or tab closure discards them. Signing out clears them.
Text you type while a reply is being saved remains an unsent draft after confirmation.

## When an ask closes

An ask closes when you close its last blocking item. An answer, a done and a
decline all count. An agent's withdraw counts too, and so does an item that
closes because every card it links to finished. An item that does not block
stays open after its ask closes, and the page then lists it among the open
items outside an open ask. A card that finishes while the inbox is off closes
no item and no ask.

One item can sit in several asks. Your response then counts toward each of
them, and each ask closes when nothing in it blocks any more.

When the ask came from a session that the command-line bridge started, Loupe
also tells that bridge. A bridge rule with `resume: true` then continues that
session, and the agent reads your answers. See
[The inbox.ask_closed event](../extending/cli-bridge.md#the-inboxask_closed-event)
and [Resume action](../extending/cli-bridge.md#resume-action).
An agent that reads its answers with its own session id as `readerSessionId`
records the read, so a bridge can skip the resume of a session that already read
every answer.

## All inboxes

Open **All inboxes** in the lower part of the sidebar to see what waits on you
in every project you own. The link is absent while the flag is off.

The page groups by project, in project name order, and shows each project that
has an open item. Each project shows how many items it has open, and its name
links to its inbox. Inside a project, the page lists two things:

1. **Open asks**, oldest first, each with its session, its context and the
   number, state and title of each item.
2. **Open items outside an open ask**, such as a to-do from an ask that already
   closed.

The page is read-only. Select **Answer in the project inbox** on an ask, or
**Respond in the project inbox** on an item outside an open ask. The link opens
that ask or item on its project inbox page, where the forms are.

## On a card page and a document page

An agent can link an item to cards and documents of the project. The page of
each linked card and each linked document then shows an **Inbox items** section
with those items. The section is absent while the flag is off, and on a page
that no item links to. On a document page it sits above the document.

The project inbox lists linked cards, documents, and the pull requests attached to those cards.
Each pull request URL appears once per item, even when several linked cards share it.
Web links open on the code host. Their status reads **Not reported** because Loupe does not fetch code-host status.
Other stored addresses read **Unavailable**, with an explanation and no open action.

A card link opens **Conversation** at the matching inbox item.
If that item falls outside the ten newest closed items, it replaces the oldest item in that list.
The section still shows ten closed items, in closing order.

The section is hidden on a version comparison. It shows on an older version of
a document, and a response from there returns to that version.

The section lists open items first, then closed ones under **Closed**, newest
close first. It shows at most ten closed items, and a link leads to the inbox
page for the rest. Above each item, two lines give the context of the newest ask
that holds it. **Open the inbox** leads to the full context.

Each item takes the same forms as on the inbox page, and the same rules apply.
After you respond, you return to the card or document page you came from. A
refused response shows its message there, beside the item.

Markdown in an item or an ask renders with no heading anchors, on this section
and on the inbox page, so it never takes the anchor of a document heading.

## The projects list

While the flag is on, each row of the projects list also shows how many inbox
items the project has open.

## Turning the inbox on

`inbox.enabled` is seeded off. Open the flags page at
**`/admin/feature-flags`** and switch it on. The change needs no restart. See
[The admin area](admin.md).

The install wizard writes the flag row on a fresh install, and a database
migration writes it on an instance that upgrades. A missing row reads as off.
