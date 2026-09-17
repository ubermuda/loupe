---
title: "Documents and review"
description: "Submitting Markdown, reviewing it inline, and revising across versions."
---

A document belongs to a project. It carries Markdown, a title, an optional
description, tags, an optional place in a series, and links to other documents;
each submission mints a new **version**, and the review UI keeps every one of
them.

Search and the status filter stay on one row, including on narrow screens.
Tag and series filters remain available when the project uses them.

## Creating

Open Documents in a project, then select **New document**.
Enter a title and Markdown.
When Board is enabled, select the cards to link.
Select **Create document**.
Loupe opens the first version with Draft status.
You can review a draft with **Finish review**, or edit it with **Revise**.
Documents submitted through the agent tools start with In review status.

## Revising

Select **Revise** beside the document title to edit its title and Markdown.
Enter a revision note, then select **Save new version**.
Loupe creates a version and keeps the previous version unchanged.
Unchanged sections keep their approvals.
The Linked cards field keeps the current selection until you change it.
Clear a checkbox to remove that card link.
The picker offers open cards and keeps linked cards available after they finish.
The document returns to Needs review.

If another revision arrives while you edit, Loupe keeps your draft and refuses the submission.
Compare your draft with the current version before you submit a new revision.

## Reviewing

`/projects/{projectId}/documents/{documentId}/review` renders the current
version. A reviewer selects a passage, comments on it, and the comment is
anchored to the text it quotes. A comment may carry a **replacement** — what the
reviewer wants that passage to become — which the author applies by rewriting
the Markdown and submitting a new version. Loupe never edits the document
itself.

Comments, suggestions and strikes apply to the version shown on the page.
If a newer version arrives, Loupe rejects the submission and keeps the draft open.
Copy the draft before reloading, then select the passage in the current version.

Threads carry a status: pending, addressed, or resolved.
Select **Finish review** beside the document title to approve the version or request changes.
A request for changes requires a review note. An approval can include a note.
The saved verdict shows the reviewer, version, time and note.
The account export and `document_get_review` result include the note.
Open threads and unapproved sections do not prevent approval.

A verdict applies to the version shown when the reviewer opens the page.
If another verdict or revision arrives first, Loupe rejects the submission and keeps the note visible.
Reload the page before submitting a fresh verdict.

Select **Undo** beside a saved verdict to withdraw it and reopen review.
The history retains the original verdict and its withdrawal.
If another verdict or revision arrives first, Loupe rejects the old Undo form.
Reload the page before withdrawing the current verdict.

The margin has Comments, Outline, Decisions and Details tabs.
Outline and Decisions show current progress and link to passages in the document.
Details shows linked cards, outgoing and incoming references, tags, series and version notes.
In Details, select **Copy review summary** to copy this version’s threads, including resolved threads and replies.
The summary includes quoted passages, replacement text and unanchored markers.
The History tab opens the full version list.

Use Left and Right Arrow to select the adjacent tab.
Use Home or End to select the first or last tab.
Tab moves focus out of the tab list.
Switching tabs preserves an unfinished reply.

The filter beside Comments shows counts for Open, Resolved, Unanchored and All.
The selected filter stays active when you resolve or reopen a thread.
An empty result shows a message in the margin.

When the inbox is on, the review page lists the inbox items linked to the
document above it, and you can answer them there. See
[On a card page and a document page](inbox.md#on-a-card-page-and-a-document-page).

An inbox Review request names the document to review.
Submitting its verdict from the inbox or a card records the same document review as **Finish review**.
A document verdict completes all open Review requests for that document.
Questions that link the document as context stay open.
Withdrawing the verdict reopens document review and shows the withdrawal beside each completed request's original answer.
The completed requests stay closed and do not resume their agents again.

The documents list answers one question per row: does this document wait for
you? A row reads **1 thread waiting for you**, and counts up from there. A
thread waits for you when the agent marked it addressed and nobody confirmed it
yet. It also waits when it is still pending and orphaned, because nobody acted
and its anchor is gone. A pending thread that still points at real text waits
for the agent, so it adds nothing. A row with nothing waiting stays empty, which
is what makes the waiting rows easy to find.

The review top bar carries the full picture for the version on screen: the open
and resolved counts, a chip that counts the addressed threads, and **All
answered** when no thread is pending. The banner above the document counts the
orphaned threads. Every count is a thread count, so a reply never adds to one.

The General comments and orphaned-thread buttons expand or collapse their groups.
Each button reports its expanded state to assistive technology.
You can reverse a panel transition with another click. Panels, dialogs, and flash
messages skip their movement when your system requests reduced motion.

### Deleted threads

Delete hides a thread and its replies from the review without changing their status.
Select **Undo** in the deletion notice to restore the thread immediately.
Deleted threads have no expiry date.
In Details, select **Deleted threads** to find retained threads from every document version.
Select **Restore thread** to return a thread to its original version.
A restored thread on an older version stays read-only.

Select **Purge permanently**, then confirm, to remove a thread and all its replies.
Purge cannot be undone.
The audit log records one thread deletion with its reply count and deletion sequence.
Permanent purge records the root and every reply.
Deleting the project or account also removes its retained threads.

### On a narrow screen

On narrow screens, the workspace tabs and context tabs use separate rows.
The context panels appear below the document.
The corner menu also provides section navigation, version links, references and review actions.

A wide window puts comment cards beside the document, aligned with their passages.
Each card shows its author, status, body, replies and actions.
Cards move down when necessary to prevent overlap.
Reply opens an inline form and preserves its draft when closed.

A comment does not repeat its highlighted passage.
Suggestions and strikes retain the quoted text.
A thread whose passage is absent from this version also retains its quote.

On narrow screens, comment cards stack below the document in passage order.
Resizing the window preserves open reply forms and their drafts.

Touch works the same way as a mouse. Select a passage and the comment toolbar
appears. A highlighted passage has no hover, so a tap on one takes its place: the
passage and its card light up together, and a tap on plain text drops the pair
again.

Three views help across versions:

- `/review/versions/{versionNumber}` — any earlier version as it read then.
- `/review/diff/{from}/{to}` — what changed between two versions.
- `/review/history` — every version, newest first.

Open **History** from the document navigation to see every version, newest first.
Each version shows its revision note and review log.
Each review shows the reviewer, verdict, note and time.
Withdrawals remain beside the original verdict. Versions without reviews say so.
History keeps the document header and the Document, Diff and History tabs.
Use **Revise** or **Finish review** there to act on the current version.
The history page also has a picker that compares any two
versions, not only two that follow one another.

### What a comparison looks like

A comparison is the review page with one pane replaced, so the document keeps its
place on the screen. A green chip in the metadata bar names the pair, and the ×
on the chip returns you to the latest version. One row under it holds the view
switch, **Rendered**, **Markdown** and **Side by side**, with the change count
and the two jump arrows at the right end. `j` and `k` move between changes as
well.

**Side by side** puts the two versions in two columns, the older one on the
left. Each block sits opposite the block it became, so a reworded paragraph
reads whole on both sides. Where one version has nothing, that side shows an
empty slot and the pair stays level. The change count and the jump arrows work
here too, and a jump can land in either column.

Comments appear below the comparison so both columns keep their reading width.
Select text on the new side to annotate the current version.
The old side and comparisons of earlier versions remain read-only.
On a phone the columns stack, older above newer, and each names its version.

**Markdown** compares the two sources line by line, so it shows a change the
other views cannot mark. Its contents list names every heading line, the removed
ones included, and a row takes you to that line.

The toolbar includes the from/to picker and **Compare** button.
Version selectors, Compare, and diff-view buttons use the same control height.
Touch screens retain larger targets.
Comparing another pair keeps the selected view.
Equal versions show no changes and disable change navigation.
Expand **Revision notes** to read the notes for the compared revisions.

### Commenting on a diff

A diff accepts comments when its newer side is the current version. The comment
is an ordinary comment on that version, so it reads and re-anchors like every
other one.

Text the revision removed cannot be commented on, because the current version no
longer holds it, and the page says so when you select it. A diff that ends at an
older version stays read-only, because a comment made there would anchor to a
version nothing reads back.

## Section approvals

The verdict covers the whole document. A reviewer can also approve one section
at a time. A section runs from one heading to the next heading, whatever the two
levels are.

A round button sits beside each heading in the document. It approves that
section, and it withdraws the approval again. The **Sections** panel above the
document lists every section with its state, as an overview; the button beside
the heading is where you act.

Loupe stores each approval against the heading and against a digest of the
section's own text. A revision keeps an approval only while both still match, so
a section you left alone stays approved and a section you rewrote comes back
unapproved. This is how a multi-round review says "these parts are settled, read
the rest".

Section approvals sit beside the whole-document verdict and do not replace it.
`document_revise` reports `sectionsCarried` and `sectionsDropped` next to the
comment counts, and `document_get_review` returns a `sections` list that says how
many reviewers still approve each one. See [The MCP endpoint](mcp.md).

## Revising

Submitting a new version **re-anchors** open comments onto it. A comment whose
quoted text still appears carries forward; one whose text is gone comes back
**orphaned**, because its anchor no longer exists. Rewriting the exact passage a
reviewer asked about therefore orphans that comment — normal, and not a reason
to avoid revising.

Re-anchoring copies a comment onto the new version rather than moving it, so
**comment ids do not survive a revision**. Reply to comments and mark them
addressed *before* submitting the new version, or re-read the review afterwards
and use the fresh ids. Both operations reject a stale id rather than silently
writing into a row nobody reads.

Deleted threads reject replies and status changes. The
`document_mark_comment_addressed` tool skips a deleted thread with the reason
`deleted`. Historical threads remain read-only for Reply, Resolve, and Reopen.

## Decision blocks

A document can ask the reviewer a question they answer by clicking rather than
by typing. Wrapping a list of options in a pair of HTML comments carrying an
identifier renders it as a group of radio buttons, and the answer comes back
with the review:

```markdown
<!-- decision: reset-link-host -->

Which host should an emailed reset link be built from?

1. Drop `x-forwarded-host` from `trusted_headers`
2. Generate emailed links from a pinned `default_uri`

<!-- /decision -->
```

Select your choices, then select **Save decision**.
Choices remain unsaved until you select that button.
The status line confirms the saved version.
If another answer changes before you save, reload and compare your choices.
Older per-option forms also reject changes based on an outdated answer.

The identifier is permanent. The answer is stored against the id rather than
against the words, so options can be reworded freely in a later version —
but **changing the id discards the answer**, with no error and no warning.
Treat it like a database column name.

A block can also take more than one answer. Mark every option `- [ ]` and Loupe
renders checkboxes, so the reviewer ticks any number of them:

```markdown
<!-- decision: ship-with -->

Which of these ship in the first release?

- [ ] The importer
- [ ] The exporter
- [ ] The admin page

<!-- /decision -->
```

Mark every option `- ( )`, or mark none of them, and the block takes exactly one
answer. Loupe strips the marker from the rendered document. A list that mixes
the two markers, or marks only some of its options, degrades to a plain list.

A multi-choice block reports its answers in `selections`, and reports null in
`selected`. Every fence written before checkboxes existed keeps its answer.

The comments are invisible in every other Markdown renderer, so a document read
outside Loupe still shows a plain list.

## Annotations

An HTML comment that is not part of a decision block renders as a visible note.
A comment on its own line becomes a block note. A comment inside a paragraph
becomes an inline note.

```markdown
<!-- note: this section still needs the migration numbers -->
```

**Do not wrap the comment in an HTML element that opens its own block.** Loupe
reads that whole region as one block of raw HTML. It keeps the wrapper and
drops the comment. The note then disappears, and nothing warns you:

```markdown
<div>
<!-- this note never appears -->
</div>
```

Blockquotes and list items are Markdown rather than raw HTML, so a comment
inside one renders as a note. The comments stay invisible in every other
Markdown renderer.

## Series

Tags say that documents belong together. A **series** also says in what order
you read them. A series has a name and belongs to one project. A document
belongs to at most one series, and holds a position in it, counting from 1.

Set the name and the position together. A position with no series numbers
nothing, and a series with no position cannot be read in order, so Loupe rejects
either one on its own. Two documents in one series may not hold the same
position. Two different series may both use position 1.

An agent sets the placement when it submits the document, with the `series` and
`seriesOrdinal` parameters of `document_create`. It can also move a document
later with `document_set_series`, or take it out of its series. Loupe stores the
name as its author spells it, and creates the series the first time a document
names it. Two spellings that differ only in case or spacing are one series, and
the first spelling is the one every reader sees. Use `series_rename` to change
it.

The documents list gets a series filter beside the tag filter. Pick a series and
the list shows only its documents, in their own order rather than newest first.
A document page shows the series and the position under the title.

Renaming a series keeps every document in place. A name another series already
holds is refused rather than merged, because two series carry two independent
numberings.

## Highlights

Highlights tint the passages a reviewer should read first. They carry no body
and cannot be replied to — they steer attention, nothing more. They belong to
the version current when they are set, and a new version drops them.

## The search language

Search stems words, so it must know the language a document is written in. Every
document carries its own.

A project holds the default. You choose it when you create the project, in the
**Document language** field on the new-project form and on the first step of the
first-run wizard. A document that names no language of its own takes that
default. An agent names another language per document through `document_create`.

The project settings screen carries the same field, so you can change the
default later. The change applies only to documents written after it. A document
fixes its own language when it is written. Nothing changes that language
afterwards, because a change needs a reindex that no screen or tool does today.

The default is `english` for every project that existed before this field, which
keeps those projects searching as they always did.

Pick **Other or mixed (no stemming)** for a project whose text has no single
language. Search then matches whole words only.

## Archiving

`/archive` and `/unarchive` take a document out of the default listing and put
it back. Nothing is deleted.
