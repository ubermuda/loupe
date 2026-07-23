# Handoff: Loupe — "Loop" review console

## Overview
Loupe is a document-review tool built for the AI-agent workflow. A coding agent (e.g. Claude, over MCP) submits long-form documents — implementation plans, specs, RFCs — for a human to review before it acts. A human reads the document, leaves anchored inline comments (select text → comment), replies to and resolves threads, and renders a verdict (approve / request changes). The agent then addresses comments and submits a new version.

This handoff covers the **"Loop" direction**: an agent-native review console. The core loop is **agent proposes → human reviews → agent revises → human approves**, surfaced through a persistent **loop ribbon** (Proposed → In review → Revise → Approved) shown on the review screens.

There are two review surfaces:
- **Documents** — long-form prose documents with anchored inline comments.
- **Site review** — DOM-anchored visual comments left on a live site through an embeddable widget. This is a **comment-list only** view — the customer's site is never rendered (no iframe/screenshot/thumbnail).

Everything is organized under **Sites**. A Site (e.g. "Acme") contains its Documents, its Site review, and its agent connection. Sites are the top-level unit — documents are never a separate global silo.

---

## About the design files
The file in this bundle — **`Loop.dc.html`** — is a **design reference created in HTML**. It is a working prototype demonstrating the intended look, copy, and interaction behavior. It is **not** production code to copy directly.

The prototype is authored in a small in-house template runtime (a `<x-dc>` custom element with a `class Component extends DCLogic` logic class and inline-styled markup). **Do not port that runtime.** Instead, **recreate these designs in the target codebase's existing environment** (React, Vue, Svelte, SwiftUI, etc.) using its established component library, styling system, and state patterns. If no environment exists yet, choose an appropriate stack (React + TypeScript is a reasonable default for this UI) and implement there.

To view the prototype: open `Loop.dc.html` in a browser. All state is in-memory (no backend); it resets on reload.

## Fidelity
**High-fidelity (hifi).** Colors, typography, spacing, radii, and interactions are final and intended to be recreated faithfully. Exact hex values and sizes are given below. Recreate pixel-close using the target codebase's primitives; substitute your own component library where it exists, but preserve the visual result.

---

## Design tokens

### Color
| Role | Hex | Usage |
|---|---|---|
| Accent | `#5b5bd6` | primary buttons, active nav, links, highlights |
| Accent hover | `#4a4ac0` | button/link hover, secondary accent text |
| Accent soft | `#ecebfb` | active nav bg, chips, code-token bg, avatars |
| Accent faint | `#f4f3ff` | nav hover bg |
| Accent faint 2 | `#faf9ff` | quote bg, active card bg |
| Ink | `#17172a` | primary headings, strong text |
| Ink alt | `#14142a` | (reserve — deep ink) |
| Text | `#363650` | body copy |
| Mute | `#6b6b84` | secondary text, muted buttons |
| Dim | `#9a9ab0` | meta, mono labels, placeholders |
| Faint | `#b4b4c6` | faintest text, disabled glyphs |
| Border | `#ececf4` | hairlines, dividers, list rows |
| Border strong | `#e4e4ee` | input/card borders, chips |
| Border faint | `#f4f4f8` | inner sub-dividers, subtle chip bg |
| Surface | `#ffffff` | canvas, panels |
| Sidebar | `#fbfbfe` | sidebar bg, subtle fills |

**Ladder / status colors**
| Status | Text | Deep text | Background |
|---|---|---|---|
| Pending | `#e09a3e` (amber) | `#c17a2f` | `#fdf3e6` |
| Addressed / Resolved | `#47a06b` (green) | `#3d8a5c` | `#f0f9f3` |

Additional greens used: chip hover `#e2f2e8`, border `#d4ecdd`, label bg `#dcf0e3`. Amber borders: `#f3e2c8`, `#f8e6cc`. Indigo banner (design retains tones internally): bg `#f4f3ff`, border `#e0dffa`.

The dark code block (Connect page) uses: panel header `#17172a`, code bg `#1c1c30`, code text `#c7c6f0`, comment punctuation `#6b6b84`, keys `#9a9ab0`, string `#e0c48a`, server-name `#a5d6b8`.

### Typography
- **UI font:** `Geist` (weights 400, 450, 500, 600, 700). Fallback: `-apple-system, system-ui, sans-serif`.
- **Mono font:** `Geist Mono` (400, 500) — used for meta, labels, counts, URLs, selectors, tokens, code, and the loop-ribbon numbers.
- Load via Google Fonts: `https://fonts.googleapis.com/css2?family=Geist:wght@400;450;500;600;700&family=Geist+Mono:wght@400;500&display=swap`.

Type scale actually used (font-size / weight / notes):
- Page H1 (Sites): 26px / 600 / letter-spacing -0.01em
- Page H1 (Documents, Connect): 24px / 600
- Document title (review): 28px / 600 / letter-spacing -0.02em
- Site-review H1: 22px / 600
- Section H2 (in prose): 16px / 600
- Body copy (lists, descriptions): 14px / 400 / line-height ~1.55
- Prose body (document): 15.5px / 400 / line-height 1.72
- Comment body: 13.5px / 400 / line-height 1.55
- Reply body: 13px / 400
- Meta / mono labels: 10.5–12.5px / mono, color dim
- Mono section eyebrows (e.g. "MCP ENDPOINT"): 10.5px / mono / letter-spacing 0.08em / uppercase / dim
- Chips: 11–12px / 500

### Spacing, radii, misc
- Sidebar width: **252px**. Doc-review comment column: **388px**. Review layout max-width: **1160px** (left-aligned).
- Main content is **left-aligned** with a max-width (`margin: 0`, not centered). Screen paddings: Sites/Documents/Connect `40px 44px`; review header `22px 32px 0`; site review `22px 32px 60px`.
- Border radius: buttons/inputs 7–9px; chips/pills 999px; avatars 999px; cards 8–12px; small tags 4–6px.
- Hairlines are `1px solid #ececf4`. No drop shadows on layout panels. Only two shadows exist: the floating selection toolbar `0 4px 14px rgba(23,23,42,.22)` and the floating composer `0 8px 28px rgba(23,23,42,.14)`. Active comment card uses a focus ring `0 0 0 3px #f0effc`.
- **Flat, not card-like:** main panels are stacked full-width bands and flush divider-lists separated by hairlines. No floating white cards, no colored card backgrounds. Small discrete items (document comment threads) may keep a subtle 1px border.

---

## Global layout & sidebar

Two-column app shell: fixed **sidebar** (252px, bg `#fbfbfe`, right border `#ececf4`) + scrollable **main** (white). Full viewport height, no page scroll — each region scrolls internally.

**Sidebar (top → bottom):**
1. **Brand** — 26px rounded-square mark (bg accent `#5b5bd6`, white loop-check glyph) + "Loupe" (14px/600 ink) with "LOOP" eyebrow (9.5px mono, letter-spacing 0.14em, uppercase, dim).
2. **Site switcher** — bordered button (`1px #e4e4ee`, radius 9px, white): 24px dark square avatar with site initial "A", site name "Acme" (13px/600), domain "acme.com" (10.5px mono dim), and an up/down chevron on the right. **Clicking it navigates to the Sites index** (this is the single sites entry point — there is intentionally no separate "All sites" link; the switcher is the standard workspace-switcher pattern). Hover: border `#c7c6f0`, bg `#faf9ff`.
3. **Scoped nav** (site-scoped): **Documents** (file icon), **Site review** (globe icon, with an open-count pill on the right), **Connect agent** (code-brackets icon). Each: full-width left-aligned button, 13px, radius 8px, gap 10px. **Active** item: bg `#ecebfb`, text `#4a4ac0`, weight 600. Hover (inactive): bg `#f4f3ff`.
   - Site-review count pill: mono 10.5px; when open>0 amber (`#fdf3e6`/`#c17a2f`), else neutral (`#f0f4f0`/`#9a9ab0`).
4. Spacer, then **account** row (top hairline): 26px round accent-soft avatar "M", "maya" (12.5px/500), "Reviewer" (11px dim).

Icons throughout are Lucide-style inline SVGs (1.8–2px stroke). Use your icon set's equivalents: grid, file-text, globe, code (`</>`), chevron-right/up-down, plus, message-square-plus, check, external-link, link/selector, copy, key.

---

## Screens / views

### 1. Sites index  → `screenshots/01-sites-index.png`
**Purpose:** Choose which site to work in; see rollups of what's outstanding.
**Layout:** Left-aligned, max-width 900px, padding `40px 44px`. Eyebrow "WORKSPACE" (mono, uppercase, dim) → H1 "Sites" (26px/600) → one-line description (14px mute). Then a hairline-topped **divider list** of sites.
**Rows (flush, `18px 6px`, bottom hairline `#ececf4`):**
- **Acme** (live) — button; hover bg `#fbfbfe`. 38px dark rounded-square avatar "A" → name "Acme" (15px/600) + "acme.com" (11px mono dim) → meta line (mono 12.5px): `4 documents · 1 review · 5 open` (the open count in `#c17a2f`) → chevron-right (`#c7c6f0`). Clicking opens the Documents screen.
- **Petstore Checkout** (placeholder) — non-interactive, `opacity .6`; grey avatar "P", "2 documents · 0 reviews · 0 open", a "DEMO" tag (mono 10px, bordered).
- **New site** (placeholder) — dashed-border 38px square with a plus, "New site" (dim). Non-functional.

### 2. Documents  → `screenshots/02-documents-list.png`
**Purpose:** The site's document list; default landing after picking a site.
**Layout:** max-width 900px, `40px 44px`. Breadcrumb (mono 12px): `Acme / Documents`. H1 "Documents" (24px/600) + description "Documents the agent submitted for review, newest first." Then a hairline-topped divider list.
**Rows (flush, `16px 6px`, bottom hairline; hover bg `#fbfbfe`):**
- Title (15px/500 ink) + version pill (mono 11px, bg `#f4f4f8`, e.g. "v3").
- Meta line (mono 12.5px dim): `Updated {time}` and, when open>0, `· {n} open thread(s)` in `#c17a2f`.
- **Status chip** (right, pill, 12px/500): *In review* = `#ecebfb`/`#4a4ac0`; *Approved* = `#f0f9f3`/`#3d8a5c`; *Changes requested* = `#fdf3e6`/`#c17a2f`.
- Chevron-right.
Seed rows: "Rate-limit the public API" v3 · 2h ago · 2 open · In review (**the live one**); "Add webhook retries with backoff" v2 · 3d · 3 open · Changes requested; "Deprecate v1 auth tokens" v1 · 5d · 1 open · In review; "Migrate sessions to Redis" v1 · last week · Approved.
Only the first opens the full review; the others open a placeholder ("This document isn't wired up in the prototype…") — in production, all rows open real reviews.

### 3. Document review (hero)  → `screenshots/03-document-review.png`
**Purpose:** Read the document, leave/resolve anchored comments, render a verdict that advances the loop.
**Layout:** Column. **Header band** (`22px 32px 0`): breadcrumb `Acme / Documents / {title}` then the **loop ribbon**. Below, a full-height **two-pane row** (max-width 1160px): left **document** pane (flex, scrolls) + right **comment margin** (388px, white, scrolls) — the two panes sit directly adjacent with **no divider** between them.

**Loop ribbon:** horizontal row of 4 steps — `Proposed → In review → Revise → Approved`. Each step = a 22px numbered circle + label, joined by 2px connector bars.
- *Current* step: circle bg accent `#5b5bd6`/white number, border accent; label ink 13px/600.
- *Completed* step: circle bg `#ecebfb`/`#4a4ac0`, border `#d9d8f5`; label `#4a4ac0`; connector bar into it `#d9d8f5`.
- *Upcoming* step: circle white/`#b4b4c6`, border `#e4e4ee`; label dim; connector `#ececf4`.
Circle numbers are mono. The ribbon reflects the document's status (In review by default; Revise after "Request changes"; Approved after approval).

**Document pane** (`id=doc-scroll`, padding `8px 40px 80px`, content max-width 660px):
- Title (28px/600) + version pill (mono 12px, `#ecebfb`/`#4a4ac0`). Sub-line (mono 12.5px dim): "Authored by Claude · reviewed by maya".
- Prose: H2 section headings (16px/600, margin `28px 0 10px`) and paragraphs (15.5px/1.72, `#363650`). Plain prose — **no line numbers, no diff/terminal styling.**
- **Anchored-comment highlights:** any text that a comment is anchored to is wrapped in an inline highlight span. Pending = bg `#fdf3e6` + 2px amber underline (`inset 0 -2px 0 #e09a3e`); Addressed = bg `#f0f9f3` + green underline; Resolved = transparent bg + faint underline `#e4e4ee`, text dimmed. When its thread is the **active** one: bg `#ecebfb` + accent underline. Highlights are clickable (focus the thread). Use `box-decoration-break: clone` so multi-line highlights render cleanly.
Seed document: "Rate-limit the public API" (v3) with sections Summary, Motivation, Approach, Rollout, Risks. Full prose text is in `Loop.dc.html` (the `docBlocks` array in the logic class) — copy it verbatim.

**Select-to-comment**  → `screenshots/04-select-to-comment.png`, `screenshots/05-comment-composer.png`:
- On text selection within the prose (`mouseup`), a **floating toolbar** appears just above the selection: a dark pill button (bg `#17172a`, white, radius 8px, shadow `0 4px 14px rgba(23,23,42,.22)`) reading "Comment" with a message-plus icon.
- Clicking it opens a **floating composer** anchored at the selection: 340px white card, `1px #e4e4ee`, radius 10px, shadow `0 8px 28px rgba(23,23,42,.14)`, padding 12px. Contains: the quoted selection (12.5px mute, left accent border `#c7c6f0`, bg `#faf9ff`); a textarea ("Leave a comment for the agent…", focus ring `0 0 0 3px #f0effc`); a footer with "⌘⏎ to submit" hint (mono, faint) and **Cancel** (ghost) + **Comment** (accent) buttons.
- Submitting creates a **pending** comment anchored to the exact quote; the highlight persists in the prose. The comment must store `{quote, prefix, suffix}` (surrounding context) so the anchor can be re-resolved across versions — see State/Data below.
- A "Comment" button in the threads header opens the same composer for an **untargeted / general** comment (no quote; shows a "General comment" label instead).

**Comment margin** (right pane, 388px, white):
- **Threads header** (padding `22px 20px 10px`): "Threads" (13px/600) + ladder count (mono 11px dim, e.g. "1/4 resolved"); a small bordered "＋ Comment" button (opens untargeted composer).
- **Progress bar** (4px track `#ececf4`, fill `#47a06b`, width = resolved/total).
- **Ladder** (scrolling list, grouped, 18px gap between groups). Each group has a mono eyebrow with a status dot:
  - **PENDING · n** (amber dot, `#c17a2f` label)
  - **ADDRESSED BY AGENT · n** (green dot, `#3d8a5c` label)
  - **RESOLVED · n** (grey dot, dim label)
- **Thread card** (radius 8px, `1px #ececf4`, padding `12px 13px`; active = border `#c7c6f0`, bg `#faf9ff`, ring `0 0 0 3px #f0effc`):
  - Header: 20px round avatar "M" (`#ecebfb`/`#4a4ac0`) + author "maya" (12.5px/600) + status chip (right; pill with a colored dot, same ladder colors).
  - Optional quote (12px mute, left border `#c7c6f0`).
  - Body (13.5px/1.55).
  - **Replies** (one flat level): each rendered in the same stream with a 19px avatar — human replies use a green avatar + initial; **agent ("Claude") replies use an accent-soft avatar with "C"**. Separated by a top hairline `#f4f4f8`.
  - If not locked: a compact reply textarea (⌘⏎ submits) + a **Reply** ghost button and a **Resolve** button (green, with a mono "r" shortcut hint). Resolved cards show a **Reopen** button instead.
- The active thread is scrolled into view within the panel (set `scrollTop`, do **not** use `scrollIntoView`).

**Verdict bar** (pinned bottom of margin, top hairline, padding `14px 20px`, white):
- Before approval: **Approve** (accent, full-width-ish, check icon) + **Request changes** (amber outline: bg `#fdf3e6`, border `#f3e2c8`, text `#c17a2f`).
- **Approve** → `screenshots/06-approved-state.png`: sets verdict approved, advances ribbon to **Approved**, **locks all threads** (reply/resolve/reopen hidden). Bar becomes a confirmation: green check circle + "Approved" / "Threads locked" + a "Replay loop" button (resets the demo).
- **Request changes** → `screenshots/07-request-changes-revise.png`: advances ribbon to **Revise**; after a short delay the agent **addresses all pending comments** (moves them to *Addressed*, appends a "Claude" reply to each) and the version bumps (v3 → v4). In production this is driven by the agent via MCP, not a timer.

> Note: the earlier "agent-state banner" narrative ("Claude is blocked / unblock Claude") was intentionally **removed**. Keep verdict copy neutral ("Approve", "Approved"). The loop ribbon is the status indicator.

### 4. Site review  → `screenshots/08-site-review.png`
**Purpose:** Triage DOM-anchored visual comments captured on the live site. **Comment-list only — never render the customer's site** (no iframe, screenshot, DOM-replay, or thumbnail).
**Layout:** max-width 820px, `22px 32px 60px`. Breadcrumb `Acme / Site review`. An independent loop ribbon (its stage derives from the site comments: pending → In review, addressed-but-open → Revise, all resolved → Approved). H1 "Site review" + "acme.com · 1 submitted review". Description: "Visual comments captured on the live site through the review widget. Each carries the element, URL, and selector — no snapshot needed."
**Comment list** — a **flat, hairline-separated divider list** (top hairline; each item `20px 2px`, bottom hairline). Each item:
- Left: a **status-colored mono index** ("01", "02", …) — amber for pending, green for addressed/resolved. This is the primary differentiator between comments.
- Title row: the **commented element's text** as a bold 15px/600 quote (e.g. `"Start free trial"`), or "General page comment" when there's no element; status chip on the right.
- Meta (mono 11px dim): "maya · via review widget".
- Body (14px/1.55).
- **Captured context** (mono 11.5px, one wrapping row): an external-link glyph + the URL (`#6b6b84`), and a selector glyph + the CSS selector (`#9a9ab0`, e.g. `main > section.hero > a.cta-primary`).
- **Agent replies** (if any): indented under a 2px accent-soft left border; "Claude" + an "addressed" mono tag (green) + body. **Site-review comments are agent-reply-only — there is no human reply input here** (unlike document threads).
- Actions: **Resolve** (green) / **Reopen** (outline), inline at the bottom.
Seed comments: (1) "Start free trial" · /pricing · `main > section.hero > a.cta-primary` · pending; (2) "Enterprise" · /pricing · `section.tiers > div:nth-child(3) > h3` · addressed (with a Claude reply); (3) General · `acme.com/` · resolved. Exact copy is in `Loop.dc.html` (`initialSiteComments()`).

### 5. Connect agent  → `screenshots/09-connect-agent.png`
**Purpose:** MCP setup so the coding agent can submit documents and read comments, scoped to this site.
**Layout:** max-width 760px, `40px 44px 60px`. Breadcrumb `Acme / Connect agent`. H1 "Connect agent" + description. Then labeled blocks (each preceded by a mono uppercase eyebrow):
- **MCP endpoint** — bordered field (bg `#fbfbfe`, radius 9px): code glyph + endpoint `https://loupe.app/mcp/acme` (mono 13px) + a **Copy** button (shows a green check + "Copied" for ~1.4s after click).
- **Access token** — same treatment: key glyph + masked token `bp_live_7Qf3…c4Hs` + a green "site-scoped" tag + Copy button.
- **.mcp.json** — a dark code block: header bar (`#17172a`) "claude · project config" + Copy; body (`#1c1c30`) with syntax-highlighted JSON showing `mcpServers.loupe.url` and a `Authorization: Bearer …` header. Colors listed in Design tokens.
- **Agent tools** — a hairline divider list; each row: a mono accent-soft name pill + a description. Tools: `submit_plan`, `get_plan_status`, `list_comments`, `address_comment` ("Post an 'addressed' response to a comment. Cannot resolve — only a human resolves."), `submit_revision`, `list_site_comments`. Exact copy in `Loop.dc.html` (`tools`).

---

## Interactions & behavior
- **Navigation:** sidebar switches between Documents / Site review / Connect (state-driven; no page reload). The site switcher and site-index rows navigate to Documents. Breadcrumbs are clickable.
- **Select-to-comment:** `mouseup` in the prose with a non-empty selection (≥3 chars) shows the floating toolbar positioned above the selection rect (computed relative to the scroll container). Clicking it opens the composer at the same anchor; submitting persists a pending comment + highlight. Esc or Cancel closes the composer.
- **Threads:** clicking a highlight or a card focuses that thread (highlight + card ring, panel scrolls to it). Replies are one flat level (no nested sub-replies) on document threads; agent "addressed" responses render in the same reply stream.
- **Resolve/reopen:** human-only. The agent can only *address* (pending → addressed); a human resolves (→ resolved) or reopens (→ pending). Approving **locks** all threads.
- **Verdict/loop:** Approve → ribbon Approved + locked + confirmation bar. Request changes → ribbon Revise + agent addresses pending comments + version bump. "Replay loop" resets to the initial state.
- **Copy buttons:** write to clipboard; swap label to "Copied" with a green check for ~1400ms.
- **Keyboard shortcuts** (document review): `⌘/Ctrl+Enter` submits the focused composer or reply; `j` / `↓` and `k` / `↑` move between threads (cycling); `r` resolves the active thread; `Esc` cancels the composer. Ignore shortcuts while typing in an input/textarea (except ⌘⏎).
- **Transitions:** highlight bg `.12s`; card border/ring `.12s`; progress-bar width `.3s`. A subtle pulse keyframe exists but is only wired to the (removed) banner dot — not required.

## State management
Suggested state (per the prototype's `Component`):
- `screen`: `sites | documents(plans) | review | sitereview | connect`
- `siteId`, `documentId` (only "rate-limit" is live in the prototype)
- `comments[]` — document comments: `{ id, author, body, status: 'pending'|'addressed'|'resolved', quote, blockId, replies: [{author, body, agent:boolean}] }`
- `siteComments[]` — `{ id, text (element text), url, selector, general:boolean, status, body, replies:[{author, body, agent}] }`
- `activeThreadId`, `composer` (`{quote, blockId}` or `{untargeted:true}`), `composerBody`, `selection` (`{text, blockId, rect}`), `replyDrafts{}`
- `verdict` (`null | 'approved' | 'changes-requested'`), `loopStage` (`proposed|review|revise|approved`), `version`
- Derived: pending/addressed/resolved groupings, progress %, ladder counts, open counts.

**Anchoring (production concern):** store each comment's anchor as `{ quote, prefix, suffix }` (the exact selected text plus surrounding context) rather than character offsets, so anchors survive re-rendering and document revisions. Re-resolve by searching the rendered text; if a quote can no longer be found, mark the comment **orphaned** and surface it (the original app had an "orphaned comments" banner). The prototype uses a simplified `blockId + quote.indexOf` scheme — replace with prefix/suffix matching in production.

**Data model (from the real backend, for reference):** `Site` → has many `Document`; `Document` has `DocumentVersion`s and a `status` (in-review / approved / changes-requested); `Comment` belongs to a version + author, has an embedded `Anchor {quote, prefix, suffix}`, a nullable `parent` (for one-level replies), and `resolved`/`orphaned` flags. Site review: `Site` → `SiteReview` → `SiteReviewComment {text, url, selector, status, body}` with status `pending|addressed|resolved`. Verdicts are submitted per document and advance its status.

## Responsive behavior
Designed for desktop (sidebar + wide main). The review two-pane row has `max-width: 1160px` and is left-aligned; the comment margin is a fixed 388px and the document pane flexes. No mobile breakpoints are specified — treat narrow widths by stacking (document above comments) if needed, but desktop is the target.

## Assets
- **Fonts:** Geist + Geist Mono (Google Fonts link above). No self-hosted font files bundled.
- **Icons:** Lucide-style inline SVGs drawn in the markup. Use your codebase's icon library (Lucide, if available) — names listed in the Sidebar section.
- **Images:** none. No logos, photos, or screenshots of customer sites (by design). Avatars are initials in colored circles.

## Files
- `Loop.dc.html` — the full interactive prototype (all five screens + interactions). Open in a browser to explore; read its logic class for exact seed copy (document prose, comments, site comments, tools) and the precise style values.
- `screenshots/` — reference captures:
  - `01-sites-index.png`
  - `02-documents-list.png`
  - `03-document-review.png`
  - `04-select-to-comment.png`
  - `05-comment-composer.png`
  - `06-approved-state.png`
  - `07-request-changes-revise.png`
  - `08-site-review.png`
  - `09-connect-agent.png`
