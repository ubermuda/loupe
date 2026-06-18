# Agent Document Review MCP — Design

**Date:** 2026-06-18
**Status:** Approved for planning

## 1. Purpose

A multi-tenant Symfony service that lets a software agent submit a document it
produced (PRD, implementation plan, spec, etc.) for human review, and receive the
human's feedback back through an MCP (Model Context Protocol) interface.

The agent creates a Markdown document via MCP. A human opens it in a web UI, adds
inline comments anchored to specific text, sets an overall verdict, and submits.
The agent then pulls the review back, revises the document, and resubmits. Comments
carry across versions where the anchored text still exists.

## 2. Key decisions

These were settled during brainstorming. Some chosen options were deliberately
harder than the alternatives; where a simpler path was rejected it is noted.

| Axis | Decision | Notes |
|---|---|---|
| Deployment | Hosted multi-tenant SaaS | Real users; data scoped per user. |
| Document format | Markdown | Rendered to sanitized HTML for review. |
| Comment granularity | Inline, anchored to text ranges | W3C TextQuoteSelector. |
| Revisions | Multi-round, versioned | Each revision is a new version. |
| Re-anchoring | Fuzzy carry-forward across versions | Misses flagged as orphaned. |
| Review payload | Verdict + threaded anchored comments | Agent gets decision and granular notes. |
| Feedback delivery | **Human-activated pull** | Human submits, then tells the agent; agent calls `get_review`. No push / long-poll in this build. |
| Auth | Per-user Personal Access Token (PAT) | One reviewer (the owner) per document. |
| Stack | Symfony 8 / PHP 8.5 skeleton | `symfony/mcp-bundle` + `mcp/sdk`. |

### 2.1 Why feedback delivery is pull, not push

An LLM agent has no event loop. After it creates a document and ends its turn,
nothing is listening, and a standard MCP client does not re-invoke the model when a
server notification arrives. A blocking call cannot hold for the hours a human
review may take — client/HTTP timeouts kill it. So feedback cannot depend on a
server-initiated push.

The durable primitive is **pull**: the review is persisted, and `get_review`
returns it by document ID from any invocation, any time. For this build the pull is
**human-activated** — the reviewer finishes in the web UI, then tells the agent the
review is ready, and the agent calls `get_review`. This removes all async
infrastructure (no webhooks, no long-poll, no notification fan-out) while still
satisfying "the agent receives feedback through the MCP."

## 3. Architecture

Single Symfony application exposing two surfaces over the same domain and database:

- **MCP surface** — HTTP transport via `symfony/mcp-bundle`. Tools are defined with
  `#[McpTool]` attributes. The PAT in the `Authorization` header resolves to a
  `User` through `security.yaml`; every tool scopes its queries to that user.
- **Web surface** — Twig + Stimulus + Tailwind reviewer UI for the human, plus a
  dashboard listing the user's documents and a screen to manage PATs.

Markdown is rendered with `league/commonmark` and the output is sanitized before
display.

## 4. Domain model

- **User** — owns documents and PATs. Authenticated for both the web UI and (via
  PAT) the MCP.
- **ApiToken** — a PAT belonging to a User. Hashed at rest; shown once on creation.
- **Document** — `id` (UUID), `owner`, `title`, `status`
  (`in_review` → `approved` / `changes_requested`), and a pointer to the
  current `DocumentVersion`.
- **DocumentVersion** — `document`, `versionNumber`, `markdownSource`,
  `renderedHtml`, `createdAt`. Versions are immutable once created.
- **Anchor** — a W3C TextQuoteSelector against a version's **rendered text**
  (what the reviewer selects, not the raw Markdown): `quote`, `prefix`, `suffix`,
  and a character-offset hint.
- **Comment** — belongs to a `DocumentVersion`, has an `Anchor`, `author`, `body`,
  `resolved` flag, optional `parent` (for reviewer↔agent threads), and an
  `orphaned` flag (set when re-anchoring to a new version fails).
- **Review** — per version: `verdict` (`approved` | `changes_requested`),
  `reviewer`, `submittedAt`.

## 5. MCP tool surface

All tools are scoped to the authenticated user.

- `create_document(title, markdown)` → `{ documentId, reviewUrl }`
  Creates a Document at version 1, status `in_review`.
- `get_document(documentId)` → metadata + current Markdown + status.
- `revise_document(documentId, markdown)` → creates a new version; returns a
  re-anchor summary `{ carried, orphaned }`. Sets status back to `in_review`.
- `get_review(documentId)` →
  `{ status, verdict, version, comments: [{ quote, body, thread, resolved, orphaned }] }`.
- `list_documents()` → the user's documents with their statuses.

## 6. Review lifecycle

1. Agent calls `create_document` → status `in_review`; the document appears on the
   owner's dashboard.
2. Reviewer opens the document, selects text to anchor comments, replies within
   threads, sets a verdict, and submits → a `Review` is recorded for that version.
3. Human tells the agent the review is ready.
4. Agent calls `get_review`, reads the verdict and anchored comments.
5. Agent calls `revise_document` with new Markdown → a new version is created.
   Open (unresolved) comments are fuzzy-re-anchored onto the new rendered text;
   confident matches carry forward, misses are flagged `orphaned`.
6. Reviewer re-reviews the new version (resolving carried comments, re-placing or
   dismissing orphaned ones). Repeat until `approved`.

## 7. Anchoring and re-anchoring

Comments anchor to the rendered document's text content using the quoted text plus
a prefix and suffix of surrounding context (W3C TextQuoteSelector), with a
character offset stored as a hint only.

On revision, each open comment is re-located in the new version by best text match:

- **Confident match** → carried to the new version with an updated offset.
- **No match** → marked `orphaned` and surfaced to the reviewer to re-place on the
  new text or dismiss.

Anchoring against rendered text (not raw Markdown) keeps anchors aligned with what
the reviewer actually selects and survives Markdown formatting changes that do not
alter the visible words.

## 8. Reviewer UI

Layout **A — right comment sidebar** (Google-Docs style):

- Header: document title, version, and a verdict bar (Approve / Request changes).
- Main pane: the rendered document with anchored highlights and numbered pins.
- Right rail: comment threads. Agent replies are shown **inline within each thread**
  (visible to the reviewer).
- A banner indicates comments that could not be re-anchored to the current version.

Built with Twig, Stimulus, and Tailwind. Text selection creates an Anchor and opens
a comment composer; submitting adds the thread to the sidebar.

## 9. Authentication

The agent sends a per-user PAT in the `Authorization` header. The mcp-bundle
resolves it to the authenticated `User`, and every tool scopes to that user's
documents. PATs are created and revoked from the web UI, hashed at rest, and shown
in full only once at creation. One reviewer — the document owner — per document.

## 10. Out of scope (north star, deferred)

- Organizations, invites, and role-based access control.
- OAuth 2.1 agent authentication.
- `await_review` long-poll and MCP/email push notifications.
- Mercure-based live updates in the reviewer UI.
- Multiple reviewers merging into one review.

These are explicitly excluded from this build and revisited in a later spec.
