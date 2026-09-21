# Generic agent harness adapter

Use this adapter when the current harness has no dedicated adapter. Map each operation to the equivalent capability in the harness.

## Connect to the Loupe tools

Discover or load the Loupe MCP tools. Retry briefly when the server is still connecting. The connection failed when the tools remain unavailable.

## Load an instruction

Use the harness's skill loader when it has one. Otherwise, read the named `SKILL.md` under `.agents/skills/` in full before you act.

## Ask nothing

The stage runs unattended. Do not use an interactive question tool.

## Change a file

Use a file-editing tool that targets the active worktree. Confirm the target before the first write.

## Bind writes to the worktree

Use the harness's worktree or working-directory control. If it has neither, start a worker whose working directory is the worktree. Then run:

```bash
pwd
git worktree list --porcelain | grep -qx "worktree $(pwd)" && git branch --show-current
```

The first command must print the worktree path. The second must print the card branch.

## Run a long command

Use the harness's background process support and poll it at least once a minute. Preserve the complete log and the exit status. Stop when the command fails.

## Dispatch a sub-agent

When the harness supports sub-agents, dispatch one with the `senior-dev` instruction and repeat every stage rule in its prompt. Otherwise, perform the task in the current worktree and apply the same instruction.

## Write and run a plan

Write a task-by-task plan, submit it to Loupe, and execute it in order. Do not save a second plan file, merge into the base branch, or remove the worktree.

## Final reply

Keep the final reply below 4 KB so every bridge can retain it.
