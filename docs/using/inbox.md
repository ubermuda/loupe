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
- A **waiting** item tells you that a card waits for you. Loupe opens it, not
  an agent. See [Automatic items](#automatic-items).
- A **notice** tells you about a fact of the project that needs a change.
  Loupe opens it and takes no answer. See [Notices](#notices).

An **ask** is the set of items that one agent session hands over at once. The
agent writes a short context for the ask, and the page shows that context above
the items. An item that blocks the agent carries a **Blocking** label.

## The inbox page

Open **Inbox** in the project sidebar. An amber count next to the link shows how
many items are still open. The link is absent while the flag is off.

The page has no header of its own. Its **View activity** and **Settings** links
sit in the top bar, and on a phone they show as icons.

The count changes without a reload when an agent asks, when you or an agent
close an item, and when a finished card makes an item obsolete. It goes away at
zero. This needs a Mercure hub and the `live_updates.enabled` flag. Without
them, the count is correct each time a page loads. See
[Mercure hub](../extending/mercure.md).

The page has two columns: a list of asks on the left and the selected ask on
the right. The list column opens with an **Open · N** and **Closed** switch, a
search field and a type filter.

### The address holds the view

The address of the page describes what you see, so a reload or a shared link
shows the same view. The page reads these values from the query string:

- `side` is `open` or `closed`. The address `?queue=completed` of an old link
  reads as `side=closed`.
- `type` is `question`, `review`, `todo`, `wait` or `workflow`. Without it the
  list shows every type.
- `q` is the search.
- `ask` is the id of the selected ask.
- `item` is the number of an item. The page opens the open ask that holds the
  item, else the newest closed ask that holds it, else the item on its own.
- `page` is the page of the closed asks or of the search results.

A value that does not parse falls back to its default. An `ask` of another
project selects nothing.

A click on a row, the switch, a filter, the search or the pager reloads the
list and the selected ask without reloading the page, and it adds a step to
the browser history. The back button returns to the view before. Each visit
from the sidebar starts on **Open** with every type and no search.

An old link of the form `#inbox-item-12` still works. The page turns it into
`?item=12`.

### The list

The **Open** side lists the open asks and the open items that no open ask
holds. No agent waits on such an item, but nobody has closed it yet. A to-do
from an ask that already closed is the usual case. Such an item is a row of its
own. An ask that holds an open blocking item comes first, then the others. Each
group is oldest first. The **Open** side shows every row.

The **Closed** side lists the closed asks, newest close first, ten to a page.

A row is one ask. It shows the icon of the first open item of the ask, and the
icon of the first item of a closed ask. The title of the first item follows.
A grey line below the title shows the first linked card and the age, such as
"#1 Checkout · 2h". An ask with more cards shows "+2" after the first. An ask
with no card shows its source, **Agent** or **Loupe**, instead. An ask that
holds an open blocking item ends the line with **Blocking**. A closed ask shows
how it ended: answered, approved, changes requested, done, dismissed, declined,
withdrawn or no longer needed.

The type filter has **All** and one icon for each type: question, review,
to-do, card wait and workflow question. Hover over an icon to read its name. A
filter keeps an ask when any of its items has that type.

Select a row to read its ask on the right. The ask takes its heading from its
first item. A grey line under the heading shows the type icon, the type and the
age of the ask, such as **Question · 3 weeks ago**. A closed ask also shows when
it closed.

A bold **From the agent** heading opens the message of the ask. An ask that
Loupe opened, such as a card wait or a workflow question, says **From Loupe**.
The items follow the message. An ask with one item shows that title once. An
ask with two or more items gives each item its own section, with a number, its
title and its type. Replies under an item show each author's initial, name and
time.

The agent's avatar beside **From the agent** carries a dot for its bridge:
green while the bridge sends heartbeats, amber when it is quiet, and grey when
no bridge started the session. Hover over or focus the avatar to read the
details and the session id.

A **Linked** panel sits beside the ask, or under it on a narrow screen. The
panel shows each card, document and pull request that the ask links, once, on
one line with its type icon. A review shows the version under review on the
document line. Select a line to open the card, the document or the pull
request.

When no ask is open, the **Open** side shows **Nothing needs you**, a line on
what agents ask here, and a link to the closed asks.

### The page follows changes

The page reads the inbox again when an agent, Loupe or another person changes an
ask while the page is open. Every member who can open the page gets this, not
only the project owner. A new ask appears in the list, an ask that closes leaves the **Open**
side, and the counts follow. The page keeps the ask you have open, so a new
blocking ask never takes its place.

A response you were typing stays in the form. When the agent withdraws the ask
you have open, or Loupe closes it because it is no longer needed, the ask stays
on screen. A line says why and links to the next open ask. The choices and the
buttons lock, and your note stays readable.

The page does not reload while a dialog is open. It reads the inbox when the
dialog closes. This needs a Mercure hub and the `live_updates.enabled` flag.
Without them, the page is correct each time it loads.

### On a phone

At phone width the list and the selected ask take turns. The page shows the
list until you select a row. Then it shows the ask, with an **All asks** link
above it. The link returns to the list.

## Searching

Type in the search field above the list. The page then lists the asks that hold
an item whose title or body holds your words, best match first, 25 to a page.
An ask ranks by its best item. Closed asks match too, and an open item that no
open ask holds is a row of its own. The search matches whole words in the
search language of the project, so in English "exports" also finds "export".
The type filter still applies to the results.

During a search neither side of the switch is pressed. A closed ask shows how
it ended in its grey line. Select **Clear** to return to the side you were on.

## When a bridge goes quiet

An agent that a CLI bridge started names that bridge when it asks. The bridge
can resume the agent after the ask closes. Each open ask from such an agent
shows when its bridge last sent a heartbeat, in the tooltip of the agent's
avatar, on the project inbox and on the account inbox.

The dot turns amber when no heartbeat arrived in the last three heartbeat
intervals. With the default interval of 60 seconds, that is three minutes. It
also turns amber when no heartbeat from the bridge ever reached Loupe. A
running bridge keeps its interval until it reconnects or you run
`loupe bridge reload`, so the warning never waits less than three default
intervals, even after you lower the flag. While the bridge stays quiet, no resume will come. The page cannot
tell why the bridge is quiet. The machine may be asleep, the bridge may have
stopped, or the network may be down. The warning reads only the heartbeat, and
it does not check whether the bridge still follows this project.

An ask from an interactive session names no bridge, and its dot stays grey. A
closed ask shows no dot. The `bridge.heartbeat_interval_seconds` flag sets the
interval. See [Bridge heartbeat API](../reference/bridge-heartbeat.md).

## Answering

Every item that takes a response shows its forms under its text.

- **Answer a question.** The **Your answer** block lists the options, and says
  whether you pick one or any. A hovered option turns grey, and a picked option
  turns pale lime. Pick an option, write a note for the agent, or do both, as
  the question allows. Then select **Send answer**. Select **Clear choice** at
  the right of the block heading to remove your pick. The page needs JavaScript
  to send the options you pick. A question with no options shows the same
  block with one field, where you write your answer.
- **Mark a to-do done.** Select **Mark complete**.
- **Decline an item.** Write an optional note for the agent in the note field,
  then select **Decline**. Decline any item that you cannot or will not answer,
  including a question whose options do not fit. A workflow question has no
  **Decline**, because Loupe needs one of its options.

The buttons sit at the right of the item, with the main button last. Only the
project owner sees them. Another member who can see the project reads the ask
and sees no buttons.

### After you send a response

The page opens the next ask. It takes the first ask below the one you closed,
in the list you looked at. When the closed ask was the last one, it takes the
ask above it. The filter you used still applies. An ask that still holds an open
item stays open on the right, so you can answer the next item.

A line above the ask says what happened. It names the ask that closed, such as
"Answer sent. Which format? is closed.", and links to it with **View it**. When
the ask stays open, the line counts the items that are left. The line shows
once, so a reload removes it.

When no ask is left, the page shows **Nothing needs you**. The closed ask is
still on the **Closed** side.

A search or the **Closed** side keeps the ask you answered. A response sent from
a card or a document page returns to that page, as before.

Unsent question answers keep their text and selected options when you close and reopen a card drawer in the same browser tab.
A closing note also survives drawer replacement.
A successful submission clears that draft. A rejected submission keeps it for correction.
Drafts stay in memory and do not survive a reload or a closed tab.
If the request closes before you respond, its page shows your unsent answer separately from the recorded response.
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
  other options, mark the item done or close it.
- "This response is final" shows no forms. A change sent from an older copy of
  the page is refused with the same message.

An agent can also close an item itself, when it withdraws the item or the work
behind it finishes. Such an item takes no response from you.

## Review requests

Open the linked document or pull request before choosing **Approve** or **Request changes** in the **Your verdict** block.
In a document review, Request changes needs a note only when the version has no open comment.
A pull request review always needs a note that explains what to change.
Select **Submit review** in the inbox, or on the card that links the request.

A document submission records the same verdict as the document review page.
It completes open Review requests that target that document; questions with document links stay open.
A PR submission records a result in Loupe only. It does not post a review to the code host.

A stale form cannot replace a newer document verdict or review a changed PR address.
The error keeps your note so you can copy it before reloading.
Unsent review notes and verdict selections survive drawer replacement in the same browser tab.
They keep their original document version, previous verdict identifier, or PR address.
If that review state changes, the restored form shows a warning.
Inspect the current content and copy your note before selecting **Discard draft** to start a fresh review.
If the request closes or its target becomes unavailable, the request keeps your unsent note and verdict visible for copying or discarding.
Discard clears only this tab's draft. It does not change the recorded review.
A removed target shows an unavailable state, while completed results retain their original target label and answer.

## Automatic items

Loupe opens an item by itself when a card waits for a person. A card waits in
these cases:

- A linked document of the card is in review, and the workflow of the card
  waits for it in the column where the card sits. With the Lifecycle template,
  that is a product design, a document with the tag `product-design`, in the
  Product design column, or a tech design, a document with the tag
  `tech-design`, in the Tech design column.
- The newest worker run of the card is blocked, gave up, or waits for a person.
  The card must stay in the column that started the run.
- A GitHub pull request that is linked to the card is ready for review.
- The workflow paused the card. The wait names the reason code of the pause,
  and it ends when the pause is released. A new pause starts a new wait.

With these templates, the app publishes the draft after its agent review, so the
item for a design opens then, and not when the writer ends. A draft that you
publish or review yourself opens it at once. See
[Agent review of a design](workflows.md#agent-review-of-a-design).

Other linked documents, such as a plan, open no wait. A change to the tags of
a document makes Loupe check each card that links it again.

The item has the kind **Waiting**, and it is always blocking.

A card has at most one open automatic item. Its title is the card number, the
reason of the main wait, and the card title, such as "#686 Review worker
blocked: cannot reach github.com · Let bridge workers read the prototypes". The
item links the card and each document that it waits on. Its panel reads as a
thread, described in the next section. Each current wait is one message. A
message that has no worker report is one plain sentence. The sentence says why
Loupe opened the item, such as "The document "Tech design", version 1, of card
635 waits for your review."
A run wait starts with the first sentence of the run output, such as "The
review worker on card 686 is blocked: cannot reach github.com". When a
workflow pause of the card comes from that run, the panel shows no second
line. The link of the run line then reads "Fix the cause, then resume the
card" and opens the card. A run wait shows the whole output of the run below
the sentence, with the stage protocol text and the reason tag removed. The
body of the item that `inbox_get` returns holds the same sentences. The waits
that ended show below them, in grey, with a sentence that says how they
closed, such as "Closed when you acted on it."
**Dismiss** hides the item. The card still waits until you act on it.

The row and the panel name **Loupe** as the sender, with a magnifier icon. They
show no bridge dot and no session, because no agent session asked for the item.

The messenger worker opens and updates these items. A change on the board, on
a document or on a pull request therefore shows after a short delay.

Loupe also checks the automatic items of every project every 15 minutes. A
wait that Loupe did not see when it started or ended opens or closes its item
on the next check.

### The thread and the split button

An automatic item reads as a thread. Each current wait is a message from its
sender. A message from a worker names the worker and says when it stopped, such
as "Implement worker stopped 12m ago". A message from Loupe says when Loupe
noticed the wait. A run wait shows the whole worker report in the message, with
file names and check names as code. The waits that ended show below the current
ones, in grey.

Under the messages, a reply card lets you act on the item without leaving the
inbox. The main action is the one that the stopped worker recommends, when it
fits the item. Otherwise it follows the newest wait. The heading of the card
shows the reason that the worker gave for the main action. With no reason, it
names the action, such as "Resume the run". The note field is optional and holds up to 2000 characters. The note
stays in Loupe, on the item. The split button at the bottom right of the card
runs the main action. The chevron beside it opens a menu with the other
actions that fit the item. Each row of the menu names the action and says in
one line what it does. Escape, or a click outside the menu, closes it. The arrow
keys move between the rows.

The actions are:

- "Resume the run" continues the session of a stopped worker. The wait ends
  when the new run starts.
- "Retry the card" releases a pause of the kinds that a person can release.
  A pause from a workflow rule cannot be released here.
- "Review" opens the review page of the document, or the pull request on
  GitHub, for a review wait.
- "Send an agent" starts a new worker for the stage work of the card, such as
  an implement worker for a card in Implementation. It never resumes an
  earlier session. It fits only when the column of the card names stage work,
  no request for that work is live, and no worker runs on the card. See
  [Workflow templates](workflows.md).
- "Open" opens the card, or the document. A card page offers no link to
  itself.
- "Start an interactive session" opens a terminal on your machine. See
  [Start an interactive session](#start-an-interactive-session).
- "Run a command" runs a command of your rule file on your machine. See
  [Running a command](#running-a-command).
- "Dismiss" closes the item as declined. It sits apart at the bottom of the
  menu, in red. Dismiss ignores the note.

Loupe shows only the actions that fit the item now. It picks the main action
from the newest open wait: Resume the run for a stopped run, Retry the card for
a pause that you can release, Review for a review, and Open for the rest. When
only Dismiss fits, the card shows a Dismiss button and no menu.

### Titles and suggestions from the agent

A worker that stops can name the stop and suggest what to do next. It adds a
`title` and up to three `suggestedActions` to its structured result. See
[the bridge guide](../extending/cli-bridge.md). The item
uses the title as its heading, cut to 80 characters, with the full text in the
tooltip. Without a title, the heading is the first sentence of the report.

Each suggestion names one of the actions above and gives a reason. The
reason shows as the heading of the reply card when that action is the main one.
A suggestion that the worker marks `recommended` becomes the main action, but
only while that action fits the item. A suggestion for an action that does not
fit is dropped.
An agent can also attach `suggestedActions` to a to-do of `inbox_ask`. A to-do
that carries suggestions shows a reply card with a split button, beside Decline
and Mark done. The button offers the suggested actions that fit the to-do now,
and the recommended one is the main action. A to-do with no suggestion that
fits shows no split button. A question with suggestions shows a reply card
with a split button below its answer form. The button offers the actions that
fit the question now, and the recommended one is the main action. That card
takes no note, because the answer carries your words. See [MCP](mcp.md#what-the-tools-do).

Neither action asks for a confirmation. Retry the card acts at once, and its
status line reads "Retried at" and the time. Resume the run asks the bridge to
resume the session. Send an agent and Start an interactive session each queue
work for a bridge. In each case the reply card turns into a tinted card with
your name, your note and a status line. The status line of a resume uses the
run states of the Activity page:

- "Queued" while the command waits for a bridge. When no bridge is online,
  the card adds "No bridge is online."
- "Cancel" shows beside Queued. It withdraws the command, and the reply card
  comes back.
- "Preparing" when the bridge took the command, then the state of the new run,
  such as "Running".
- "Expired", "Refused" or "Cancelled" when the command did not run. The
  reply card comes back below the status.

An action does not close the item. The item closes when its last wait ends,
such as when the new run starts after you resumed the run. When the new run
stops again, Loupe opens a new wait for the card. While another wait of the
item stays open, such as a document in review, the reply card stays under the
status line, so you can still act on that wait.

The card page shows the same thread in its **Inbox items** section, with the
same buttons. After you act there, you return to the card page. The document
page shows no thread.

### Running a command

A worker can suggest that you run one command entry of your rule file. The
button then reads "Run <entry> on <branch>", such as "Run design-export on
main". A worker can suggest it on a to-do, or at the end of a stopped run.

The button is off, with the reason beside it, when Loupe cannot offer the run.
The reasons are:

- No bridge that follows the project asked for this.
- The bridge is too old to run commands from the inbox. Update the CLI.
- The rule file of the bridge has no command of that name. When the agent
  proposed the entry, see [Adding a proposed entry](#adding-a-proposed-entry).
- The command uses `{workRequestId}` or `{project}`. The bridge fills these
  only at the run, so Loupe cannot show the command before you run it.
- The command uses `{cardId}` or `{cardNumber}`, and the item has no single
  card.
- A value does not fit the pattern of its parameter.
- The bridge has no worktree for the branch.
- The command is already queued or running for this item.

An offline bridge does not turn the button off. Loupe checks the run against
the last heartbeat of the bridge. The run then shows "Queued" and "No bridge is
online", with Cancel, until the bridge comes back.

Only the owner of the project can run a command. Select the button, and Loupe
opens a confirm step. It shows the bridge, the branch, the value of each
parameter, the folder, and the command as the bridge runs it, with the
parameter values and the built-in placeholders filled in. The branch list holds
each branch that has a worktree on the bridge. When the branch of the worker has
no worktree, the list shows it off, and Run stays off until you pick another
branch. Check the filled command, then select **Run**. Nothing runs before you
do. When the bridge reports a changed command before you select **Run**, Loupe
refuses the run and asks you to read the command again.

The bridge checks the request again, with its own rule file. It refuses the
run, and the item shows the reason, in these cases:

- `entry-missing`: the rule file has no command of that name.
- `entry-changed`: the command in the rule file changed after you confirmed it.
- `param-invalid`: a value does not match the whole pattern of its parameter.
- `worktree-missing`: the bridge finds no worktree for the branch.
- `placeholder-missing`: the command needs a value that the item cannot give,
  such as a card number for an item with no card.

A refused run starts nothing.

The status line shows the run as Queued, Running, Succeeded or Failed. While the
command runs, the line shows its output as the command writes it, under a
**Live** marker. The block follows the end of the output. Scroll up to read
earlier lines, and the block stays where you are. When the run ends, the line
shows the last 10 lines, and a Succeeded run shows its time, such as "Succeeded
in 14 s". A command that waits for a worker of its card stays Queued until that
worker ends.

An ended run that has stored output also shows **Open the full log**, with the
number of lines. The link opens the whole log as plain text in a new tab. On a
failed run, the link sits left of **Mark done** and **Run again**.

A success ends the item. A to-do closes as done, and the worker that waits for
the answer resumes. For a stopped run, Loupe resumes the worker, which reads the
result of the command with `inbox_get`. A failure keeps the item open. The
status line shows the exit code and the last lines, and **Run again** starts the
same command again.

Two limits apply:

- The pattern of a parameter is as strict as the operator writes it. A loose
  pattern lets a worker pass a value that the command reads as an option, such
  as `--exec=...`. Write each pattern to match only the values that you accept.
- The output can contain what the command prints, including secrets. Loupe
  stores up to 1 MiB of it for each run, and everyone who can see the project
  can read it, live and in the full log. Loupe deletes the log with the run.

Loupe stops storing a log at 1 MiB. The full log then ends with the line
"Loupe stopped storing the log at 1 MiB", and the command still runs to its end.
A run from a bridge that is too old to stream shows the last lines after the
run ends, and no link to a full log. See
[the bridge guide](../extending/cli-bridge.md#running-an-entry-from-the-inbox)
for the rule file.

### Adding a proposed entry

An agent can propose a command entry that the rule file of the bridge lacks.
The item then shows the entry, and the Run button stays off.

The reason line says that the entry is not in the `rules.yaml` of the bridge.
Loupe adds the name of the entry to the text that the agent sent, so you read
the same text that the bridge writes. What happens next depends on the bridge.

When the bridge opted in with `acceptEntries: true` in its rule file, the main
button reads **Add to rules.yaml**. The same action sits in the menu when
another action is the main button. Select it, and Loupe opens a confirm step.
The step shows the full entry and the name of the bridge. It says that nothing
runs yet. Select **Add the entry** to send it.

Only the owner of the project can add an entry. The status line then shows
"Queued", and Cancel withdraws the request while no bridge has taken it. The
bridge checks the text, adds the entry to the `work:` block, and reloads. Its next heartbeat lists the entry. The status line shows "Added to
rules.yaml", and the Run button turns on by itself. A run still asks you in its
own confirm step.

The bridge refuses a text that is no command entry, a name that the file
already holds, and a file that it cannot change safely. It also refuses when
the file changed while it added the entry, or when the reload after the write
failed. The status line then shows "Refused" and the reason code:
`entry-invalid`, `entry-exists`, `not-accepted`, `write-failed`,
`file-changed` or `reload-failed`. A refused add changes nothing. The button
comes back, so you can try again. Add the entry by hand when the bridge keeps
refusing.

When the bridge did not opt in, the reason line says so. The item shows the
entry with a **Copy** button. Add it under `work:` in the rule file by hand,
then run `loupe bridge reload`. The Run button turns on by itself.

An added entry runs any command with the rights of the bridge user, after you
confirm each run. Turn on `acceptEntries` only for a bridge that you trust with
that. See [the bridge guide](../extending/cli-bridge.md) for the key.

### Notices

A notice states a fact of the project. It does not block an agent and takes no
answer. A notice may link the cards that the fact is about.

Loupe opens the notice **No bridge can take work** when the work of a card waits
for 10 minutes and no online bridge can take it. A paused bridge keeps the work
waiting, and Loupe opens no notice for it.
The notice lists each card that waits. It tells you to start a bridge, or to run
`loupe login`. When more work starts to wait, Loupe adds its card to the open
notice. A project has one such notice at a time. The inbox switches do not
turn it off.

Loupe closes the notice as **done** when a bridge can take the work again. The
note says when no bridge could take work, and how many cards waited. Loupe closes
it as **obsolete** when the waiting work ended and no bridge came back, for
example when the work was cancelled. It also closes the notice as obsolete when
you switch the inbox off.

Loupe once opened a notice while a bridge rule raced the sync of the project.
Bridge rules are gone, so Loupe closed each such notice as **obsolete**, and
opens no new one.

### Workflow questions

A workflow question comes from a rule of the board workflow, not from an agent.
Loupe opens it when a rule needs a decision about a card. Today the
`unplanned-child` rule of the Lifecycle template asks it. See
[Asking about an unplanned child](workflows.md#asking-about-an-unplanned-child).

The item has the kind **Workflow question**. It always blocks, links the card,
and names **Loupe** as the sender. It has two or more options. You pick one
option and send it. It takes no free text.

The answer is final. You cannot change it, you cannot close the item without
an answer, and an agent cannot withdraw it. Loupe runs the option you picked
after you answer. The ask that holds the item closes with your answer.

An open workflow question closes as **withdrawn**, with no answer, in these
cases:

- The rule stops holding. For example, the card links an approved tech design.
- Someone puts a hold on the card.
- Someone deletes the card.

With the inbox off, the rule opens no question. The card pauses instead.

### Pull request waits

Only a GitHub pull request gives a wait. Loupe reads the state of the pull
request, and a pull request that Loupe did not read yet gives no wait. A pull
request that is linked to two cards gives a wait on each card.

A pull request is ready for review when all of these are true:

- It is open, and it is not a draft.
- Its checks passed on its newest commit.
- It is mergeable, it is behind its base branch, or GitHub blocks the merge
  until a review.
- It needs a review, or it has no review rule. A request for changes on an
  older commit also counts, because the author pushed a fix after it.
- No worker run of the card is open.
- It does not merge with no approval. A pull request of an epic child into the
  epic branch of its parent merges there with no approval, so it gives no wait.
  The epic's own pull request into the main branch still gives one. A child
  pull request into the main branch gives one too.

An approval ends the wait. So does a request for changes on the newest commit,
a new commit whose checks did not pass yet, a merge and a close. A pull request
with changes requested waits again after a new commit with passing checks.

An approved pull request waits again when it gets new commits after the
approval. The wait says "Pull request #N of card C changed after you approved it". A merge from the base branch does not
count, with or without a conflict resolution. A force push, such as a rebase,
counts, so you approve the rewritten branch again. A new approval of the newest
commit ends the wait.

A workflow that stops asking for fixes pauses the card, and the pause opens
the wait.

### Choosing which waits open an item

Each project has seven switches, one for each cause of a wait:

- **Document in review**
- **Run blocked**
- **Run gave up**
- **Run waiting for a person**
- **Pull request ready for review**
- **Pull request fix loop stopped**, which no longer opens a wait
- **Workflow paused the card**

The switches are on the inbox settings page, at **Project settings > Inbox**.
The **Settings** link in the top bar of the inbox page also opens it. Only the
project owner can open the page. Every switch is on until you change it. An
agent connected to the project can change the switches with the
`inbox_settings_update` [MCP tool](mcp.md#changing-the-project-settings). A
switch that the call omits keeps its value.

When you save, Loupe checks every card of the project again. A wait that
already exists opens an item when you turn its switch on. A wait ends when you
turn its switch off. Its item closes as **obsolete** when no other wait holds
it open.

### When an automatic item closes

A wait ends when its document leaves review. A verdict does this, and so does
an archive. A new version of the document also ends the wait, and a new wait
for the new version replaces it. The item then stays open. An open review
request from an agent for the same document holds back the wait, because it
asks for the same verdict. A document wait also ends when the card leaves the
column that waits for the document, or when the document loses its tag.

A run wait ends when a newer run of the card starts. It also ends when the card
moves to another column.

The item closes when its last wait ends:

- It closes as **done** when its last wait ended by its own cause, such as a
  verdict, an archive, a newer run, an approval of a pull request or a move of
  the card.
- It closes as **obsolete** when the card finishes or someone deletes it.
- It closes as **obsolete** when you turn off the switch of its waits on the
  inbox settings page.
- It closes as **obsolete** when an administrator turns the inbox off. The
  15-minute check closes it.

The Loupe ask that holds the item closes with it.

### Dismiss

Open the menu of the split button and select **Dismiss** to close an automatic
item that you do not need. The item closes as declined. Dismiss takes no note. Loupe does not open the item again
for the same document version, the same run or the same pull request commit. A
new version of the document opens a new item, and so does a new run that
waits. A pull request wait holds the dismiss for one commit: a new commit that
is ready opens a new item.

Dismiss and the actions of the split button are the only responses that an
automatic item takes. You cannot answer it or mark it done. An agent cannot withdraw it, and the `inbox_withdraw` tool
refuses it.

### Start an interactive session

Select **Start an interactive session** on an item that has a card, to work
with an agent in a terminal on your machine. The action needs an online bridge
with a `session` entry in its rule file. Without one, the action stays grey and
says that no bridge can open a session. The bridge opens a new session on the
card. The prompt names the inbox item, and the agent reads your note with
`inbox_get`.

An agent can suggest the action with a prompt for the session. The inbox then
shows the prompt in a text field above the action. You can edit the text. The
session runs the text that you leave in the field. When you clear the field,
the session starts with no suggested prompt. The limit is 2000
characters, and an admin changes it with the `inbox.session_prompt_max_length`
flag on the flags page. The action hides only while a session request of the
same card is live. You can cancel the action until a bridge takes it. A request that
no bridge takes within 2 minutes expires. See
[Session entry](../extending/cli-bridge.md#session-entry).

## When an ask closes

An ask closes when you close its last blocking item. An answer, a done and a
close all count. An agent's withdraw counts too, and so does an item that
closes because every card it links to finished. An item that does not block
stays open after its ask closes, and the page then lists it among the open
items outside an open ask. A card that finishes while the inbox is off closes
no item that an agent asked, and no ask of an agent.

One item can sit in several asks. Your response then counts toward each of
them, and each ask closes when nothing in it blocks any more.

When the ask came from a session that the command-line bridge started, Loupe
asks that bridge to resume the session, and the agent reads your answers. A run
that is still open when the ask closes resumes when it ends. See
[Pause, stop and resume](../extending/cli-bridge.md#pause-stop-and-resume).
An agent reads its answers with its own session id as `readerSessionId`.

## All inboxes

Open **All inboxes** in the lower part of the sidebar to see what waits on you
in every project you own. The link is absent while the flag is off.

The page shows one row for each project you own that has an open item, in
project name order. Each row shows the first letter of the project, its name,
the number of open asks, and how long its oldest open ask or item waits. An open
item that no open ask holds counts as one ask, as on the **Open** side. A row
also shows **Blocking** when it holds an open item that an agent waits on, in an
open ask or outside one.

Select a row to open the inbox of its project, where the forms are. The page
itself is read-only.

## On a card page and a document page

An agent can link an item to cards and documents of the project. The page of
each linked card then shows an **Inbox items** section with those items. The
section is absent while the flag is off, and on a page that no item links to.

A document page shows an hourglass and the card numbers in its byline instead.
Each number links to its inbox item. Its tooltip reads **Card #N waits on this
review**, or **Cards #N and #M wait on this review** for two or more cards. The
item is absent while the flag is off, and when no card waits on the document.
Answer the item in the inbox.

The **Linked** panel of the project inbox lists linked cards, documents, and the pull requests attached to those cards.
Each card, document and pull request URL appears once per ask, even when several items or cards share it.
Web links open on the code host. Loupe does not fetch code-host status.
Other stored addresses show a note that they are not web addresses, and have no link.

A card link opens **Conversation** at the matching inbox item.
If that item falls outside the ten newest closed items, it replaces the oldest item in that list.
The section still shows ten closed items, in closing order.

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

The `inbox.session_prompt_max_length` flag sets the longest prompt that an agent
can suggest for a session. It is an integer, and it defaults to 2000.
