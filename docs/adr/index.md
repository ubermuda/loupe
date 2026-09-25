---
title: "Architecture decision records"
description: "Why the code is the way it is, one decision per record."
---

An architecture decision record (ADR) keeps one decision, the options we
weighed, and what the decision costs. Read the record before you change the
code it covers. When the reasons no longer hold, write a new record that
supersedes the old one. Do not edit the decision of an accepted record.

## Format

Name the file `NNNN-short-title.md`, with the next free number. Give it these
sections:

- Status: proposed, accepted, or superseded by a later record.
- Context: the problem, and the facts that force a decision.
- Options: each option we weighed, and what it costs.
- Decision: the option we took.
- Consequences: what gets easier, what gets harder, and what nothing enforces.
