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
3. When `topic` is neither `cost` nor `host`, do the steps in [Another topic](#another-topic), and stop.
4. When `topic` is `cost`, do the steps in [Find where the cost goes](#find-where-the-cost-goes). When `topic` is `host`, do the steps in [Find what the host did](#find-what-the-host-did).
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

## Find what the host did

The host metrics come from samples that a bridge takes of its machine. Read them in `metrics` of `worker_run_list` and `worker_run_get`: `meanCpuPct`, `peakMemBytes`, `peakSwapBytes`, `concurrentRuns` and `onBattery`. `concurrentRuns` counts the runs on the same bridge whose times overlap, the run itself included. Loupe computes it from the start and end times of the runs, so an ended run has a value with no sample. The other four metrics come from samples. A null value in them means that no sample is known.

1. Call `worker_run_list` with `endedAfter` set to the start of `scope.range`, such as 30 or 90 days before today. Pass no `endedAfter` for `all`. Walk every page while `hasMore` is true, with `perPage` 100.
2. Count the runs whose `meanCpuPct`, `peakMemBytes`, `peakSwapBytes` and `onBattery` are all null. These runs have no sample. Say in the report that sampling can be off. The instance flag `bridge.host_sampling_enabled` is off by default, and the `collect` key in the bridge `rules.yaml` can be `false`. A run that is shorter than the sample interval also has no sample.
3. When no run has a sample, skip steps 5 to 7. Do step 4, because `concurrentRuns` stays available with no sample. The report then says that no sample is known, and gives only the concurrency findings.
4. For each `workKind` and each `bridgeId`, compare runs with `concurrentRuns` 1 against runs with a higher count. Compare `metrics.durationMs`, and the share of runs whose `state` is `failed`, `timed-out` or `lost`.
5. Find the runs with `onBattery` true. Also find the runs that ended `stopped`, `failed` or `lost` on a bridge that was on battery near that time.
6. Call `bridge_host_samples` with the `runId` of each of those runs. A falling `batteryPct` with `onAc` false shows a drain. A gap in `sampledAt` before the end of the run shows that the machine slept or lost power.
7. Find the runs with a high `meanCpuPct`, and the runs whose `peakMemBytes` is near `memTotal` of their samples. A `peakSwapBytes` above zero shows memory pressure. Group them by `workKind` and `bridgeId`.
8. Find a change for each problem. Examples are a smaller worker pool on a bridge, a laptop bridge that stays on AC power, or a heavy work kind that moves to another bridge.

| Sign | Where to read it |
|---|---|
| Too many runs at one time | `metrics.concurrentRuns` against `metrics.durationMs` and `state` |
| A machine on battery | `metrics.onBattery`, then `batteryPct` and `onAc` of `bridge_host_samples` |
| A machine that slept or lost power | a gap in `sampledAt` of `bridge_host_samples` before the run ended |
| CPU pressure | `metrics.meanCpuPct`, and `cpuPct` per core of `bridge_host_samples` |
| Memory pressure | `metrics.peakMemBytes` against `memTotal`, and `metrics.peakSwapBytes` |

## The report

1. Call `document_create` with the tag `analysis`, and a title such as `Cost report: last 90 days` or `Host report: last 30 days`.
2. Open with a summary of three to five lines: the main findings and the best proposal. A cost report gives the total cost and the main cost drivers.
3. Cite the numbers for each claim. Link the runs and the cards you read.
4. Give the sample size of each claim, and say how sure you are: high, medium or low. A claim from fewer than ten rows is low.
5. Keep advice concrete. Name the work kind, the model, the step or the command to change.
6. Say what the data cannot show, such as runs with an unknown cost or with no host samples.

## Proposals

1. Propose at most five changes. An empty list is valid when the data supports no change.
2. Use kind `card` for each one.
3. Write a `title` of one line that says the change.
4. In the `body`, cite the run ids and card numbers that show the problem. Add the outcome data: merge rate and fix rounds for cost, or duration and failures for host.
5. Give `estimatedSaving` as a short text, such as `about $4 a week` or `about 2 failed runs a week`. Base it on the numbers in the body.

## Another topic

1. Call `document_create` with the tag `analysis`. Say in a few lines that this skill does not support the topic yet.
2. Call `analysis_report` with the `documentId` and an empty `proposals` list.
