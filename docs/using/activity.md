---
title: "Activity: Events"
description: "The Events tab of the Activity page, which lists the durable events of a project."
---

Open **Activity** in a project's sidebar, then the **Events** tab, to read its durable event history.
The **Runs** tab lists the [worker runs](worker-runs.md).
The [Analytics](analytics.md) page charts the metrics of the runs and compares the variants of each [experiment](experiments.md).

The topbar bell opens the latest 12 events without leaving the current page.
Closing the panel returns focus to the bell and keeps the page's unsaved fields.
Reopening reloads the recent events. **Open activity** goes to the full list.

## What a row shows

| Column | Meaning |
|---|---|
| Event | the event label in bold, then its subject in grey. A card move names the card and its two columns, such as `#292 Tech design → Implementation` |
| Delivery | whether Loupe delivered the event to the bridges: **Delivered**, **Pending delivery** or **Delivery failed** |
| Family | the family of the event, such as **Board** or **Pull requests** |
| Sequence | the order number that Loupe gave the event when it recorded it. Loupe delivers events in this order |
| Recorded | how long ago Loupe recorded the event. Hover over it to see the exact time |

Delivery status describes delivery to bridges, not the outcome of an agent's work.
A **Delivery failed** chip shows a help icon.
Hover over or focus the chip to see the number of failed attempts and the last error.

Click a row to open its linked work, such as the card of a card event.
Card events link to the current card when it still exists in this project.
Deleted cards leave their event records without a link. Disabling the board also hides card links.
A row with no linked work does not open anything.

The list reads newest first, 20 events to a page.

## Search and filters

The search box matches the event type and the recorded event data.
So a card number, a column slug or a word of the type, such as `moved`, finds its rows.
Each word must match.

The family filter keeps one family of events. **Documents** also keeps the older document review events.

Every control lands in the URL, so a filtered view is a link you can share.
Select **Clear** to go back to the whole list.
The count beside the filters shows how many events match.

## Live updates

The first page reloads when Loupe records an event in the project, or delivers one.
It also reloads after the page reconnects to the hub, for any change the page missed.
This needs a Mercure hub and the `live_updates.enabled` flag. Without them, the page shows a change on its next load.
A later page does not reload, so its rows stay in place while you read.
