---
title: "Analytics: Metrics"
description: "The Metrics tab of the Analytics page, which charts how the agents of a project perform over time."
---

The **Metrics** tab of the project's **Analytics** page charts one metric of the
agents over time. It reads the [worker runs](worker-runs.md) that a bridge
reports, and the cards that they finish. The **Experiments** tab compares the
variants of each [experiment](experiments.md).

Open **Analytics** in the project sidebar, or go to
`/projects/{project}/analytics/metrics`. Only the project owner can open it.

## The controls

A row of controls above the chart sets what the page shows. The page loads
again when you change a control. Without JavaScript, press **Apply**.

| Control | Values | Default |
|---|---|---|
| Metric | one metric from [the list below](#the-metrics) | **Cost** |
| Unit | **Per run** or **Per finished card** | **Per finished card** |
| Statistic | **Median**, **Mean**, **Sum**, **90th percentile** or **Count** | **Median** |
| Group | **By stage**, **By model**, **By variant**, **By card type**, **By bridge** or **No grouping** | **No grouping** |
| Range | **30 days**, **90 days** or **All time** | **90 days** |
| Bucket | **Per day**, **Per week** or **Per month** | **Per week** |

A control lists only the values that its metric allows. An unknown value in the
address takes the default. A value that the metric does not allow takes the
default when the metric allows the default. Otherwise it takes the first value
that the metric allows. For example, `metric=stop-rate&unit=card` shows the stop
rate per run.

## Run rows and card rows

The unit sets what one row of the metric stands for.

A run row is one closed run. Its time is the end of the run. A run with no end
takes the time when its first report arrived. **By stage** reads only the runs
of a card.

A card row is one finished card. A card is finished when it sits in a terminal
column, such as **Done**. Its time is the time when the card was completed. The
value of a card is the sum over its runs. A run that started and has no value
makes the value of its card unknown. A command run has no agent, so its missing
cost and tokens count as none.

With **By stage**, **By model**, **By variant** or **By bridge**, a card gives
one row for each group that its runs used. A card whose runs used two variants
gives two rows. Each row of a card outcome metric carries the whole outcome of
the card. Thus the card counts once for each variant.

The range keeps the rows whose time falls inside it. The metrics start with the
oldest run that the server held at the upgrade. A run that the
[retention](../reference/worker-runs.md#retention) sweep deleted before then is
not counted.

## The metrics

| Metric | What it measures | Units |
|---|---|---|
| **Cost** | the cost in US dollars. It is unknown when a started run has no price | run, card |
| **Input tokens** | the input tokens that the model read | run, card |
| **Output tokens** | the output tokens that the model wrote | run, card |
| **Cache read tokens** | the tokens that the model read from its prompt cache | run, card |
| **Cache write tokens** | the tokens that the model wrote to its prompt cache | run, card |
| **Duration** | the time from the start to the end of a run | run, card |
| **Runs** | the number of worker runs on a finished card | card |
| **Stop rate** | the share of runs that reached an outcome and blocked, failed, gave no result or gave up | run |
| **Merge rate** | the share of finished cards whose pull request merged | card |
| **Fix rounds** | the number of fix rounds that a finished card needed | card |
| **Hours to merge** | the hours from the first pull request opening to the last merge | card |

The dollars are the API list price that claude reports. On a subscription, you
do not pay this amount. A duration shows in milliseconds, seconds, minutes or
hours. **Hours to merge** shows in hours. A rate shows as a percentage.

**Stop rate** and **Merge rate** take only **Mean** and **Count**. **Merge
rate**, **Fix rounds** and **Hours to merge** take only **By variant**, **By
card type** and **No grouping**.

## Statistics, groups, ranges and buckets

| Statistic | Meaning |
|---|---|
| **Median** | the middle value |
| **Mean** | the average value |
| **Sum** | the total of the values |
| **90th percentile** | the value that 90% of the rows do not pass |
| **Count** | the number of rows with a known value |

A group splits the rows into one series for each value:

| Group | A series for each |
|---|---|
| **By stage** | work kind, such as `implement` or `fix` |
| **By model** | model |
| **By variant** | variant of an experiment |
| **By card type** | type of card |
| **By bridge** | bridge, by its name. A bridge with no name shows the end of its id |
| **No grouping** | one series for all the rows |

The rows with no value for the group form the series **No group**, which comes
last.

The range is **30 days**, **90 days** or **All time**, back from now. A bucket
starts at midnight UTC. A week starts on the ISO Monday, and a month starts on
the first day of the month.

## The summary, the chart and the table

The summary shows one figure for each series: the statistic over all the rows
of the series in the range. Below the figure, it shows the number of rows in
the series.

The chart shows one bar for each bucket of each series. The bars of the series
in a bucket stand side by side. The time axis shows only the buckets with rows.
A legend names the series when there is more than one. With more than eight
series, the first seven keep their colours, and the rest share one grey. The
value axis of a rate stops at 100%.

Point at a bar, or move to it with the Tab key, to see its bucket, its series,
its value and its number of rows with a known value.

The table below the chart lists the rows of each series, newest first. A series
shows its newest 100 rows, and a note says how many more it holds. A card row
opens the card when the board is on. A run row opens the
[Runs](worker-runs.md) tab, filtered to that run.

The table does not show the **Estimated** and **n runs have no usage** marks.
The [usage total](worker-runs.md#the-usage-total-of-a-card) of a card still
shows them.

## Unknown values

A row whose value is unknown shows **unknown** in the table. It stays out of
every statistic, and **Count** does not count it. A series with no known value
shows **unknown** as its figure. With **Count**, it shows 0.

## When there is nothing to show

When no row falls in the range, the page shows **No runs are recorded yet**. The
chart fills when a bridge reports worker runs. Connect a bridge on the Agents
page, or pick a longer range.

## Share a view

Each view of the page is an address. The controls go into the query string, and
a control at its default stays out of it. Copy the address to share or bookmark
a view.

The old address of the Cost tab, `/projects/{project}/worker-runs/cost`, opens
this page. It shows the mean cost per finished card. The range and the bucket of
the old address stay when the page knows them.

## Through the MCP

The MCP tool `metric_query` returns the same numbers as this page, and
`metric_list` lists the metrics. See [the MCP tools](mcp.md#what-the-tools-do).
Over the MCP, the group of a bridge is its id, and the page shows its name.
