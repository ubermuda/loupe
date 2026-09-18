---
title: "Project activity"
description: "Read durable project events and pause their presentation without stopping work."
---

Open **Activity** in a project's sidebar to read its durable event history.
Delivery status describes delivery to bridges, not the outcome of an agent's work.

The topbar bell opens the latest 12 events without leaving the current page.
Closing the panel returns focus to the bell and keeps the page's unsaved fields.
Reopening reloads the recent events. **Open activity** goes to the full feed.

The page refreshes every ten seconds while it remains open.
**Pause feed** freezes the displayed rows. Recording and agent work continue.
**Resume feed** immediately reads the latest events and reconciles them with the displayed history.
Repeated reads do not duplicate events. Filters, keyboard focus, and the reading position remain in place.

Search matches the text of each row. The event-family filter includes document review events under **Documents**.
The count beside the filters shows visible rows against loaded rows.
Card events link to the current card when it still exists in this project.
Deleted cards leave their event records without a link. Disabling the board also hides card links.

Each refresh reads the latest 100 events. Earlier rows remain visible during the current visit.
If no event overlaps the previous view, the page warns that some intervening events may be missing.
Reloading starts with the latest 100 events again.

A failed refresh retains the displayed history and reports delayed or unavailable updates.
The page retries automatically. An empty successful response remains distinct from failed loading.
Without JavaScript, the page shows recorded history and does not offer active pause controls.
