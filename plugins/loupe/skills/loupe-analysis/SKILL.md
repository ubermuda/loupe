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
3. When `topic` is `cost`, do the steps in [Find where the cost goes](#find-where-the-cost-goes).
4. When `topic` is `time`, do the steps in [Find where the time goes](#find-where-the-time-goes).
5. For any other topic (`host`, `experiment` or `question`), do the steps in [Another topic](#another-topic), and stop.
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

## Find where the time goes

Time here is the time of the tool calls of the main session, and the time the worker spends thinking. Assume nothing about the stack of the project. Read the command signatures as they are.

Pass `scope.range` as the `range` of each `metric_query`. Call `metric_list` first, and use only the units, statistics and groups that it gives.

1. In `metric_list`, find the `bucket-time:<name>` entries. Each one is a bucket that has time on a run of the project. The bucket `other` holds the calls that no rule takes. `analytics_settings_get` does not list the rules, so infer the buckets from `metric_list`.
2. Query `duration` with unit `run`, statistic `median`, `p90` and `sum`, and group `stage`. Note the `total` of each series and its `rows`.
3. Query `duration` again with unit `run`, statistic `sum` and no group. A stage group leaves out the runs that belong to no card, and a bucket query counts them. Query each `bucket-time:<name>` with unit `run` and statistic `sum`, and compare each sum with this ungrouped `duration` sum. A bucket with a large share is a cost of time. A run with no bucket data has an unknown value, so note how many rows each series holds.
4. Call `worker_run_list` with `workKind` for the stage with the most time. Read `metrics`: `durationMs`, `toolTimeMs`, `modelTimeMs`, `idleGapMs`, `longestCallMs` and `toolCalls`. Sort by the value you study, because the list is newest first.
5. Call `worker_run_get` and `worker_run_tool_calls` on the longest runs, about five to ten. Page through each run while `hasMore` is true.
6. Find the slowest calls: sort the calls by `durationMs`. Read their `signatures`.
7. Find repeated steps: count the same `signatures` in one run, and across runs. The same step many times is a candidate for a cache, a smaller scope or a skip.
8. Find polling loops: a run of calls with signatures such as `for`, `grep` or `sleep`, and `waitsOn` null, that repeat in a short time. A call with `waitsOn` set reads a background task that it started earlier, so the wait is a known step. A loop with `waitsOn` null polls with no link to its task, so its time is pure waiting.
9. Separate the outliers that are not the fault of the worker. Name each one in the report, and leave it out of the proposals:
   - a call far longer than the other calls of its run, with no sign of work in it;
   - a gap that ends the run, such as an `idleGapMs` before the last call;
   - a machine that lost power or network, shown by a long gap with no call.
10. When the bridge has host data, compare the time of a run with the concurrency at that time. Slow runs that overlap many other runs point at the host. Do not require this data, and say so when it is missing.
11. When one signature hides many different steps, such as `git` for all subcommands, say so. The list of subcommand programs groups the second word of a command into the signature. Propose a change to `subcommandPrograms` as a card, and name the programs. The owner sets it with `analytics_settings_update`. Do not call that tool yourself, because an analysis changes nothing.
12. Find the signatures in `other` that take real time. Compare the sum of `bucket-time:other` with the `duration` sum. A signature that repeats in `other` and takes more than a few percent of the run time needs a rule.

### Proposals for time

1. Propose a rule of kind `bucket-rule` for a signature that falls in `other` and takes real time. Give `payload` as `{"pattern": "<glob>", "bucket": "<name>"}`. The pattern is a glob over a call signature, such as `git *`, `grep` or `Agent`: `*` matches any run of characters and `?` matches one. The pattern is at most 120 characters. The bucket name matches `a-z`, `0-9`, `_` and `-`, at most 64 characters. Prefer a bucket name that already exists.
2. Propose a `card` for each fix, such as a cache, a faster step, or a polling loop that waits on the task. Cite the runs and the calls that show the time.
3. Give `estimatedSaving` as a short text in time, such as `about 3 minutes a run`, based on the numbers in the body.
4. State the sample size and your confidence in the report, as [The report](#the-report) says. A claim from fewer than ten runs is low.

## The report

1. Call `document_create` with the tag `analysis`, and a title such as `Cost report: last 90 days` or `Time report: last 30 days`.
2. Open with a summary of three to five lines: the total cost or time, the main drivers, and the best proposal.
3. Cite the numbers for each claim. Link the runs and the cards you read.
4. Give the sample size of each claim, and say how sure you are: high, medium or low. A claim from fewer than ten rows is low.
5. Keep advice concrete. Name the work kind, the model, the step or the command to change.
6. Say what the data cannot show, such as runs with an unknown cost.

## Proposals

1. Propose at most five changes. An empty list is valid when the data supports no change.
2. Use kind `card` for each one. A `time` analysis also uses kind `bucket-rule`, as in [Proposals for time](#proposals-for-time).
3. Write a `title` of one line that says the change.
4. In the `body`, cite the run ids and card numbers that show the problem, and the outcome data from step 4 of the cost procedure.
5. Give `estimatedSaving` as a short text, such as `about $4 a week`. Base it on the numbers in the body.

## Another topic

The topics `host`, `experiment` and `question` have no procedure yet.

1. Call `document_create` with the tag `analysis`. Say in a few lines that this skill does not support the topic yet.
2. Call `analysis_report` with the `documentId` and an empty `proposals` list.
