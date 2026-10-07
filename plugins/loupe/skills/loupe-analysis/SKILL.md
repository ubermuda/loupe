---
name: loupe-analysis
description: "Use when a work request asks for an analysis of a Loupe project, such as a cost report or a comparison of the variants of an experiment, when a prompt names loupe-analysis or gives an analysis id, or when calling analysis_get, analysis_report or experiment_get."
---

# Running a Loupe analysis

An analysis reads the worker runs of one project, or the comparison of one experiment, and ends with a report document and a short list of proposals. The owner starts it on the Reports tab of the Analytics page. A bridge then runs you with the analysis id. The owner reads the report and creates or dismisses each proposal.

Change no code and no card. Read through the `loupe` MCP, write one document, and report.

## Procedure

1. Call `analysis_get` with the analysis id. Read `topic`, `scope.range`, `scope.experiment` and `state`.
2. When `state` is `done` or `failed`, stop. The analysis takes no second report.
3. When `topic` is `cost`, do the steps in [Find where the cost goes](#find-where-the-cost-goes).
4. When `topic` is `experiment`, do the steps in [Compare the variants of an experiment](#compare-the-variants-of-an-experiment).
5. For any other topic, do the steps in [Another topic](#another-topic), and stop.
6. Load the `loupe-documents` skill, then write the report as [The report](#the-report) says.
7. Call `analysis_report` with `analysisId`, the `documentId` from `document_create`, and your proposals.
8. Read the response. Its `state` must be `done`.

## Find where the cost goes

Pass `scope.range` as the `range` of each `metric_query`. Call `metric_list` first, and use only the units, statistics and groups that it gives.

1. Query `cost` with unit `run`, statistic `sum`, and group `stage`. Do it again with group `model`, then with no group. Note the `total` of each series and its `rows`.
2. Query `cost` with unit `card`, statistic `median` and `sum`, and group `card-type`.
3. Query `cost` with bucket `week` and no group, to see the trend.
4. Query `merge-rate` and `fix-rounds` with unit `card`, with the same groups as step 2.
5. Take the costliest series. Sort its `rows` by `value` yourself, because `rows` holds the newest 100 rows and no sort by value.
6. Call `worker_run_get` on the costliest runs, about five to ten. When the rows are not enough, call `worker_run_list` with `workKind` and read `metrics.costUsd`.
7. For each run, find why it cost much. Use the signs below.
8. Join cost to outcome. A cheap run on a card that did not merge, or that needed many fix rounds, is no saving.

| Sign | Where to read it |
|---|---|
| Many resumes | `runs` of `worker_run_get` holds the whole series |
| Long polling or waiting | `metrics.idleGapMs` against `metrics.durationMs` |
| Big subagent work | `metrics.subagentMs` |
| Repeated gate runs | `worker_run_tool_calls`, the same `signatures` many times, such as `just phpunit` |
| A large model on easy work | `model` and `usage` against the work kind |

## Compare the variants of an experiment

`scope.experiment` names the experiment. The comparison reads every card of the experiment, so `scope.range` is `all`. An experiment runs one kind of work with two or more variants, such as two models, and compares the cards each variant worked.

### Read the comparison

1. Call `experiment_get` with `experiment` set to `scope.experiment`.
2. Call it again with `page` 2, 3 and on while `hasMore` is true. Keep every row of `cards`.
3. Read `variants`: the model, the weight, `cards`, `finishedCards`, `runs` and `costUsd` of each.
4. Read `metrics`. For each metric, note the range of each variant in `byVariant` and its `clear` flag. Read the `parts` of `fix-rounds` too.
5. Read `headline` and `minFinishedCards`.
6. Note `unknownMetrics`. The rule declares these keys, and the server has no such metric. Say so in the report.

### Check the sample

1. Compare `finishedCards` of each variant with `minFinishedCards`.
2. When a variant has fewer finished cards than the minimum, say that the sample is too small to call a result.
3. Say the same when no metric is clear.
4. In both cases, recommend more runs. Name how many more finished cards each variant needs to reach `minFinishedCards`.
5. Keep reading. The confounders still help the next analysis.

### Find the confounders

A confounder is a cause other than the variant that changes a figure.

1. Read the left-out cards, the rows of `cards` with a `leftOut` list. Count them by reason, and say what each reason removes from the figures.
2. In each variant, take the kept cards with the highest `costUsd`, about three to five. Take the cards with the most `runs` as well.
3. Call `worker_run_list` with the `cardNumber` of each card. Call `worker_run_get` on its costliest or longest runs.
4. Look for the signs in the table below. Name each confounder and the cards it touches.
5. Compare the card mix of the variants. Call `card_get` on a few cards of each variant, and compare their types and sizes. A variant that got the harder cards looks worse for that reason alone.

| Sign | Where to read it |
|---|---|
| A usage limit or a stop | `reason` and `state` of the run, such as `stopped` |
| Long waits | `metrics.idleGapMs` against `metrics.durationMs` |
| Resumes | `runs` of `worker_run_get` holds the whole series |
| Failed tool calls | `metrics.failedCalls` |
| Slow repeated tool calls | `worker_run_tool_calls`, the same `signatures` many times with a long `durationMs` |
| A shared machine under load | runs on one `bridgeId` that overlap in time |
| A power loss or a lost bridge | a run that ends `lost` |

### Recommend

1. Recommend one variant, or more runs. Give the reasons.
2. Join cost with quality. A cheaper variant with a lower merge rate, or with more fix rounds, is no clear win.
3. Give the sample size of each claim, and say how sure you are: high, medium or low.
4. Say which confounders weaken the result, and how much.

### Proposals for an experiment

1. Propose at most three changes. Use kind `card` for each one.
2. Propose a change such as "Keep variant X for implement, and remove the experiment", or "Run 12 more cards before a decision".
3. Base `estimatedSaving` on the numbers, such as the cost per merged card of each variant and the cards a week.
4. In the `body`, cite the metric ranges, the sample sizes and the cards that show the result.

## The report

1. Call `document_create` with the tag `analysis`. Use a title such as `Cost report: last 90 days`, or `Experiment report: implement`.
2. Open with a summary of three to five lines. For cost, give the total cost, the main cost drivers, and the best proposal. For an experiment, give the recommended variant or the runs still needed, and the main confounder.
3. Cite the numbers for each claim. Link the runs and the cards you read.
4. Give the sample size of each claim, and say how sure you are: high, medium or low. A claim from fewer than ten rows is low.
5. Keep advice concrete. Name the work kind, the model, the step or the command to change.
6. Say what the data cannot show, such as runs with an unknown cost.

## Proposals

1. Propose at most five changes. An empty list is valid when the data supports no change.
2. Use kind `card` for each one. An experiment takes at most three, as [Proposals for an experiment](#proposals-for-an-experiment) says.
3. Write a `title` of one line that says the change.
4. In the `body`, cite the run ids and card numbers that show the problem, and the outcome data from step 4 of the cost procedure.
5. Give `estimatedSaving` as a short text, such as `about $4 a week`. Base it on the numbers in the body.

## Another topic

1. Call `document_create` with the tag `analysis`. Say in a few lines that this skill does not support the topic yet.
2. Call `analysis_report` with the `documentId` and an empty `proposals` list.
