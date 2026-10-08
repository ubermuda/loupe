---
name: loupe-discovery
description: "Use when a bridge runs a discovery run of a Loupe project, when a prompt names loupe-discovery, or when calling readiness_get, workflow_get and readiness_report_submit to write a readiness report."
---

# Running a Loupe discovery

Discovery finds what a project needs before agents can work its cards. You read the project setup, the workflow and the repository, and you write a readiness report. The owner ticks proposals in the report. When the owner approves the report, Loupe creates one card in Next for each ticked proposal.

The prompt names the project, the `projectId`, the card number and the `cardId` of the discovery card.

## Read only

Discovery changes nothing. Every change goes into the report as a proposal. Do not commit, push or create a branch. Do not change a file in the repository. Do not create, change or move a card. Do not change the project, its columns, its automation, its inbox or its origins.

These tools are forbidden: `card_create`, `card_update`, `column_create`, `column_update`, `column_reorder`, `column_delete`, `automation_settings_update`, `project_update`, `project_origins_set`, `inbox_settings_update`, `readiness_guide_set` and `discovery_start`.

You can run commands that only read, such as `git remote -v`, `ls` or `cat`. Do not run a build or a test suite that writes outside a temporary directory. Read how the project runs them instead.

## Steps

1. Call `readiness_get`. Each of its `rows` has a `key`, `done` and `status`. The keys are `agent`, `workflow`, `bridge`, `github`, `agent_account` and `repository`. These rows tell you what Loupe sees of the setup.
2. Read the `discovery` field of `readiness_get`. It is the latest run of the project. Its `cardNumber` must be the card number of your prompt. If it is not, stop and report the mismatch.
3. Keep `discovery.runId`. It is the `runId` that `readiness_report_submit` takes. When `discovery.state` is not `requested`, the run takes no report, so stop and report the state.
4. Call `workflow_get`. It returns the `template` with its `key` and `version`, and the `columns`. Its `kinds` list each kind of work that the workflow asks a bridge to do. Each kind has an `origin` of `template` or `app`, the `rules` that ask for it, and its `checks`. The checks name what that work needs.
5. Read the repository. Find the build and test commands, the CI config and the agent instruction files, such as `AGENTS.md` or `CLAUDE.md`. Read `.loupe/lifecycle.md` and its sections when the file exists. Read the git remote.
6. Apply the generic checks.
   - How to build and test the project, and whether CI runs those commands.
   - An instructions file for agents, such as `AGENTS.md` or `CLAUDE.md`.
   - The GitHub remote, the GitHub App, the bridge and the agent GitHub account. Use the `readiness_get` rows for what Loupe sees.
   - Each setup step that an agent cannot do through the MCP. For example, only the owner can install the GitHub App in a browser, and no tool changes the workflow template.
7. Apply each check of each kind against the repository. A kind can have an empty `checks` list. A write fallback, such as `sync` or `merge`, carries no list. Write no finding for such a kind. Name it in the `summary` as a kind that only the generic checks cover.
8. Write one proposal for each gap that a card can close. Give it a `key` that is unique and kebab-case, a `title`, a `type` and a `body`. The `type` is a type key from `board_columns` that has no `children` capability. Write the body so that a session with no context can act on it: what to do, why, and where.
9. Call `card_search` with the key words of each gap before you write its proposal. `card_search` returns finished cards too, so read each row's `status`. When an open card covers the gap, set `openCardNumber` to its number.
10. Some gaps need the owner on a settings page or in a browser. Do not propose a card for such a gap. Say what the owner must do in the evidence of the finding.
11. Call `readiness_report_submit` with `runId`, `workflow`, `findings`, `proposals` and `summary`. Set `workflow` to the template `key`. Each finding has a `check`, a `status` of `ready` or `gap`, and an `evidence` in short Markdown. Keep the `summary` to a few sentences. The tool returns the `documentId` and the `url` of the report.
12. Open a review of the report in the inbox. Call `inbox_ask` with one item of `kind` `review`, `reviewDocumentId` set to the `documentId`, and `cardIds` set to the `cardId`. Pass `sessionId` and `bridgeId` from the footer line "Your session id is … and your bridge id is …". Leave `blocking` false, so the ask closes at once. Loupe does not put the report in the inbox for you. When the prompt has no such line, the inbox is off, so skip this step.
13. End with a short reply that gives the report `url` and the number of gaps.

## When something fails

A tool refusal names its fix. When the fix is a change that discovery must not make, stop and report the refusal in your final reply.

Two refusals of `readiness_report_submit` mean that the run takes no report: "This run does not wait for a report now" and "This run has a report already". Do not retry, and do not start a new run. Report the refusal and stop.
