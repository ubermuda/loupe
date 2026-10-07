---
title: Workshop
description: "See open requests, cards, and recent project activity."
---

Open a project to see its Workshop. The summary counts belong to that project.
It shows open requests, open cards, and completed cards.
Cards in terminal columns count as completed, regardless of the column's name.
A disabled inbox shows an explanation instead of a zero count.

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
