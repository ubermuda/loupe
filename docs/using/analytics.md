---
title: "Analytics: Metrics and Reports"
description: "The Metrics tab of the Analytics page, which charts how the agents of a project perform over time, the Time buckets tab, and the Reports tab, where an agent explains the cost and the time."
---

The **Metrics** tab of the project's **Analytics** page charts one metric of the
agents over time. It reads the [worker runs](worker-runs.md) that a bridge
reports, and the cards that they finish. The **Experiments** tab compares the
variants of each [experiment](experiments.md). On the [Reports](#reports) tab,
an agent analyses the runs and proposes changes.

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
hours. **Hours to merge** shows in hours. A rate shows as a percentage. With
**Count**, the summary and the chart show a number of rows for every metric.
The table still shows the value of each row in the type of its metric.

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
value axis of a rate stops at 100%, except with **Count**.

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

Each view of the page has an address that you can share. The form puts every
control into the query string. Copy the address to share or bookmark a view. An
address that leaves out a control shows that control at its default.

The old address of the Cost tab, `/projects/{project}/worker-runs/cost`, opens
this page. It shows the mean cost per finished card. The range and the bucket of
the old address stay when the page knows them.

## Through the MCP

The MCP tool `metric_query` returns the same numbers as this page, and
`metric_list` lists the metrics. See [the MCP tools](mcp.md#what-the-tools-do).
Over the MCP, the group of a bridge is its id, and the page shows its name.

## Reports

The **Reports** tab lists the analyses of the project, newest first. An
analysis is a piece of agent work about the project rather than about a card.
The agent reads the worker runs, writes a report document, and proposes
changes. Go to `/projects/{project}/analytics/reports`. Only the project owner
can open it.

An analysis needs a bridge with a work entry for the `analysis` subject.
[Work requests](../extending/cli-bridge.md#work-requests) shows the entry. The
`loupe-analysis` skill of the Loupe plugin does the work.

### Start an analysis

Fill the **Analyse** form, and press **Analyse**.

| Field | Values |
|---|---|
| Topic | **Cost**, where the cost of the workers goes, or **Time**, where the time of the workers goes |
| Range | **30 days**, **90 days** or **All time**: the runs the agent reads |
| Model | the model of the agent, such as `sonnet` or `opus` |
| Effort | **Low**, **Medium**, **High**, **Extra high** or **Maximum** |

An empty model or effort takes the project default. The analysis opens a work
request, and a bridge that reports the `subject-analysis` capability claims it.
The request model and effort replace the model of the work entry.

A **Time** analysis reads the bucket times of the runs, the slowest tool calls
and the idle gaps. It names repeated steps and polling loops, and it sets apart
a slow call that is no fault of the worker. It proposes a card for each fix. It
also proposes a rule for each slow call that no [time bucket](#time-buckets)
takes yet.

### States and reasons

Each analysis shows its state, its model and effort, its cost so far, and the
time it started.

| State | Meaning |
|---|---|
| **Waiting for a bridge** | No bridge has claimed the work yet |
| **Running** | A bridge claimed the work, and the agent runs |
| **Done** | The agent sent its report |
| **Failed** | The work ended with no report. The reason follows the state |
| **Paused** | No bridge took the work before the work timeout. Nothing runs it again |

A failed analysis shows one of these reasons:

- `request-refused`: the server refused the work request.
- `request-failed`: an error stopped the server before it opened the work request.
- `no-report`: the work ended, and the agent sent no report.
- The reason of the bridge, or `refused`, when the bridge refused the work.

A paused analysis shows `no-bridge-took-work`. A failed or paused analysis is
final. To try again, start a new analysis. The cost is the sum of the runs
of the analysis. It shows **unknown** when no run has a known cost.

### The report and the proposals

A done analysis links its report document. The `loupe-analysis` skill tags it
`analysis`. Open it to read and comment, as on any document.

Below the analysis, each proposal shows its title, its detail and its
estimated saving. A proposal is **Proposed** until you act on it.

- Press **Create the card** to put a feature card in the backlog of the board.
  The proposal then reads **Card created**, with a link to the card.
- Press **Dismiss** to close the proposal. Type an optional reason first.

A proposal of the kind `bucket-rule` shows its pattern and its bucket. Press
**Create the rule** on it to add the rule to the [time buckets](#time-buckets)
of the project. The proposal then reads **Rule created**. Loupe
refuses a proposal whose pattern or bucket is not valid, and a proposal for a
project that already holds 50 rules. Dismiss such a proposal.

### Analysis settings

Open **Analysis settings** to set the defaults of the project.

| Setting | Meaning |
|---|---|
| Default model | the model of a new analysis that names none |
| Default effort | the effort of a new analysis that names none |
| Programs with subcommands | a comma list, such as `git, npm`. For these programs the second word joins the signature of a shell command |
| Collect the full text of each tool call | the bridge sends the full text of each tool call, so an analysis can read it |

An empty default model or effort takes the instance default. The
`insights.default_analysis_model` feature flag holds the instance model, and
its default is `sonnet`. The `insights.default_analysis_effort` feature flag
holds the instance effort, and its default is `medium`. Change them at **`/admin/feature-flags`**.
The full text switch is off by default.

A blank list of programs takes the instance list. The
`insights.subcommand_programs` feature flag holds it. A program name has 1 to
40 characters of letters, digits and `.`, `_`, `+` and `-`. A list holds at
most 50 names.

### Time buckets

The **Time buckets** tab splits the tool time of each worker run into buckets.
A rule has a pattern and a bucket. The pattern is a glob that matches the
signature of a tool call, such as `git *` or `grep`. A star matches any run of
characters and a question mark matches one. A bucket name has 1 to 64
characters of lower case letters, digits, `_` and `-`.

The first rule that matches a call takes it. A call that no rule takes counts
in the bucket `other`. Use **Move up** and **Move down** to change the order.
A project holds at most 50 rules. After each change, Loupe computes the bucket
times of the runs of the project again, in the background.

The MCP tools `analysis_get` and `analysis_report` let the agent read and
finish an analysis. `analytics_settings_get` and `analytics_settings_update`
read and change the settings. See [the MCP tools](mcp.md#what-the-tools-do).
