---
name: loupe-analysis
description: "Use when a work request asks for an analysis of a Loupe project, when a prompt names loupe-analysis or gives an analysis id, or when calling analysis_get or analysis_report."
---

# Running a Loupe analysis

An analysis reads the worker runs of one project and ends with a report document and a short list of proposals. The owner starts it on the Reports tab of the Analytics page. A bridge then runs you with the analysis id. The owner reads the report and creates or dismisses each proposal.

Change no code and no card. Read through the `loupe` MCP, write one document, and report.

## Procedure

1. Call `analysis_get` with the analysis id. Read `topic`, `scope.range` and `state`.
2. When `state` is `done` or `failed`, stop. The analysis takes no second report.
3. When `topic` is not `cost`, do the steps in [Another topic](#another-topic), and stop.
4. Do the steps in [Find where the cost goes](#find-where-the-cost-goes).
5. Load the `loupe-documents` skill, then write the report as [The report](#the-report) says.
6. Call `analysis_report` with `analysisId`, the `documentId` from `document_create`, and your proposals.
7. Read the response. Its `state` must be `done`.

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

## The report

1. Call `document_create` with the tag `analysis`, and a title such as `Cost report: last 90 days`.
2. Open with a summary of three to five lines: the total cost, the main cost drivers, and the best proposal.
3. Cite the numbers for each claim. Link the runs and the cards you read.
4. Give the sample size of each claim, and say how sure you are: high, medium or low. A claim from fewer than ten rows is low.
5. Keep advice concrete. Name the work kind, the model, the step or the command to change.
6. Say what the data cannot show, such as runs with an unknown cost.

## Proposals

1. Propose at most five changes. An empty list is valid when the data supports no change.
2. Use kind `card` for each one.
3. Write a `title` of one line that says the change.
4. In the `body`, cite the run ids and card numbers that show the problem, and the outcome data from step 4 of the cost procedure.
5. Give `estimatedSaving` as a short text, such as `about $4 a week`. Base it on the numbers in the body.

## Another topic

1. Call `document_create` with the tag `analysis`. Say in a few lines that this skill does not support the topic yet.
2. Call `analysis_report` with the `documentId` and an empty `proposals` list.
