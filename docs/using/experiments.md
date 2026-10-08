---
title: "Analytics: Experiments"
description: "The Experiments tab of the Analytics page, which compares the variants of each experiment a bridge runs."
---

An [experiment](../extending/cli-bridge.md#experiments) splits the cards of a
kind of work between models. The **Experiments** tab of the project's
**Analytics** page shows how each variant did.

Open **Analytics** in the project sidebar, then the **Experiments** tab.

## The list of experiments

The list shows one row for each experiment that a run or a pin names. A row
shows the name, the number of cards and the time of the last run. The latest
run comes first. Select a name to open the comparison of that experiment.

A project with no experiment shows how to start one. Give a worker entry of the
`work:` map in `rules.yaml` its variants. The kind of the entry names the
experiment.

## The comparison

The **Comparison** tab starts with a short answer. It names the cheaper of the
first two variants by cost per merged card, and the share it saves. It also
says whether the merge rate and the fix rounds give a clear answer.

The variants table shows, for each variant:

| Column | Meaning |
|---|---|
| Variant | the name of the variant |
| Model | the model that the latest run of the variant asked for |
| Weight | the weight of the variant, as the bridge last reported it |
| Cards | the cards of the variant that the figures use |
| Finished | the cards that merged or stand in a terminal column |
| Runs | the experiment runs of those cards |
| Cost | the cost of those runs |

The metrics table has one row per metric. Each variant has two columns: the
value, and the **Likely range** around it.

| Metric | Meaning |
|---|---|
| Merge rate | the merged cards over the finished cards |
| Stop rate | the runs that ended blocked, failed, with no result or gave up, over the runs that reached an outcome |
| Fix rounds per merged card | the fix requests of a merged card. A row for each reason follows, such as a conflict, failed checks or requested changes |
| Cost per merged card | the cost of the experiment runs of a merged card |
| Output tokens per merged card | the output tokens of the experiment runs of a merged card |
| Hours from open to merge | the time from the first pull request of a merged card to the last merge |

The cost and the output tokens use only the merged cards whose experiment runs
reported usage. A card with no usage is not a card that cost nothing, so it
stays out of these two samples. The same applies when one run of the card
started and reported no usage, because the sum of the others is too low. It still counts in the other metrics. A card
with a usage row for a model that has no price has an unknown cost, so it
stays out of the cost sample and the variant total.

A card counts as merged only when the workflow moved it because its pull
request merged. A card that a person moved to a terminal column is finished, but it
is not merged.

## The likely range

The likely range holds the true value 95 times in 100. A rate uses the Wilson
interval. A value per merged card uses a bootstrap of the mean. The bootstrap
has a fixed seed, so a page shows the same range on each load.

A metric compares the first two variants by name. It gives a clear answer when
each of the two variants has at least 5 cards behind its value, and one of
these is true:

- The two ranges do not overlap.
- The two values are within 10% of each other.

A rate counts the finished cards. A value per merged card counts only the
merged cards that have the value, such as a cost or the two times of a merge.

A metric with no clear answer shows a **Too few cards** chip. An experiment
with one variant never gives a clear answer.

Press **Analyse this experiment** below the metrics to ask an agent to explain
the comparison. The link opens the [Reports](analytics.md#reports) tab, with
the topic **Experiment** and this experiment already chosen.

## Cards that are left out

The figures leave out a card that does not give a fair comparison. The line
below the metrics shows how many cards are left out, and links to them.

| Reason | When |
|---|---|
| Switched variant | a run of the card moved it from a variant that the rule no longer offered |
| Mixed variants | the runs and the pin of the card name more than one variant |
| Implemented before the test | a worker run with no experiment worked the card in the same column before the first experiment run |
| Run before the card history | the first experiment run of the card is older than the card history of the project |
| No experiment run | a pin puts the card in the experiment, and no experiment run worked the card |

## The Cards tab

The **Cards** tab lists the cards of the experiment, 20 to a page, the latest
run first. A row shows the card, the variant, the column, the runs, the fix
rounds, the cost, and a tag for each reason that leaves the card out. The cost
shows a dash when no experiment run of the card reported usage, or when the
cost is unknown. Select a card to open it on the board.

The filters above the list show all the cards, the kept cards of one variant,
or the cards that are left out. Each filter lands in the URL, so a filtered
view is a link you can share.
