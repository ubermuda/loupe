---
title: Workshop
description: "See open requests, cards, and recent project activity."
---

Open a project to see its Workshop. The summary counts belong to that project.
It shows open requests, open cards, and completed cards.
Cards in terminal columns count as completed, regardless of the column's name.
A disabled inbox shows an explanation instead of a zero count.
The project owner can also see a [readiness guide](#the-readiness-guide), which can take the place of these sections.

Needs you shows the six oldest open inbox items, oldest first.
The list includes [automatic items](inbox.md#automatic-items), which Loupe opens for a waiting card.
The count includes all open items, including those outside this list.
Select **Open inbox** to see the full inbox.
Select a request to open its question, to-do, or review in the inbox.
The row shows the item kind and whether it blocks an agent.
When the inbox is disabled, Workshop says so instead of claiming that no requests need attention.

In motion shows the cards that have an open [worker run](worker-runs.md): queued, resumed or running.
A worker run and an interactive session both count, and the card can be in any column.
A card with two open runs shows the most advanced one, so a running session comes before a queued worker.
Running cards come first, then resumed cards, then queued cards. Inside each group, the run that has waited or run the longest comes first.

Each tile shows the card number, column, and title.
It also shows the state of the run and the rule name, or **Interactive** for a session.
The elapsed time counts from the start of a running run, or from the arrival of a queued or resumed run.
The section updates without a page reload when a run or a card changes.

The section shows six tiles. When more cards are in motion, **+N more** opens the worker runs page with the **Open runs** filter.
A run whose card was deleted shows no tile and does not count.
When nothing is in motion, the section says so.

Select a tile to open the card drawer without leaving Workshop.
Escape closes the drawer and returns focus to the tile.
If a card fails to load, Retry loads it again. Close card returns to Workshop.
Open board shows the full board, including the cards that are not in motion.

Recent activity shows the four latest recorded project events.
An event links to its related card when that card remains available.
Other events link to Activity, which shows the full event details and delivery state.

The connection links open the existing agent and bridge setup pages.
Workshop does not infer running agents from open cards or bridge heartbeats.

## The readiness guide

The readiness guide shows the project owner what the project needs before agents can work on it.
Other members do not see it.
The guide is a checklist named Ready for agents. It counts the rows that are done, for example "2 of 6".

- Agent connected is done after an agent first calls the MCP server for this project. Connect opens the Connect page.
- Workflow is done when the project has a workflow. The row shows the name of the workflow template.
- Bridge running is done when a bridge that serves the project sends heartbeats. Start opens the Connect page.
- GitHub App is done when a repository connects to the project through the GitHub App. Install starts the install when the instance has a GitHub App.
- Agent GitHub account is done when the owner records the login of the agent account, and a running bridge of the project pushes as that login. The row says which part is missing. Set up opens the [agent account page](../getting-started/agent-github-account.md).
- Repository shows the latest [discovery](#run-discovery) of the project. It is done when the owner reviewed the discovery report.

When Needs you is empty and nothing is in motion, the guide is the only content of the Workshop.
Its heading names the project, for example "Get Acme ready for agents".
A Run discovery panel comes first and shows the status of the Repository row. The checklist and the Hide this guide control follow it.
Open cards with no worker run, such as cards in Backlog or Next, do not change this layout.
The worker run of the discovery card does not change it either, so the guide stays in front while discovery runs.

When an item needs you or a card is in motion, the Workshop shows its normal layout.
The checklist then sits at the top of the right column. Its × control hides the guide.

A hidden guide stays hidden. To show it again, use the [Agent readiness](projects.md#agent-readiness) tab of Project settings.
When an instance upgrades, a project that has a card outside Backlog starts with the guide hidden.

## Run discovery

Discovery reads the repository and the workflow of the project, and writes a readiness report.
The worker only reads. It changes no file and opens no pull request.

To start it, select **Run discovery** in the Repository row.
Loupe creates a new tooling card in the Backlog, named "Discover what Acme needs for agents".
The card tracks the run, and Loupe moves it. Each run gets a new card.
The [`discovery` rule](workflows.md#rules-the-app-adds) then asks a bridge for the work.
An agent can start discovery with the `discovery_start` MCP tool, and read the checklist with `readiness_get`.

Discovery needs a running bridge that serves the project.
The project must also have a workflow, and **Run the workflow of the board** must be on, on the [Automation](board.md#automation) tab.
Otherwise the workflow cannot ask a bridge for the work, so Loupe refuses the start.
The bridge must set `appPrompts: true` in its `rules.yaml`, and its CLI must support app prompts.
See [Command-line bridge](../extending/cli-bridge.md#work-requests).

The Repository row shows the state of the latest run:

| Status | What it means | Action |
|---|---|---|
| Not run | Discovery never ran on this project | Run discovery |
| Discovery waits for a running bridge | Discovery never ran, and no bridge serves the project now | None. The Bridge running row links to the setup |
| Discovery waits for a workflow | Discovery never ran, and the project has no workflow | None. Choose a workflow for the project |
| Discovery waits for board automation | Discovery never ran, and the workflow of the board is off | None. Switch on **Run the workflow of the board** |
| Running on card #N | A run is open. A second start is refused until it ends | Open card |
| Discovery failed: reason | The run ended with no report. "No bridge took the work." means that no bridge claimed the request in time. "The discovery card left Backlog before the work ended." means that the work stopped because the card moved | Run again, when discovery can start |
| The report waits for your review | The worker reported | None |
| Reviewed | The owner reviewed the report. The row is done | None |
