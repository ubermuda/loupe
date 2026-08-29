---
name: loupe-documents
description: Use when writing or revising a document for the Loupe app, anything sent through the loupe MCP's document_create or document_revise (implementation plans, specs, RFCs, audit reports, retrospectives, any long-form review document).
---

# Writing documents for Loupe review

Reviewers read what you send to `document_create` / `document_revise` in the
Loupe review UI, where they select passages and comment on them. Format for that
reading context, not for a terminal or a README.

## Rules

1. **No H1 title in the body.** Loupe's own `title` field holds the title, so an
   `# Heading` repeating it renders twice. Start with content, or with the first
   `##` section.

2. **Prefer numbered lists over bullet lists.** Reviewers cite entries in
   comments ("point 3 is wrong"), which numbers permit and bullets do not. Use
   bullets only where order and reference genuinely do not matter, such as a
   short set of interchangeable examples.

3. **Cross-references need stable IDs, because list numbering restarts at every
   heading.** Markdown starts a new ordered list after each `##`, so source
   entries numbered 1–33 render as 1–2 under the first heading, then 1–5 under
   the next, and a "see finding 6" points at nothing the reviewer can see. A
   self-hosting audit shipped with every cross-reference broken, and the
   reviewer's first comment was that finding 6 did not exist.

   When entries refer to one another, give each a short ID that does not depend
   on rendering: `C1`, `H4`, `M5` for severity-banded findings, or any scheme
   where the prefix names the section. Lead the entry with it:

   ```markdown
   4. **H4 — There is no administrative console command.** …
   ```

   Refer to **H4**, never "finding 4". Keep the list numbered as well (rule 2
   still applies): the number gives the reviewer something to point at, the ID
   gives the text something stable to cite. Entries that never reference each
   other need no IDs.

4. **In long lists, open every entry with a short introductory sentence** saying
   what the entry is about, before the detail. A reviewer scanning the list must
   grasp each entry from that sentence alone, without diving into a wall of
   `file:line` references or jargon.

5. **Make decision points look like decisions.** Where the document offers more
   than one way to do something, the formatting must show the reader that you
   ask for a choice. Write an explicit "**Decision needed:**" lead-in or
   equivalent, put the alternatives in a numbered sub-list so they can be
   referenced, and name your recommendation if you have one. Never fold
   alternatives into a flat sentence ("do X or do Y."): that reads as an
   instruction, and the reader sails past the choice.

   Where the alternatives are a clean either/or, also wrap them in a **decision
   fence** (rule 12), so the reviewer clicks an option instead of typing "option
   2" into a comment for you to parse back out.

6. **After you address review comments, revise the document.** Send a new
   version with `document_revise` so the document shows the resolved state; an
   outcome recorded in an issue tracker, the chat or a commit message leaves the
   returning reviewer on a stale proposal. Mark each affected entry with a
   **Decided:** line naming what was chosen and where the work now lives. Add no
   revision note, changelog or "updated on <date>" header, because Loupe tracks
   versions itself and shows them in the UI. Skip the revision only when the
   comments changed nothing in the content: pure acknowledgements, or questions
   answered entirely in chat with no bearing on what the document says.

   Revising re-anchors open comments onto the new version, and those whose
   quoted text no longer appears come back **orphaned**. Rewriting a passage you
   were asked about therefore orphans that comment. That is normal and no reason
   to avoid revising, but keep the reviewer's quoted phrases intact where you
   can, and expect the orphan count in the tool's response.

7. **Reply to comments before you revise, or re-read the review afterwards.**
   `document_reply_to_comment` and `document_mark_comment_addressed` take the
   comment ids from `document_get_review`, and **`document_revise` invalidates
   every one of them**: re-anchoring copies a comment onto the new version and
   leaves the original behind. The old ids still exist and still look pending,
   so the natural order of read-review, revise, then reply writes into rows
   nobody reads. Both tools reject a stale id loudly, with a `superseded` skip
   or an error naming both version numbers, but by then you have composed a
   reply you must redo. Two orders work:

   1. Reply to each comment and mark it addressed **first**, then call
      `document_revise` once at the end.
   2. Revise first, then call `document_get_review` again and use the fresh
      ids.

   The first is usually better: your replies describe changes the reviewer is
   about to see, and each thread's status is already correct when the new
   version lands.

8. **Replies are two or three sentences.** A reply is a turn in a conversation,
   not a section of the document: say what you did or what you concluded, then
   stop. Use no headings, no bold lead-ins, no bullet or numbered lists and no
   tables. Do not restate the comment back to the reviewer, and do not rehearse
   the reasoning that produced the answer. This is a complete reply: "Renamed to
   `AccountStatusChecker` — `ActiveUserChecker` collides with `countActive()`,
   which already means something else here."

   Reply length also tells you where an answer belongs. An answer needing more
   than a short paragraph belongs in the document, which the reviewer re-reads
   later; a comment thread is the worst place to keep a decision. Put it in the
   next version and let the reply be the pointer, as in "Reworked that section
   in v4." Disagreement earns more room, because the reviewer needs the
   reasoning to judge it, and even then one paragraph is enough.

9. **Say what changed in the version description.** `document_revise` requires a
   `description`, and `document_create` takes an optional one. Loupe shows it
   under that version in the review UI's version list, and a reviewer coming
   back to "v4" reads it to decide whether to re-read the document at all. Name
   what you rewrote, added or dropped, and why, in one or two plain sentences.

   Useful, because a reviewer can act on it:

   ```
   Replaced the rollout section with a phased plan, and answered the two
   questions on caching. Section 3 is unchanged.
   ```

   Useless, because it restates the mechanism the version already proves:

   ```
   Updated the document. · Revision 4. · Addressed review comments.
   ```

   Name the sections or entries you touched, so a reviewer can skip straight to
   them. Say explicitly when something they commented on did **not** change, and
   why, because silence reads as an oversight. Do not restate the title, the
   date or the version number; Loupe records all three itself.

   On `document_create` the description answers a different question: what this
   document is and what it exists to settle. One sentence is usually enough
   ("Design spec for the site-review widget, deciding the auth model").

10. **Renaming is `document_rename`, not a revision.** To fix a typo in a title,
    or to make a batch of related documents consistent, call `document_rename`;
    it changes the title and nothing else. Resubmitting unchanged markdown
    through `document_revise` mints a version whose description can only say
    "renamed", and every version after it is one more entry a reviewer has to
    skip. `document_revise` takes a `title` only when the content and the title
    change together.

11. **Point at the passages that matter with `document_highlight`, where the
    instance offers it.** Highlighting is off by default, and an instance with it
    off does not advertise the tool at all. **If `document_highlight` is not
    among your tools, skip this rule.** Nothing else in it applies and there is
    no fallback: say what you would have marked in prose, or say nothing.

    Where it is on, use it: on a long document, where the reviewer starts
    reading is most of what you control. Quotes match the **rendered prose**,
    not your Markdown source, every call replaces the whole set, and
    `document_revise` drops them. Read `references/highlights.md` before you
    call it.

12. **A decision fence turns a choice into something the reviewer clicks.** A
    pair of `<!-- decision: some-id -->` and `<!-- /decision -->` comments around
    the alternatives renders them as radio buttons, whose answer comes back in
    `document_get_review` under `decisions`. **An id is permanent once
    published**, and **a changed id silently discards the answer**: no error, no
    warning, and the decision reads as unanswered again. A malformed fence
    degrades to a plain list, also with no error. Read
    `references/decision-fences.md` before you write one.

## Example

Entry shape, lead sentence first and detail after:

```markdown
1. **Token flush on every request.** Each widget API call writes
   `last_used_at` synchronously. `ApiTokenAuthenticator.php:45` runs
   `markUsed()` + `flush()` inside the auth path… (detail follows)
```

Not this, which starts mid-jargon, glues fragments together and presents
nothing:

```markdown
1. `ApiTokenAuthenticator.php:45` — markUsed() + flush() on every Bearer
   request · hot-row contention · fix: …
```

Decision shape, where the choice is visible and the options referenceable:

```markdown
**Decision needed** — how to stop reset-link host poisoning:

1. Drop `x-forwarded-host` from `trusted_headers` (recommended: smallest
   change, removes the attacker input).
2. Generate emailed links from a pinned `default_uri` instead of request
   context.
```

Not: "Drop `x-forwarded-host` or generate these links from a pinned
`default_uri`." The reader cannot tell that a decision is expected.

## Common mistakes

- Pasting a report drafted for the terminal into `document_create` unchanged.
  Terminal formatting habits (dense bullets, `·`-separated fragments, H1
  headers) are exactly what these rules exist to undo.
- Numbering only the top level. Nested lists that reviewers will discuss also
  get numbers.
- Cross-referencing by list number across headings. The rendered numbering is
  per-section, so the reference lands on the wrong entry or none at all. Use
  stable IDs (rule 3).
- Holding comment ids across a `document_revise` call. They do not survive it
  (rule 7); re-read the review, or reply before revising.
- Quoting your own Markdown to `document_highlight`. It matches the rendered
  prose, so inline markup in the quote finds nothing (rule 11).
- Answering a one-line comment with a structured essay: bold lead-ins,
  sub-points, the whole argument that produced the answer. Give the answer
  (rule 8); if it does not fit in a short paragraph, it belongs in the document.
