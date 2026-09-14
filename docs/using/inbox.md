---
title: "The inbox"
description: "The page where the project owner reads the questions and to-dos that agents hand over, answers them, and sees which answers can still change."
---

An agent that works for a long time produces questions and tasks that only a
person can finish. The inbox holds them. Each project has one inbox, and only
the project owner can read it or answer it.

The inbox is behind the `inbox.enabled` feature flag, and the flag ships off.
See [Turning the inbox on](#turning-the-inbox-on).

## Items and asks

An **item** is one question or one to-do. Each item has a number that counts
from 1 inside the project, so you can say "item 12" to an agent.

- A **question** offers options, a written answer, or both. The agent says
  whether you can pick one option or several.
- A **to-do** asks you to do something, such as reviewing a pull request.

An **ask** is the set of items that one agent session hands over at once. The
agent writes a short context for the ask, and the page shows that context above
the items. An item that blocks the agent carries a **Blocking** label.

## The inbox page

Open **Inbox** in the project sidebar. An amber count next to the link shows how
many items are still open. The link is absent while the flag is off.

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
project, so in English "exports" also finds "export". An item in the results shows its forms when it still takes a
response. Select **Clear** to go back to the asks.

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

## Changing a response

You can change a response until an ask that holds the item closes. After that,
the response is final, because an agent may already act on it. Send a
correction to the agent in some other way.

Each closed item says which case applies:

- "You can still change this response" shows the forms again, so you can pick
  other options, mark the item done or decline it.
- "This response is final" shows no forms. A change sent from an older copy of
  the page is refused with the same message.

An agent can also close an item itself, when it withdraws the item or the work
behind it finishes. Such an item takes no response from you.

## When an ask closes

An ask closes when you close its last blocking item. An answer, a done and a
decline all count. An agent's withdraw counts too, and so does an item that
closes because every card it links to finished. An item that does not block
stays open after its ask closes, and the page then lists it among the open
items outside an open ask.

One item can sit in several asks. Your response then counts toward each of
them, and each ask closes when nothing in it blocks any more.

When the ask came from a session that the command-line bridge started, Loupe
also tells that bridge. See
[The inbox.ask_closed event](../extending/cli-bridge.md#the-inboxask_closed-event).

## The projects list

While the flag is on, each row of the projects list also shows how many inbox
items the project has open.

## Turning the inbox on

`inbox.enabled` is seeded off. Open the flags page at
**`/admin/feature-flags`** and switch it on. The change needs no restart. See
[The admin area](admin.md).

The install wizard writes the flag row on a fresh install, and a database
migration writes it on an instance that upgrades. A missing row reads as off.
