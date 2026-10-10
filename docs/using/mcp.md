---
title: "The MCP endpoint"
description: "How an AI agent creates and revises documents, and how it signs in."
---

`POST /mcp` is a Model Context Protocol endpoint. An agent signs in with OAuth
and calls tools that create documents, revise them, and read back what humans
said. A long-form plan then gets considered review instead of scrolling past in
a terminal.

## Set up with one prompt

A coding agent can do the whole setup for you. A project's Connect page and
the first-run wizard at `/welcome/connect` show a short prompt with a Copy
button. Paste it into Claude Code or Codex, in the repository of the project:

```text
Set up Loupe for this repository. Run
curl -fsSL "https://loupe.example.com/setup.md?project=<project id>"
and follow the steps it prints, in order. Use curl, not a web fetch tool.
```

`/setup.md` is public and returns Markdown steps for an agent. The steps
install the CLI with `/install.sh` when `loupe version` fails, and stop on
Windows, which has no build. They sign in with `loupe login` and bind the repository with
`loupe init`. Then they connect the MCP server, install the skills, and check
the result with `loupe status`. When your rule file holds worker entries under
`work:`, the steps then ask whether each bridge worker gets its own folder.
If it does, the agent adds a
[`before` command](../extending/cli-bridge.md#before-command) to those entries,
and a teardown entry. The agent asks you when a step needs you, such
as the browser approval of the sign-in. It never writes a token or a password.

The `project` parameter fills the project id into the commands. The page
accepts only a UUID and ignores any other value. With no valid id, the steps
run `loupe init` and ask you which project to use.

The two agents differ in two steps:

| Step | Claude Code | Codex |
|---|---|---|
| MCP server | `loupe init --mcp` declares `loupe mcp` at user scope | `codex mcp add loupe -- loupe mcp --project <project id>` |
| Skills | The Claude Code plugin, see [below](#the-claude-code-plugin) | A copy of `plugins/loupe/skills/` in `~/.agents/skills/` |

Codex gets the project id in its server declaration, and Claude Code reads it
from `.loupe.yaml`. At the end, the agent tells you to restart it. The new
session then calls `project_current` and names the project.

The prompt asks for curl because a web fetch tool can return a summary of the
page instead of the page. An agent that follows a summary can skip a step or
change a command.

## Connecting through the CLI

This is the way to connect, and the setup prompt above does it for you. One
sign-in serves every repository, and each repository names its own project, so
a new project needs no new login.

`loupe mcp` connects an agent to this endpoint with no token in any file. The
CLI already holds a login, so the command signs each request with it, and the
project comes from the repository rather than from the credential.

Install the CLI, sign in once, and name the project in each repository:

```bash
curl -fsSL https://<your Loupe>/install.sh | sh
# or, with Homebrew: brew install ubermuda/tap/loupe
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
project, or refuses and asks which one when you own several. The refusal
names `loupe init` as the fix.

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
loses its Loupe tools for the rest of the agent's run. It also waits and tries
again when Loupe answers with a rate limit or a short outage during a deploy, and
it gives the agent an error for that one call when the wait ends.

One sign-in is enough. `loupe login` asks for `agent mcp projects`, so the same
login serves `loupe bridge` and `loupe mcp`, and it covers every project you own
including ones you create later. The approval page says so before you allow it.

`loupe status` checks the setup. Run it in the repository. It calls
`project_current` through the MCP server and prints the name of the project.
When a rule file exists, it also checks each account of the file, as
[Account checks](../extending/cli-bridge.md#account-checks) says.

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

It installs one skill for each part of working a Loupe project:

| Skill | Covers |
|---|---|
| `loupe:loupe-documents` | Writing a document so the review UI reads well |
| `loupe:loupe-site-review` | Acting on widget comments and marking them addressed |
| `loupe:loupe-board` | Reading a board, writing a card, linking a pull request |
| `loupe:loupe-inbox` | Asking the project owner, and ending a turn on a blocking ask |
| `loupe:loupe-workers` | Reading worker runs and bridges, and stopping, resuming or cancelling a run |
| `loupe:loupe-analysis` | Running an analysis: a cost report, or a comparison of the variants of an experiment, with proposals |
| `loupe:loupe-discovery` | A read-only discovery run that writes the readiness report of a project |
| `loupe:product-design` | An interactive product design session with the owner, from a card or a one-line idea |
| `loupe:loupe-stage-product-design` | One review round on a product document |
| `loupe:loupe-stage-tech-design` | A card entering the tech design column |
| `loupe:loupe-stage-implementation` | Building an approved design into a pull request |
| `loupe:loupe-stage-fix-round` | One round of review feedback, from a fix request or run by hand |
| `loupe:loupe-stage-merge` | Merging a ready pull request, or updating one that is behind its base |
| `loupe:loupe-stage-repair` | Fixing the cause of failed work, after its retries run out |

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
| `project_update` | Change the project's name, description, domain or search language. A new name also changes the slug |
| `project_origins_set` | Replace the list of site origins the sign-in widget may run on |
| `readiness_guide_set` | Show or hide the readiness checklist on the Workshop |
| `readiness_get` | Read the readiness checks of the project, each with its status, and the state and the `runId` of the latest discovery run. `readiness_report_submit` takes that `runId`. It works while the Workshop guide is hidden |
| `discovery_start` | Start a read-only [discovery run](workshop.md#run-discovery) on a new Backlog card. A running bridge must serve the project, the project needs a workflow with board automation on, and one run can be open at a time |
| `readiness_report_submit` | Submit the report of a [discovery run](workshop.md#review-the-report): the findings, and the cards it proposes. Loupe writes the report as a document for review. When the owner approves it, each ticked proposal becomes a card in Next. A run takes one report |
| `workflow_get` | Read the [workflow](workflows.md) of the project: its template and version, the board columns with the slot of each, and each kind of work with the rules that ask for it and its `checks` |
| `document_create` | Submit Markdown as a new document, or as a draft with `draft`; returns a review URL, the language it was stored in and its status |
| `document_revise` | Submit a new version, described by what changed |
| `document_publish` | Send a draft to review, so it reaches the reviewer's inbox |
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
| `feedback_list` | The project's site-review feedback, each item with the card it belongs to (off with the board, see below) |
| `feedback_mark_addressed` | Mark feedback items acted on, so the next `feedback_list` skips them (off with the board, see below) |
| `agent_review_submit` | Submit the review of a pull request of a card, with findings on file lines. Only a running `review` worker of the card can call it. Loupe stores the review against the commit it names, and the `agent-review-check` forge write shows it as a check on the pull request. The result field `current` says whether that commit matches the last head commit that Loupe read from the forge |
| `card_create` | Put a card on the project board (off with the board, see below) |
| `card_list` | Read a page of the board, filtered by status, type, reporter or parent, with the board's columns |
| `board_columns` | List the board's columns, each with its slug, label, terminal flag, default flag and backlog flag, and the card types of the project |
| `card_search` | Search every card's title and body by words, finished ones included |
| `card_get` | Read one card, with the pull requests and their stored state, the latest agent review of each pull request as `agentReview`, and the feedback linked to it |
| `card_get_history` | Read a page of one card's history, newest first: its creation, its moves and the automation's actions |
| `card_update` | Change a card, or move it to another column |
| `card_run_open` | Record an open interactive session on a card, and optionally move the card in the same step |
| `card_run_close` | Close the interactive run a session opened on a card, and ask the bridges for the usage of the run |
| `column_create` | Add a column at the end of the board, from a label |
| `column_update` | Rename a column, or set whether it is terminal |
| `column_reorder` | Put the columns in a new order; Backlog stays first |
| `column_delete` | Delete a column, and move its cards to `targetColumn` |
| `automation_settings_update` | Change the board's Automation settings |
| `inbox_ask` | Hand questions and to-dos to the project owner (off by default, see below) |
| `inbox_search` | Search every inbox item's title and body by words, closed ones included |
| `inbox_join` | Add an open item that is already in the inbox to the session's own ask |
| `inbox_list` | Read a page of inbox items, filtered by state, ask, session, card or document |
| `inbox_get` | Read one inbox item, with its answer and its links |
| `inbox_withdraw` | Withdraw an open item that is no longer needed, with a reason |
| `inbox_settings_update` | Turn the inbox wait switches on or off (off with the inbox) |
| `worker_run_list` | Read a page of the worker runs, newest first, filtered by state, card, work kind, bridge, harness, account, model, words or the time a run ended. Each row gives the reason the run ended, its harness and account, its usage per model, its model, its experiment and variant, and its metrics. The metrics hold the sums of the run and its timing: the tool time, the model time, the idle gaps, the subagent time, and the count, failures and longest duration of its tool calls. They also hold the host of the run: the mean CPU use, the peak memory and swap, the count of runs that overlapped it on its bridge, and whether its machine ran on battery |
| `worker_run_get` | Read one worker run in full, with every run of its series, its state changes, its output, its metrics and the commands sent to its bridge |
| `worker_run_tool_calls` | Read a page of the tool calls of one worker run, in the order the worker made them. Each call gives its tool, its start, its duration, its error flag, whether a subagent made it, and its signatures. `perPage` defaults to 20, with a maximum of 100 |
| `bridge_host_samples` | Read a page of the host samples of the bridge that ran one worker run, oldest first, from the start of the run to its end, or to now while the run is open. Each sample gives its time, the use of each core, the memory and swap in use, the total memory, the battery charge and the power source. The samples cover the whole machine, so they cover every run on that bridge at that time. `perPage` defaults to 100, with a maximum of 500 |
| `metric_list` | List the metrics of the worker runs and the finished cards, with the units, statistics and groups each one takes. It adds one `bucket-time:<name>` entry for each bucket that has time on a run of the project |
| `metric_query` | Read one metric over time, by run or by card. Each group gives a series with a total, a point per period and the rows behind it. The metric `bucket-time:<name>` reads the time of the main-session tool calls of a run in one bucket, in milliseconds. A name is 1 to 64 characters of `a-z`, `0-9`, `_` and `-`. A run with no data for any bucket has an unknown value |
| `experiment_get` | Read the comparison of one experiment: the variants, each metric with its likely range per variant and whether it gives a clear answer, and one page of the cards, with the reasons a card is left out |
| `analysis_get` | Read one analysis, with its topic, its range, the experiment that an experiment analysis compares, its model and effort, its state and reason, its cost so far and its proposals |
| `analysis_report` | Finish an analysis with its report document and at most 20 proposals, each a `card` or a `bucket-rule` |
| `analytics_settings_get` | Read the analysis settings of the project: the default model and effort, the programs with subcommands, and whether the bridge sends the full text of each tool call |
| `analytics_settings_update` | Change the analysis settings of the project. An empty model or effort, or an empty list of programs, clears the project value, so the instance default applies |
| `bridge_list` | List the bridges that follow the project, with their name, their push login, their heartbeat, their pause, their worker pools, their account checks and their open runs |
| `worker_run_resume` | Ask the bridges to resume up to 50 ended worker runs, each resumed or refused on its own |
| `worker_run_stop` | Ask the bridge to stop a queued or running worker run. The stop does not make the card unmanaged, so call `card_hold` for that |
| `card_hold` | Make a card unmanaged, by `cardId` or `number`. The workflow makes no move and starts no work on the card, and no bridge starts a worker on it, until `card_release`. A live run goes on |
| `card_release` | Make an unmanaged card managed again. The queued runs on the card then start |
| `card_pause_release` | End the workflow pause of a card, by `cardId` or `number`, so the paused rule runs again with a fresh budget. It ends a pause of kind `retries`, `work-limit`, `work-timeout` or `work-stopped`. It does not end a hold, which `card_release` ends |
| `bridge_command_cancel` | Withdraw the resume or stop command that waits on a worker run, before its bridge reads it |

### Changing the project settings

`project_update`, `project_origins_set` and `readiness_guide_set` change the
settings of the bound project, as **Project settings** does.
`inbox_settings_update` changes the switches of the inbox settings page. An
argument that a call omits keeps its value. On `project_update`, an empty
`description` or `domain` clears it. A new name also changes the project slug,
so a bridge rule file that names the old slug stops matching.
`project_origins_set` replaces the whole list of allowed origins, and an empty
list clears it. The tools refuse what the settings pages refuse, and the error
names the argument to fix.

### Staging a draft

Pass `draft: true` to `document_create` to keep a document out of review.
A draft does not reach the reviewer's inbox, and `document_revise` keeps it a draft.
Call `document_publish` to send it to review.
For a document that is not a draft, `document_publish` changes nothing and returns `published: false`.
It refuses an archived document.

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

### A feedback item carries a list of anchors

`feedback_list` and `card_get` report each feedback item's elements in an
`anchors` list.

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

An item with several anchors says something about how those elements relate,
so read them together. An empty `anchors` list is a note about the page as a
whole.

An anchor whose `quote` is a string points at that run of text inside its
element, and `quote` is then the subject of the item. One whose `quote` is
null points at the whole element. `quotePrefix` and `quoteSuffix` hold up to 32
characters of the surrounding page text; they exist so the widget can find the
passage again when the same words appear twice in one element, and they are not
part of what the reviewer said.

A live page has no version boundary, so a quote can stop matching. The widget
then falls back to drawing the element, and the payload keeps the quote. An
agent that cannot find a quote on the page should report that rather than guess
which text replaced it.

### A drawing is reported as a flag, not as points

A reviewer can draw freehand over the page as part of a note.
`feedback_list` and `card_get` report `hasDrawing` and nothing more. The strokes are vector
points measured against a live page, which no agent can render, so the points
would cost a large payload and buy nothing.

Read `hasDrawing: true` as "the reviewer pointed at something the words may not
name". Act on the words. Ask the reviewer when they do not say enough, rather
than guessing what the drawing meant. The reviewer's own data export carries the
points in full, under `strokes` in each card's `feedback` in `cards.json`.

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
in `selected`. Each decision also reports the reviewer's `note`, and `updated_at`
for the time of the last save. Both are null while the decision has no answer,
and `note` is also null when the reviewer picked an option and wrote no note.
See [Documents and review](documents.md) for the syntax.

## What `feedback_mark_addressed` skips

The call never fails on an item it cannot mark. It marks the rest and returns
the others under `skipped`, each with a reason.

| Reason | Meaning |
|---|---|
| `unknown` | No such item on this project, or the reviewer deleted it |
| `invalid_id` | The id is not a UUID |
| `already_addressed` | An earlier pass marked it |
| `resolved` | A human signed it off, or its card finished |

The reason is best-effort. The tool writes the status first, then reads the
item again to learn why it skipped. Another writer can change the item
between those two steps, so a reason can name the wrong status. The skip itself
is always correct, because the write only touches an item that is still
pending.

A card that moves into a terminal column resolves its pending and addressed
feedback. So an agent that finishes a card with `card_update` also resolves the
feedback on it. See [Review feedback on a card](board.md#review-feedback-on-a-card).

## Configuration

`document_highlight` is behind the `review.highlights.enabled` feature flag,
seeded **off**. An agent tinting the passages a human should read first steers
the review, which is a nudge an operator opts into rather than inherits. While
it is off the tool is absent from `tools/list` and from the Connect page, so an
agent never learns of a tool this instance would refuse — switch it on in
**Admin → Feature flags**. A client holding a tool list from before the flag
changed and calling it anyway gets a plain refusal, not a broken call.

Each board has its own columns. `board_columns` lists them in board order, and
`card_list` returns the same list in `columns` beside its cards. The `status`
argument of `card_create`, `card_update` and `card_list` takes a column slug. An
unknown slug is refused, and the error lists the slugs the board has. A terminal
column is where finished work goes, and a card that enters one gets a
`completedAt`.

A card created with no `status` lands in Backlog, whose slug is `backlog`.
Backlog is not drawn as a board column, and it has its own page. `board_columns`
lists it with `default` and `backlog` both true. Renaming a column changes its
slug.

`column_create`, `column_update`, `column_reorder` and `column_delete` change
the columns, as **Board settings** does. They refuse the changes that Board
settings refuses, and the error says what the agent can fix. For example, a
delete of a column that holds cards needs `targetColumn`.
`automation_settings_update` turns the workflow of the board on or off with
`enabled`, as the **Automation** tab does. A call that omits it keeps the value.

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

A `card_list` or `card_search` row is a summary with nine fields: `cardId`,
`number`, `title`, `type`, `status`, `reporter`, `parentCardId`, `updatedAt` and
`state`. `parentCardId` is null for a card with no parent. `state` is null, or
holds `kind`, `code` and `since`: what the card needs now on the board, which is
`stuck`, `needs-you`, `working` or `waiting`. The full card adds `reason` and
`others` to `state`. [The board](board.md#the-mcp-tools) lists the codes.

A card has a `type`. The workflow template of the project declares the types,
so each project can have its own. `board_columns` returns them in `types`, and
`defaultType` names the type of a card that is created with no type chosen. Each
entry has a `key`, a `label`, and two capabilities: `children` and `lane`. The
`type` argument of `card_create`, `card_update` and `card_list` takes a key. A
key that the template does not declare is refused, and the error lists the
declared keys.

A type with the `children` capability groups other cards. Its cards are one
feature that is too large for one pull request, and their child cards hold the
parts. `card_create` and `card_update` take `parentCardId`, the id of a card of
the same project. On `card_update`, omit it to keep the parent, send an empty
string to clear it, and send an id to set it. Only a card of a type with the
`children` capability can be a parent, and such a card cannot have a parent. A
card with a parent cannot change to such a type, and a card with children keeps
its type. Both tools also take `laneEnabled`, which says whether the board draws
a lane for a card of a type with the `lane` capability. It defaults to `true`.

Both tools also take `childDesign`, for a child of a parent whose tech design is
approved. The value `inherit` links the tech design of the parent. The value
`own` moves the card to Tech design, so a call that passes `own` passes no
`status`. When the owner would otherwise get the
[unplanned child question](workflows.md#asking-about-an-unplanned-child),
`card_create` is refused without the choice, and so is a `card_update` that
sets `parentCardId`. The call is also refused when the card has no parent, when
the parent has no tech design to inherit, or when the workflow does not allow
the move. A refused call changes nothing.

`card_list` takes `parentCardId` as a filter, which reads the children of one
card. The full card, from `card_get` or from `card_list` with `full`, carries
`state` on each entry of `pullRequests`, the last state Loupe read.
[The board](board.md#the-mcp-tools) lists its keys. It also carries four more
keys. `parent` holds `cardId`, `number`, `title` and `status`, or
null. `laneEnabled` is a boolean. `children` lists the children of a card with
the same four keys. `progress` holds `done` and `total` for a card of a type with
the `children` capability, and null for any other card. A child counts as done when it sits in a terminal column.

The full card also carries `pause`, the active workflow pause of the card, or
null. A pause holds `pauseId`, `kind`, `reason`, `ruleId` and `since`. The kind
is `rule`, `retries`, `work-limit`, `work-timeout` or `work-stopped`. `card_list` takes `paused`
as a filter: `true` keeps the paused cards, and `false` keeps the cards with no
pause. `card_pause_release` ends a pause of any kind except `rule`. Pass the
`pauseId` you read, and the tool refuses with `pause-changed` when the active
pause is a different one.

The `inbox_*` tools are behind the `inbox.enabled` feature flag, seeded
**off**. The gate behaves the same way as the board gate: while the flag is off
the tools are absent from `tools/list` and from the Connect page, and a client
that calls one anyway gets a plain refusal.

An item is one question, review request, or to-do for the project owner. An ask is the set of
items that one agent session hands over at once. `inbox_ask` and `inbox_join`
take a required `sessionId`. A session reads it from the first set variable of
`$LOUPE_SESSION_ID`, `$CLAUDE_CODE_SESSION_ID` and `$CODEX_THREAD_ID`. The
bridge sets `$LOUPE_SESSION_ID` for each session it starts. One session holds at most one open ask, so a second
`inbox_ask` from the same session adds its items to that ask. A question blocks
by default; a to-do or review requires `blocking: true` to block. An ask with no blocking item closes at once,
and its items stay open in the inbox.

`inbox_list` and `inbox_get` take an optional `readerSessionId`. Pass your own
session id there to record that you read the answers of your closed asks. A
read counts only for an item that the call returns and that a
closed ask of that session holds. The first read is kept.

Loupe trusts the `readerSessionId` it receives, so pass only your own session
id there. The `sessionId` argument of `inbox_list` is a filter and never records
a read, so filtering by another session's id leaves its answers unread.

Every item row carries `origin`. The value is `agent` for an item that an agent
asked, or `loupe` for an [automatic item](inbox.md#automatic-items). Loupe opens
an automatic item with the kind `wait` while a card waits for a person, and
closes it when the card stops waiting. An item with the kind `notice` states a
fact of the project. A notice holds no card and takes no answer.

`inbox_get` also returns `cardId` and `waits`. For a `wait` item, `cardId` names
the card, and `waits` lists each reason the card waited, current and ended, in
start order. Each entry holds `trigger`, `type`, `reason`, `documentId`,
`versionNumber`, `runId`, `pullRequestId`, `headSha`, `pauseId`, `startedAt`, `endedAt`
and `endReason`. `type` is `document`, `pull-request`, `worker-run` or `card-pause`. `reason` is a
code, such as `waiting-for-review`, `new-commits-after-approval`, `blocked`, `gave-up`,
`waits-for-person` or the kind of a pause. It is no sentence. An id, a commit or a date that does not apply is null. `endedAt` and `endReason` are null for a
current wait. `endReason` is `resolved`, `card-finished`, `card-deleted`,
`switched-off` or `dismissed`. For any other kind,
`cardId` is null and `waits` is empty.

Each entry of `asks` in `inbox_get` carries `origin` too. The `sessionId` of a
Loupe ask is null.

`inbox_join` accepts an open `wait` item. Your ask then closes when Loupe
closes the item. A bridge that started you then resumes you.
`inbox_withdraw` refuses a `wait` item or a `notice`, because only Loupe closes
it.

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
