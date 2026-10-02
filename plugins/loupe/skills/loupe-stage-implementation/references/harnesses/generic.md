# Generic agent harness adapter

Use this adapter when the current harness has no dedicated adapter. Map each operation to the equivalent capability in the harness.

## Connect to the Loupe tools

Discover or load the Loupe MCP tools. Retry briefly when the server is still connecting. The connection failed when the tools remain unavailable.

## Load an instruction

Use the harness's skill loader when it has one. Otherwise, read the named `SKILL.md` under `.agents/skills/` in full before you act.

## Ask nothing

The stage runs unattended. Do not use an interactive question tool.

## Change a file

Use a file-editing tool that targets the worker folder. Confirm the target before the first write.

## Run a long command

Use the harness's background process support and poll it in the foreground at least once a minute. Never end the turn while it runs. Preserve the complete log and the exit status. Stop when the command fails.

## Dispatch a sub-agent

When the harness supports sub-agents, dispatch one with the `senior-dev` instruction and repeat every stage rule in its prompt. Otherwise, perform the task in the worker folder and apply the same instruction.

## Write and run a plan

Write a task-by-task plan, submit it to Loupe, and execute it in order. Do not save a second plan file, merge into the base branch, or remove the worker folder.

## Final reply

Keep the final reply below 4 KB so every bridge can retain it.
