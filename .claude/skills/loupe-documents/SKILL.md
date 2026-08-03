---
name: loupe-documents
description: Use when writing or revising a document for the Loupe app — anything sent through the loupe MCP's document_create or document_revise (implementation plans, specs, RFCs, audit reports, retrospectives, any long-form review document).
---

# Writing documents for Loupe review

Documents submitted via `document_create` / `document_revise` are read in the
Loupe review UI, where reviewers select passages and comment on them. Format
for that reading context, not for a terminal or a README.

## Rules

1. **No H1 title in the body.** The document's title lives in Loupe's own
   `title` field; an `# Heading` repeating it in the markdown renders as a
   duplicate. Start the body directly with content (or the first `##`
   section).

2. **Prefer numbered lists over bullet lists.** Reviewers refer to entries in
   comments ("point 3 is wrong") — numbers make that possible, bullets don't.
   Use bullets only where order and reference genuinely don't matter (e.g. a
   short set of interchangeable examples).

3. **Cross-references need stable IDs, because list numbering restarts at
   every heading.** Markdown begins a new ordered list after each `##`, so a
   document whose source numbers entries 1–33 straight through renders as
   1–2 under the first heading, then 1–5 under the next, and so on. Any
   "see finding 6" written against the source numbering points at nothing the
   reviewer can see. This is not hypothetical: a self-hosting audit shipped
   with every cross-reference broken, and the reviewer's first comment was
   that finding 6 did not exist.

   When entries refer to one another, give each a short ID that does not
   depend on rendering — `C1`, `H4`, `M5` for severity-banded findings, or
   any scheme where the prefix names the section — and lead the entry with
   it:

   ```markdown
   4. **H4 — There is no administrative console command.** …
   ```

   Then refer to **H4**, never "finding 4". Keep the list numbered as well
   (rule 2 still applies); the number gives the reviewer something to point
   at, the ID gives the text something stable to cite. A document whose
   entries never reference each other doesn't need IDs.

4. **In long lists, open every entry with a short introductory sentence.**
   When a list has many entries, each one starts with a small plain sentence
   presenting what the entry is about, before the detail. A reviewer scanning
   the list must be able to grasp each entry from its first sentence alone —
   no diving into a wall of `file:line` references or jargon to find out what
   an item concerns.

5. **Make decision points look like decisions.** When the document offers
   more than one way to do something, the formatting must show the reader a
   choice is being asked of them — an explicit "**Decision needed:**" lead-in
   (or equivalent), the alternatives as a numbered sub-list so they can be
   referenced, and your recommendation named if you have one. Never fold
   alternatives into a flat sentence ("do X or do Y.") — that reads as an
   instruction, and the reader sails past the choice instead of making it.

   Where the alternatives are a clean either/or, wrap them in a **decision
   fence** (rule 10) as well, so the reviewer clicks the option instead of
   typing "option 2" into a comment for you to parse back out.

6. **After addressing review comments, revise the document.** When you act on
   a reviewer's comments — answering questions, recording decisions, changing
   course — send a new version with `document_revise` so the document reflects
   the resolved state. Tracking the outcome somewhere else (an issue tracker,
   the chat, a commit message) does not close the loop: the reviewer returns
   to the document, and a document still showing the proposal state after its
   proposals were decided is stale. Mark what changed with a **Decided:** line
   on each affected entry, saying what was chosen and where the work now
   lives. Do **not** add a revision note, changelog, or "updated on <date>"
   header — Loupe tracks versions itself and shows them in the UI, so
   hand-written version bookkeeping is duplicate noise. Skip the revision only
   when the comments changed nothing about the document's content (pure
   acknowledgements, or questions answered entirely in chat with no bearing
   on what the document says).

   Revising re-anchors open comments onto the new version; those whose quoted
   text no longer appears come back **orphaned**. Rewriting a passage you were
   asked about therefore orphans that comment — this is normal and not a
   reason to avoid revising, but keep the reviewer's quoted phrases intact
   where you can, and expect the orphan count in the tool's response.

7. **Reply to comments before you revise, or re-read the review afterwards.**
   `document_reply_to_comment` and `document_mark_comment_addressed` take the
   comment ids from `document_get_review`, and **`document_revise` invalidates
   every one of them**: re-anchoring does not move a comment forward, it
   *copies* it onto the new version and leaves the original behind. The old
   ids still exist and still look pending, so the natural order — read the
   review, revise, then say what you did — writes into rows nobody reads.

   Both tools reject a stale id rather than accepting it silently (a
   `superseded` skip, or an error naming both version numbers), so the failure
   is loud. But an agent that learns this from the error has already composed
   a reply it now has to redo. Two orders that work:

   1. Reply to each comment and mark it addressed **first**, then call
      `document_revise` once at the end.
   2. Revise first, then call `document_get_review` again and use the fresh
      ids.

   The first is usually better: your replies describe changes the reviewer is
   about to see, and each thread's status is already correct when the new
   version lands.

8. **Say what changed in the version description.** `document_revise` requires
   a `description`, and `document_create` takes an optional one. It is shown
   under that version in the review UI's version list, and it is what a
   reviewer coming back to "v4" reads to decide whether they need to re-read
   the document at all. Write it for that reader: name what you rewrote,
   added or dropped, and why — in one or two plain sentences.

   Useful, because a reviewer can act on it:

   ```
   Replaced the rollout section with a phased plan, and answered the two
   questions on caching. Section 3 is unchanged.
   ```

   Useless, because it restates the mechanism the version already proves:

   ```
   Updated the document. · Revision 4. · Addressed review comments.
   ```

   Three habits that make the difference. Name the sections or entries you
   touched, so a reviewer can skip straight to them. Say explicitly when
   something they commented on did **not** change and why, since silence
   reads as an oversight. And do not restate the title, the date or the
   version number — Loupe records all three itself.

   On `document_create` the description answers a different question: what
   this document is and what it exists to settle. One sentence is usually
   enough ("Design spec for the site-review widget, deciding the auth model").

9. **Renaming is `document_rename`, not a revision.** Correcting a title —
   fixing a typo, making a batch of related documents consistent — goes
   through `document_rename`, which changes the title and nothing else. Do not
   resubmit unchanged markdown through `document_revise` to carry a new title:
   that mints a version whose description can only say "renamed", and every
   version after it is one more entry a reviewer has to skip. `document_revise`
   takes a `title` only for the case where the content and the title change
   together.

10. **A decision fence turns a choice into something the reviewer clicks.**
    Wrap the alternatives in a pair of HTML comments carrying an identifier,
    and Loupe renders them as a group of radio buttons whose answer comes back
    in `document_get_review` under `decisions`:

    ```markdown
    <!-- decision: reset-link-host -->

    - [ ] Drop `x-forwarded-host` from `trusted_headers`
    - [ ] Generate emailed links from a pinned `default_uri`

    <!-- /decision -->
    ```

    The comments are invisible in every other Markdown renderer, so a document
    read outside Loupe still shows the list — that is why the fence is written
    this way rather than with a visible marker. Keep the entries a flat list of
    one-line options: a nested list is refused and renders as an ordinary list,
    and so is a second fence reusing an id already used above it.

    **An id is permanent once published.** The answer is stored against the id,
    not against the words, precisely so you can reword a decision block in the
    revision that responds to feedback about it. The other side of that bargain
    is that **changing an id silently discards the answer** — there is no error
    and no warning, the decision simply reads as unanswered again. Treat one
    like a database column name: rewrite the options freely, never the id.

    Ids are lowercase letters, digits and hyphens, up to 64 characters. Prose
    still does the work — keep the "**Decision needed**" lead-in and your
    recommendation (rule 5) above the fence, because the fence carries no
    question of its own, only the options.

## Example

Entry shape — lead sentence first, detail after:

```markdown
1. **Token flush on every request.** Each widget API call writes
   `last_used_at` synchronously. `ApiTokenAuthenticator.php:45` runs
   `markUsed()` + `flush()` inside the auth path… (detail follows)
```

Not this — starts mid-jargon, fragment-glued, no presentation:

```markdown
1. `ApiTokenAuthenticator.php:45` — markUsed() + flush() on every Bearer
   request · hot-row contention · fix: …
```

Decision shape — the choice is visible and the options referenceable:

```markdown
**Decision needed** — how to stop reset-link host poisoning:

1. Drop `x-forwarded-host` from `trusted_headers` (recommended: smallest
   change, removes the attacker input).
2. Generate emailed links from a pinned `default_uri` instead of request
   context.
```

Not: "Drop `x-forwarded-host` or generate these links from a pinned
`default_uri`." — the reader cannot tell a decision is expected.

## Common mistakes

- Pasting a report drafted for the terminal into `document_create` unchanged —
  terminal formatting habits (dense bullets, `·`-separated fragments, H1
  headers) are exactly what these rules exist to undo.
- Numbering only the top level: nested lists that reviewers will discuss also
  get numbers.
- Cross-referencing by list number across headings — the rendered numbering is
  per-section, so the reference lands on the wrong entry or none at all. Use
  stable IDs (rule 3).
- Holding comment ids across a `document_revise` call. They do not survive it
  (rule 7); re-read the review or reply before revising.
