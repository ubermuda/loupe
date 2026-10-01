---
title: "0002: Keep the stage skills generic, and let bridge rules set up the environment"
description: "A stage skill works in the directory it starts in. The bridge rules of each user create, refresh and remove that directory."
---

## Status

Accepted on 2026-09-30.

## Context

The stage skills ship in the Loupe plugin. Every repository that uses Loupe runs the same skills. Each repository keeps its own values in `.loupe/lifecycle.md`, such as its gate commands and its pull request format.

Until card 405, the skills also set up the place where they work. The implementation skill creates a git worktree from the main checkout, runs the provisioning command of the profile, and binds its writes to the new tree. The fix round skill sets up or refreshes that tree from the pull request branch. The profile's "Worktree" section feeds these steps. In this repository it names `.worktrees/card-<number>`, `just worktree-up` and `just worktree-down`.

This fails in two ways:

- Other teams set up their environment in other ways. Some use a worktree with other commands, some use a container or a remote machine, and some use no isolation at all. A skill that runs `git worktree add` assumes a shape that the profile can only fill in, not replace.
- Nothing tears the environment down. A skill ends before its card does, so no stage owns the removal. On 2026-09-29, 23 worktrees in this repository belonged to cards whose pull request had merged. Each one still ran its sidecars and held two databases.

Card 244 lets a bridge rule run a `before` command that prepares the worker's directory, and an `action: command` that runs a script with no agent. The setup prompt of epic 269 writes those rules for a new user.

## Decision

A stage skill works in the directory it starts in. It never creates, refreshes or removes that directory or the services that belong to it. The bridge rules of each user do that work, and the setup prompt writes them.

The rule in detail:

- The environment belongs to the rules. A `before` command creates or reuses the directory, and a command rule on a terminal column removes it. A user with no such rule gets a worker in the project directory.
- The work belongs to the skill. A skill can still create a branch, commit, run the gate and open a pull request in its directory. Those steps are the stage's work, not its environment.
- The profile keeps values for the work. `.loupe/lifecycle.md` keeps its gate, review, changelog, pull request, board and merge values. It holds no environment steps that a skill reads.
- No skill names a repository's setup command. A skill that needs a working app assumes that its directory already has one.

Apply this rule when you write or change a stage skill. When you find a setup or teardown step in a skill, move it to a rule, or raise a card to move it.

## Rejected options

- Keep the setup in the skill, with the profile's values: the profile can rename the commands, but the skill still decides that a worktree exists and when it is made. A team with another shape must fight the skill.
- Let the skill use a tree when it starts in one, and make one otherwise: no repository breaks when the plugin updates. But the skill keeps both paths, and the setup it was meant to lose stays in it.
- Put the setup in bridge hooks: hooks only watch the bridge, get no card data, and cannot change the worker's directory. Card 400 moves the hooks into rules.

## Consequences

Better:

- One plugin fits every repository. A team writes its own rules and changes no skill.
- Setup and teardown run as fixed commands, with no agent and no tokens. This follows ADR 0001.
- A failed setup stops the run before an agent starts, so it costs no worker time.
- A rule on a terminal column removes the environment, so trees no longer pile up after a merge.

Worse:

- Isolation now depends on a bridge. A person who runs a stage skill by hand, with no bridge, gets no tree unless they make one.
- Each user must write the rules. The setup prompt carries that work, and a wrong answer there gives the user a worker with no isolation.
- A skill cannot repair its own environment. When a migration or a service is missing, the skill stops and reports it. Before, it could run the provisioning command again.

Watch for a step inside a run that the environment needs, such as a refresh after the skill merges the base branch. That step has no rule yet. Keep it out of the skill, and give it a home in the rules when the need shows.
