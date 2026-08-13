# Manual test plan — document review

What to click through by hand, and why each item is here.

**Read the coverage note against each section.** Items marked **UNCOVERED** have
no automated test at all — those are where manual testing is the only thing
standing between a defect and production. Items marked **regression-only** have
a passing e2e suite that proves the feature does not break anything *else*,
while asserting nothing about whether the feature works.

Log in at `https://loupe.dev.localhost` with `dev@loupe.test` / `password`.

---

## 1. Documents list

**Covered by e2e.** Spot-check only.

1. **Search finds a document by body text, not just title.** Type a word that
   appears only in a document's body. It should match. Stemming is English, so
   searching `partitions` should find a document containing `partition`.
2. **Typing does not lose keystrokes.** Type a query, pause about half a second
   mid-word, then keep typing **without clicking back into the field**. The full
   string must be in the box, in order. Two failure shapes to watch for: the
   second half missing entirely, and the second half arriving *in front* of the
   first (`onkafka` rather than `kafkaon`).
3. **A search is linkable.** After searching, reload the page. The results and
   the query should survive, and the URL should carry the term.
4. **Tag filter.** Filter by a tag; combine it with a search term. Clearing
   filters should return the full list.

## 2. Document review page

### Table of contents — **regression-only**

No e2e spec asserts the panel works; the suite only proves it breaks nothing.

1. Open a document with several `##` headings. A contents panel should list
   them, and each entry should jump to its heading.
2. **A heading that is only an image.** `## ![Diagram](d.png)` should show the
   image's alt text as the entry, not a blank link.
3. **A heading with no alt text** (`## ![](d.png)`) should be *omitted* from the
   contents rather than rendering an empty row — and the section should still
   have a working anchor.

### Front matter and HTML comments — **regression-only**

1. Submit a document beginning with a `---` YAML block. It should render as a
   table, not as prose or raw text. A `date:` value should read as a date, not
   as a Unix timestamp.
2. An HTML comment on its own line (`<!-- TODO: link the repo -->`) should
   render as a visible annotation rather than vanishing.
3. **A comment inside a `<div>` is still invisible.** This is known and
   deliberate: rendering comment text nested inside a raw HTML block would mean
   parsing and re-emitting document-supplied markup, which widens what the
   sanitizer has to defend. Confirm it degrades quietly rather than breaking the
   page.

### References and tags

1. Reference another document; the chip should appear and link correctly. The
   referenced document should show the inbound reference.
2. Tags render on the document and on the list.

### Comments and anchoring — **the highest-value area**

Anchoring is the core of the product and the part most easily broken by any
renderer change.

1. Select a passage, comment on it, and confirm the highlight lands on **exactly**
   the text you selected.
2. Reload. The highlight must still be in the right place.
3. **Revise the document without touching that passage.** The comment must
   re-anchor to the same text.
4. **Revise the document and rewrite the passage.** The comment should come back
   marked orphaned rather than silently pointing at the wrong text.
5. Comment on a passage containing an inline `<code>` span or a link, and on one
   spanning a paragraph boundary.

### Strike and suggest

1. Select text and press `s`. A strike should appear in one gesture, with **no
   composer opening**.
2. Hold `s` down. Exactly one strike, not one per key repeat.
3. Press `s` twice quickly. Exactly one strike.
4. Select text, click elsewhere, then press `s`. Nothing should happen.
5. Suggest a replacement; confirm the original and the replacement are both
   legible in the thread.

### Agent highlights — **UNCOVERED (visual)**

PHPUnit cannot see rendering, and no e2e spec asserts appearance.

1. Have an agent mark a passage via MCP. It should render distinctly from a
   human comment — a **wavy** underline in a distinct colour.
2. **Overlap an agent mark with a human comment on the same text.** Both should
   remain legible; the agent's wavy underline must not disappear under the
   comment's straight one.
3. **Overlap an agent mark with a strike.** The strike crosses the middle of the
   text and the agent mark underlines below it — both should be visible.
4. If you can, check with a colour-vision simulator. The tint alone is close to
   a pending comment's tint under tritanopia; the wavy underline is what carries
   the signal, so it must not be lost.

## 3. Version diff — **UNCOVERED (no Playwright spec)**

1. Revise a document, then open the diff between two versions. Word-level
   changes should be visible.
2. **No contents panel in diff mode.** Its absence is deliberate — the diff pane
   replaces the prose, so the heading targets do not exist.
3. **No comment highlights or composers in diff mode.** The diff shows Markdown
   source, so anchoring cannot apply.
4. Try a diff on a very large document. It should refuse cleanly with a
   "too large" state rather than hanging.

## 4. Decision controls — **UNCOVERED (no Playwright specs at all)**

Tracked as a known gap. This is the newest feature and the least exercised.

1. Submit a document containing a decision fence and confirm clickable options
   appear:

   ```markdown
   <!-- decision: deploy-target -->
   1. Ship to staging first
   2. Ship straight to production
   <!-- /decision -->
   ```

2. Both **numbered and bulleted** lists should convert.
3. Pick an option. It should persist across a reload.
4. **Revise the document, reordering the options.** Your answer must still be
   the option you actually chose, not the one now sitting at that position.
5. **Answer from a stale page.** Open the document, revise it in another tab,
   then answer in the first. It must **refuse** and tell you to reload — not
   silently record something.
6. **A malformed fence degrades locally.** An unclosed fence should render as an
   ordinary list, and a *later, correct* fence on the same document must still
   produce controls.
7. Changing a decision's id in the source discards the answer. This is by design
   and documented; confirm it is not silently mis-attributed instead.

## 5. Agent-facing (MCP)

Integration-tested, but never driven by a real agent end to end.

1. Connect an agent and run through: create a document, get its review, reply to
   a comment, mark one addressed, set tags, highlight a passage, read decisions.
2. Confirm an agent's reply is visibly attributed to the agent, not to you.
3. Confirm **marking addressed does not mark resolved** — the agent claiming it
   acted is not you agreeing it is finished.

## 6. Destructive paths — worth one careful pass

Every branch this wave touched the deletion chains, and a mistake there is a
500 rather than a silent orphan.

1. **Delete a project** that has documents, comments, reviews, tags, references,
   highlights and at least one answered decision. It must succeed.
2. **Delete an account** that owns such a project. It must succeed.

## 7. Landing page — **UNCOVERED (visual)**

Only reachable signed out, and only where `landing.enabled` is on — the flag
is seeded off, and while it is off `/` still bounces to `/login`, which is
what a self-hosted instance must keep seeing. Flip it in
`/admin/feature-flags` and open `/` in a private window.

1. **The app still in the hero must be there at first paint**, at full size,
   before any JavaScript runs — throttle the network and reload. It is built
   from the real shell and review classes, so a change to the sidebar or the
   comment card shows up here too.
2. **Narrow the window slowly.** The still scales down rather than clipping, and
   the page never scrolls sideways.
3. **The floating toolbar sits in the gap between the two paragraphs** and the
   comment card is level with the highlighted passage. Both offsets are fixed,
   so re-flowing the sample copy moves them off.
4. Copy the demo command and check the button says "Copied".

## 8. The re-render command — **UNCOVERED (console only)**

`app:review:rerender-versions` has integration tests and no e2e.

1. Run it against a database with anchored comments. It should **refuse** and
   report how many comments would stop resolving, writing nothing.
2. Re-run with `--accept-comment-orphaning`. It should proceed and still report
   the count.

---

## What the automated gates cannot see

Worth knowing when deciding how much of the above to actually do:

- **Rendering and colour.** No test asserts how anything looks. Highlight
  layering, contrast and colour-vision distinguishability are manual-only.
- **Browser API behaviour** beyond what the e2e specs happen to cover — the
  Selection API, keyboard auto-repeat, caret position after a Turbo swap.
- **`nullable: false` on many-to-many join columns** and similar Doctrine
  deprecations. These log on every mapping read and are invisible to `just ci`;
  only running the app surfaces them. If in doubt, truncate `var/log/dev.log`,
  click around, and read it.
- **Whether a feature works at all**, for anything marked regression-only above.
