---
title: "The MCP endpoint"
description: "How an AI agent creates and revises documents, and how it signs in."
---

`POST /mcp` is a Model Context Protocol endpoint. An agent signs in with OAuth
and calls tools that create documents, revise them, and read back what humans
said. A long-form plan then gets considered review instead of scrolling past in
a terminal.

## Connecting through the CLI

This is the way to connect. One sign-in serves every repository, and each
repository names its own project, so a new project needs no new login.

`loupe mcp` connects an agent to this endpoint with no token in any file. The
CLI already holds a login, so the command signs each request with it, and the
project comes from the repository rather than from the credential.

Install the CLI, sign in once, and name the project in each repository:

```bash
loupe login                  # a browser sign-in, once per machine
cd ~/code/my-project
loupe init                   # writes .loupe.yaml, choosing from your projects
```

`loupe init` then checks how Claude Code starts the `loupe` server. When nothing
does, it offers to declare it for every project:

```bash
claude mcp add --scope user loupe -- loupe mcp
```

One declaration serves every repository. Claude Code starts the command with the
repository as its working directory, and `loupe mcp` reads `.loupe.yaml` from
there, so the project comes from the repository rather than from the
declaration. A worktree gets it for free. A repository with no `.loupe.yaml`
still starts the server, with no project: your login then acts on your single
project, or refuses and asks which one when you own several.

`loupe mcp` reads `.loupe.yaml` again when the file changes, so a new project
reaches the agent at its next request. A file that fails to parse keeps the last
good project. A removed file sends no project, as if the repository had no
file. Each change writes one line to stderr, which an agent shows as this
server's log. With `--project`, the command never reads the file.

When something else already answers to the name, `loupe init` says what it
starts and offers to remove it. Removing always asks, whatever flags you passed,
so a script never drops a declaration you made by hand. `--mcp` and `--no-mcp`
answer the create offer without being asked.

`loupe init` never edits Claude Code's configuration file. Claude Code owns it,
rewrites it while it runs, and keeps its own backups beside it, so every change
runs `claude mcp` and lets Claude Code edit its own file.

### Committing the server to the repository

`loupe init --mcp-json` writes `.mcp.json` instead, so everyone who clones the
repository gets the server:

```json
{
  "mcpServers": {
    "loupe": { "command": "loupe", "args": ["mcp"] }
  }
}
```

Every other server in that file is kept, and so is every other top-level key.
The file holds no credential, so committing it is safe. `.loupe.yaml` holds the
project id, which is not a secret either.

Two things come with it. An agent asks you to approve the server the first time,
because the file names programs to run and travels with the repository. And
`.mcp.json` is the lowest of Claude Code's three scopes, so a local or user
declaration of the same name hides it. `loupe init` reports that rather than
leaving you to find it.

The command adds one thing a direct HTTP connection cannot. Loupe keeps each MCP
session for an hour in one web container's cache directory, so a session ends on
an idle agent, on a deploy, and on a request that reaches another container.
`loupe mcp` then opens a new session and carries on, where a direct connection
loses its Loupe tools for the rest of the agent's run.

One sign-in is enough. `loupe login` asks for `agent mcp projects`, so the same
login serves `loupe bridge` and `loupe mcp`, and it covers every project you own
including ones you create later. The approval page says so before you allow it.

## Connecting by URL

A client that speaks OAuth can also connect with the endpoint URL alone, with
no CLI. Prefer this where the client is not on your own machine, such as Claude
reaching your instance from Anthropic's servers. The connection then binds to
one project, so a second project needs a second connection.

An MCP client that supports OAuth connects with the endpoint URL alone. You do
not copy a token. Claude Code and Claude connect this way.

In Claude Code, add the server, then sign in:

```bash
claude mcp add --transport http loupe https://loupe.example.com/mcp
claude mcp login loupe
```

You can also run `/mcp` inside Claude Code and select the server. Claude Code
opens the Loupe consent page in your browser. Sign in, choose the project, and
select **Allow**.

In Claude, add a custom connector with the same URL, then connect it. Claude
reaches your instance from Anthropic's servers, so the instance must be
reachable from the internet over https. An instance on a private network or
behind a VPN works with Claude Code only.

The connection is bound to one project. The *Connected apps* section of your
account settings lists it and revokes it. See [Connected apps](connected-apps.md).

A client finds the sign-in in three steps:

1. `POST /mcp` with no token answers `401`. Its `WWW-Authenticate` header
   points at `/.well-known/oauth-protected-resource/mcp`.
2. That document names the endpoint as the resource and Loupe as its
   authorization server.
3. The client identifies itself with a Client ID Metadata Document. Its client
   id is an https URL, and Loupe fetches that document to learn the app's name
   and redirect addresses.

Loupe has no Dynamic Client Registration. A client that supports only that
cannot connect by URL. Connect it through the Loupe CLI instead, above.

For a self-hosted instance:

- `DEFAULT_URI` must be the https URL that clients use. The resource
  `https://<host>/mcp` and the issuer are built from it.
- `MCP_ALLOWED_HOSTS` must contain that host.
- The web container fetches a client's metadata document when a user connects
  that client. The fetch goes to public addresses only. An instance with no
  internet access cannot connect a new client, and every other feature works.

## There is no static token

Loupe issues no static API token. No page mints one, and the server accepts
none. Every connection is an OAuth sign-in, and the client refreshes it by
itself.

Each sign-in needs a person at a browser once. An MCP client opens the consent
page. The Loupe CLI prints a device code for a page you open. A machine where
nobody can open a browser cannot get a credential, so CI and scripts cannot
reach `/mcp` or `/api`.

A connection carries one scope. The `mcp` scope binds to the one project you
pick on the consent page, so every tool call knows which project it acts on.
The `agent` scope reaches the CLI endpoints and binds to no project. The
`site-review` scope reaches the comments of one project, which is what the
site-review widget uses. See [Connected apps](connected-apps.md) for the whole
table and for how to revoke a connection.

`403 insufficient_scope` means the sign-in is valid and carries the wrong scope
for that endpoint. Connect the client again with the scope the endpoint needs.

A project's Connect page shows the command for each way on this page. The
first-run wizard shows the same commands at `/welcome/connect`.

## The Claude Code plugin

The plugin ships skills, and no MCP server. `loupe login` and `loupe init` set
the server up, so nothing here holds a credential and nothing asks you for one.

```bash
claude plugin marketplace add ubermuda/loupe
claude plugin install loupe@loupe
```

It installs nine skills, each covering one part of working a Loupe project:

| Skill | Covers |
|---|---|
| `loupe:loupe-documents` | Writing a document so the review UI reads well |
| `loupe:loupe-site-review` | Acting on widget comments and marking them addressed |
| `loupe:loupe-board` | Reading a board, writing a card, linking a pull request |
| `loupe:loupe-inbox` | Asking the project owner, and ending a turn on a blocking ask |
| `loupe:product-design` | An interactive product design session with the owner, from a card or a one-line idea |
| `loupe:loupe-stage-product-design` | One review round on a product document |
| `loupe:loupe-stage-tech-design` | A card entering the tech design column |
| `loupe:loupe-stage-implementation` | Building an approved design into a pull request |
| `loupe:loupe-stage-fix-round` | One round of review feedback, run by hand |

The plugin carried an MCP server until version 0.2.0, as an HTTP endpoint plus a
project API token you typed at install. Two things were wrong with it. A plugin
holds one set of config values per user, so that was one token across every
project you work in, and an MCP credential binds to one project. And the token
sat in your configuration for as long as the plugin was installed.

`loupe mcp` has neither problem. The project comes from `.loupe.yaml` in the
repository, so one setup serves every project, and the credential is an OAuth
login the CLI refreshes rather than a token you paste.

If you installed an older version, the plugin's server is still declared. Remove
it by reinstalling the plugin, and check with `claude mcp list`, which should
show `loupe` and no `plugin:loupe:loupe`.

## What the tools do

Roughly in the order an agent uses them:

| Tool | Purpose |
|---|---|
| `project_current` | Report which project this connection acts on, with its id, slug and name |
| `document_create` | Submit Markdown as a new document; returns a review URL and the language it was stored in |
| `document_revise` | Submit a new version, described by what changed |
| `document_get` / `document_list` | Read a document, or enumerate the project's, with search and filters |
| `document_get_review` | Verdict, threaded comments, answered decision blocks, and approved sections |
| `document_reply_to_comment` | Reply to a reviewer's thread |
| `document_mark_comment_addressed` | Mark a thread acted on |
| `document_highlight` | Tint the passages to read first (off by default — see below) |
| `document_rename` | Change the title without minting a version |
| `document_archive` / `document_unarchive` | Take it out of the listing, or put it back |
| `document_set_tags` / `document_set_references` | Group it, or link it to sibling documents |
| `document_set_series` | Place it at a numbered position in an ordered set, or take it out of one |
| `tag_list` | The project's existing tag vocabulary |
| `series_list` | The project's series, each with its document count and highest position |
| `series_rename` | Rename a series; every document in it keeps its position |
| `site_review_get` | Widget comments and their page context |
| `site_review_mark_comment_addressed` | Mark a widget comment acted on, so the next `site_review_get` skips it |
| `card_create` | Put a card on the project board (off by default — see below) |
| `card_list` | Read a page of the board, filtered by status, type, reporter or parent, with the board's columns |
| `board_columns` | List the board's columns, each with its slug, label, terminal flag and default flag |
| `card_search` | Search every card's title and body by words, finished ones included |
| `card_get` | Read one card, with the pull requests linked to it |
| `card_update` | Change a card, or move it to another column |
| `inbox_ask` | Hand questions and to-dos to the project owner (off by default, see below) |
| `inbox_search` | Search every inbox item's title and body by words, closed ones included |
| `inbox_join` | Add an open item that is already in the inbox to the session's own ask |
| `inbox_list` | Read a page of inbox items, filtered by state, ask, session, card or document |
| `inbox_get` | Read one inbox item, with its answer and its links |
| `inbox_withdraw` | Withdraw an open item that is no longer needed, with a reason |

### Finding a document without reading every one

`document_list` returns one row per document. Each row carries the description of
the document's current version, which says what the document is about. Read that
instead of calling `document_get` on every row.

Four arguments narrow the list:

- `search` matches full-text terms against every document's title and current
  content. Quotes and `OR` work as they do in a web search box. The rows come
  back by relevance instead of by recency.
- `status` keeps one state: `draft`, `in-review`, `approved` or `changes-requested`. An
  unknown value is refused, and the error names the accepted values.
- `tag` keeps the documents that carry one tag. The match ignores case and
  surrounding spaces. Read `tag_list` for the project's vocabulary.
- `series` keeps the documents in one series and orders them by their position
  in it. The match ignores case. Read `series_list` for the project's names.

The arguments combine. Archived documents stay out of every result until you
pass `includeArchived`. Paging works the same way under a search: pass `page`
while `hasMore` is true.

### The language a document is searched in

Search stems words, so it must know the language a document is written in. Every
document carries its own. `document_create` takes an optional `language`, and
`document_get` reports the value back.

The value is a PostgreSQL text-search configuration name, such as `english`,
`french`, `german`, `spanish`, `portuguese` or `russian`. Use `simple` for text
of mixed or unknown language, which then matches on whole words only. An unknown
name is refused, and the error lists the accepted ones.

A document that names no language takes the project's default. You choose that
default when you create the project, on the new-project form or on the first
step of the first-run wizard, and it is `english` for every project that came
before the field. The project settings screen changes it afterwards, and the
change applies only to documents written after it. Every document written before
this feature stays English, because that is how it was already indexed. Changing
a document's language after it exists needs a reindex, which no tool does yet.

### A site-review comment carries a list of anchors

`site_review_get` reports each comment's elements in an `anchors` list. It no
longer reports the `selector` and `text` fields, which held one element each.

```json
{
  "id": "...",
  "url": "https://staging.example.com/pricing",
  "body": "These two should sit side by side.",
  "anchors": [
    { "selector": ".plan-card", "text": "Starter", "quote": null, "quotePrefix": null, "quoteSuffix": null },
    { "selector": ".plan-note", "text": "Billed yearly per seat", "quote": "per seat", "quotePrefix": "Billed yearly ", "quoteSuffix": "" }
  ],
  "hasDrawing": false
}
```

A comment with several anchors says something about how those elements relate,
so read them together. An empty `anchors` list is a note about the page as a
whole.

An anchor whose `quote` is a string points at that run of text inside its
element, and `quote` is then the subject of the comment. One whose `quote` is
null points at the whole element. `quotePrefix` and `quoteSuffix` hold up to 32
characters of the surrounding page text; they exist so the widget can find the
passage again when the same words appear twice in one element, and they are not
part of what the reviewer said.

A live page has no version boundary, so a quote can stop matching. The widget
then falls back to drawing the element, and the payload keeps the quote. An
agent that cannot find a quote on the page should report that rather than guess
which text replaced it.

### A drawing is reported as a flag, not as points

A reviewer can draw freehand over the page as part of a comment.
`site_review_get` reports `hasDrawing` and nothing more. The strokes are vector
points measured against a live page, which no agent can render, so the points
would cost a large payload and buy nothing.

Read `hasDrawing: true` as "the reviewer pointed at something the words may not
name". Act on the words. Ask the reviewer when they do not say enough, rather
than guessing what the drawing meant. The reviewer's own data export carries the
points in full, under `strokes` in `site_reviews.json`.

## Which sections are settled

`document_get_review` returns a `sections` list beside the comments, one entry
per section of the current version:

```json
{ "heading_id": "heading-goals", "level": 2, "title": "Goals", "standing_approval_count": 1 }
```

`standing_approval_count` counts **every reviewer** whose approval of that
section still matches its text. It is 0 while nobody's approval stands. The
count is not scoped to the identity the token belongs to. It also names no
reviewer, for the same reason a comment reports only `agent` or `human`.

Treat a section above 0 as settled and leave its text alone. Rewriting a section
changes its digest, which drops the approval and puts the section back in front
of the reviewer. See [Documents and review](documents.md).

## Two things agents get wrong

**Comment ids do not survive `document_revise`.** Re-anchoring copies a comment
onto the new version and leaves the original behind, so replying after revising
writes into rows nobody reads. Reply first, then revise — or revise, then
re-read the review for fresh ids. See [Documents and review](documents.md).

**A decision block's id is permanent.** Rewording its options is safe; changing
its id silently discards the reviewer's answer.

A decision reports its `type`. A single-choice block answers in `selected` and
`selected_index`. A multi-choice block answers in `selections`, and reports null
in `selected`. See [Documents and review](documents.md) for the syntax.

## What `site_review_mark_comment_addressed` skips

The call never fails on a comment it cannot mark. It marks the rest and returns
the others under `skipped`, each with a reason.

| Reason | Meaning |
|---|---|
| `unknown` | No such comment on this project, or the reviewer deleted it |
| `invalid_id` | The id is not a UUID |
| `already_addressed` | An earlier pass marked it |
| `resolved` | A human signed it off in the web UI |

The reason is best-effort. The tool writes the status first, then reads the
comment again to learn why it skipped. Another writer can change the comment
between those two steps, so a reason can name the wrong status. The skip itself
is always correct, because the write only touches a comment that is still
pending.

## Configuration

`document_highlight` is behind the `review.highlights.enabled` feature flag,
seeded **off**. An agent tinting the passages a human should read first steers
the review, which is a nudge an operator opts into rather than inherits. While
it is off the tool is absent from `tools/list` and from the Connect page, so an
agent never learns of a tool this instance would refuse — switch it on in
**Admin → Feature flags**. A client holding a tool list from before the flag
changed and calling it anyway gets a plain refusal, not a broken call.

The `card_*` tools and `board_columns` are behind the `board.enabled` feature
flag, seeded **off**. A board an agent writes to is a second place work is
tracked, so the operator opts in. The gate behaves the same way as the one above: while the flag
is off the tools are absent from `tools/list` and from the Connect page, and a
client that calls one anyway gets a plain refusal.

Each board has its own columns. `board_columns` lists them in board order, and
`card_list` returns the same list in `columns` beside its cards. The `status`
argument of `card_create`, `card_update` and `card_list` takes a column slug. An
unknown slug is refused, and the error lists the slugs the board has. A terminal
column is where finished work goes, and a card that enters one gets a
`completedAt`.

A card created with no `status` lands in the default column. Renaming a column
changes its slug. No tool writes a column.

The board has no delete tool. An agent moves a card to a terminal column; only a
person removes one. `card_update` also refuses to change `reporter`, because
that field records who first raised the card.

`card_update` reads an omitted field as "leave it alone". `pullRequestUrls` is
the one field where an omitted list and an empty list differ: omit it and the
links stay, send `[]` and every link is removed.

Every card carries a `number` beside its `cardId`. The number counts from 1
inside one project, so a person can say "card 42" and an agent can name a branch
after it. Two projects each have a card 1. `card_get` and `card_update` take
either `cardId` or `number`, never both. A number resolves only inside the
project that the connection is bound to. The other tools take no number.

A `card_list` or `card_search` row is a summary with eight fields: `cardId`,
`number`, `title`, `type`, `status`, `reporter`, `parentCardId` and
`updatedAt`. `parentCardId` is null for a card with no parent.

A card has a `type`: `feature`, `bug`, `security`, `tooling`, `docs`, `idea` or
`epic`. An epic is one feature that is too large for one pull request, and its
child cards hold the parts. `card_create` and `card_update` take
`parentCardId`, the id of an epic of the same project. On `card_update`, omit it
to keep the parent, send an empty string to clear it, and send an id to set it.
Only an epic can be a parent, and an epic cannot have a parent. A card with a
parent cannot become an epic, and an epic with children keeps its type. Both
tools also take `laneEnabled`, which says whether the board draws a lane for an
epic. It defaults to `true`.

`card_list` takes `parentCardId` as a filter, which reads the children of one
epic. The full card, from `card_get` or from `card_list` with `full`, carries
four more keys. `parent` holds `cardId`, `number`, `title` and `status`, or
null. `laneEnabled` is a boolean. `children` lists the children of an epic with
the same four keys. `progress` holds `done` and `total` for an epic, and null
for any other card. A child counts as done when it sits in a terminal column.

The `inbox_*` tools are behind the `inbox.enabled` feature flag, seeded
**off**. The gate behaves the same way as the board gate: while the flag is off
the tools are absent from `tools/list` and from the Connect page, and a client
that calls one anyway gets a plain refusal.

An item is one question, review request, or to-do for the project owner. An ask is the set of
items that one agent session hands over at once. `inbox_ask` and `inbox_join`
take a required `sessionId`, which a Claude Code session reads from
`$CLAUDE_CODE_SESSION_ID`. One session holds at most one open ask, so a second
`inbox_ask` from the same session adds its items to that ask. A question blocks
by default; a to-do or review requires `blocking: true` to block. An ask with no blocking item closes at once,
and its items stay open in the inbox.

`inbox_list` and `inbox_get` take an optional `readerSessionId`. Pass your own
session id there to record that you read the answers of your closed asks. The
[ask check endpoint](../extending/cli-bridge.md#ask-check-endpoint) reports
these reads, so a bridge can skip a resume when the session already read every
item of the ask. A read counts only for an item that the call returns and that a
closed ask of that session holds. The first read is kept.

Loupe trusts the `readerSessionId` it receives, so pass only your own session
id there. The `sessionId` argument of `inbox_list` is a filter and never records
a read, so filtering by another session's id leaves its answers unread.

With `readerSessionId`, each `inbox_list` row also carries the response:
`options`, `selectedOptions`, `answerText`, `closeNote`, and `review`. Without it, a row is
the short summary, and `inbox_get` reads the answer.

Create a Review item with `kind: "review"` and exactly one `reviewDocumentId` or `reviewPullRequestId`.
Read `pullRequestId` from `card_get`; it identifies a stored card link, not a code-host number.
Review items take no question options, `multiple`, or `freeText`.
The target document or PR card is linked automatically, alongside any context links you supply.

`review` carries the target, verdict, note, reviewer, submission time, and reviewed document version.
It is null for other item kinds. An unanswered review has a null verdict.
`review.withdrawal` separately records a document verdict withdrawal without changing the completed answer.
PR results stay in Loupe and do not submit a code-host review.

Every card id and document id an item links to must belong to the token's
project, or the call is refused. An item closes as `obsolete` when every card it
links to is moved into a terminal column, by a move or by a column delete. A
move or a delete while the inbox is off closes no item. Only an open item can be
withdrawn.

`inbox_ask` takes at most 20 items in one call. An item takes at most 20
options, 20 card ids, 20 document ids and a body of 20,000 characters. Each
option is at most 500 characters. Its title is one line, and its options must
differ from each other. The context of an ask
is at most 10,000 characters, counting what earlier calls appended. A withdraw
reason is at most 2,000 characters. A second call that names a different
`bridgeId` from the one the open ask holds is refused.

`MCP_ALLOWED_HOSTS` is a DNS-rebinding allowlist — hostnames only, no port. It
must contain the hostname agents actually use, or every call is rejected with a
403 that names the variable and echoes the host it rejected. See
[Environment variables](../reference/environment.md).

An unauthenticated `POST /mcp` answers **401, not 404**, with a
`WWW-Authenticate` header that points at the protected resource metadata. A 404
means the route did not register. A 403 with a plain-text body is the rebinding
guard. A 403 with `insufficient_scope` means the sign-in is valid and does
not carry the `mcp` scope.
