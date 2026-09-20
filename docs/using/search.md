---
title: Search
description: "Find pages, cards, and documents in one project or all owned projects."
---

The topbar search is behind the `search.topbar.enabled` feature flag, and the flag ships off.
Switch it on at **`/admin/feature-flags`**. While it is off, the topbar shows no search and Cmd+K does nothing.

Open **Search project** in the topbar, or press Cmd+K on macOS or Ctrl+K elsewhere.
The search dialog keeps the current page open. On project pages, card and document results belong to the current project.
An empty query lists its available pages.

On Account and All projects, **Search all projects** searches every project you own.
Results include the project name. Projects owned by another account never appear.
Next advances the results within each owned project; the last page can contain results from fewer projects.

Type to search, or press **Search**. Tab to a result and press Enter to open it.
Escape closes the dialog and restores focus. Closing it preserves unsaved fields on the page beneath it.
If a request fails, press **Search** to retry. Without JavaScript, the topbar link opens a full search page.

Card and document searches match titles and text with the existing full-text indexes.
Enter a card number, such as `#12`, to find that card directly.
Results include completed cards and archived documents.
A disabled board contributes no cards, Board page, or Rules page.

Each results page contains up to ten cards and ten documents.
Use **Next results** or **Previous results** to read more matches.
Selecting a result opens the corresponding page, card, or document.
