# Claude Code adapter

The stage skills read this file when they run in Claude Code, including an unattended `claude -p` worker. It maps each harness step to the tools of Claude Code.

## Connect to the Loupe tools

Search for the Loupe tools with ToolSearch, and load `EnterWorktree` in the same search. In `claude -p` the Loupe MCP server can still be connecting at the first search, so search again, up to six times. When every search fails, the connection failed.

## Load an instruction

Load a named instruction, such as `loupe-board`, with the Skill tool. Read a file of instructions with the Read tool.

## Ask nothing

Never call `AskUserQuestion`. Nobody answers it in an unattended run.

## Change a file

Change a file with the Edit and Write tools. Never use the Serena edit tools, because they write to the main checkout.

## Bind writes to the worktree

Call `EnterWorktree` with the absolute path of the card worktree. Then check both of these:

```bash
pwd
git worktree list --porcelain | grep -qx "worktree $(pwd)" && git branch --show-current
```

The first must print the worktree path. The second must print the card branch. When either check fails, the binding failed. A sub-agent that the bound session dispatches writes into the same worktree.

## Run a long command

A Bash call ends after 600000 ms. Start the long command with the Bash tool's `run_in_background`. Write its output to a log file, and append an exit marker when it ends:

```bash
<command> > <log> 2>&1; echo "EXIT=$?" >> <log>
```

Then wait in the foreground with this loop, and a Bash timeout of 600000:

```bash
for i in $(seq 1 57); do grep -q '^EXIT=' <log> && break; sleep 10; done; grep '^EXIT=' <log> || echo still-running; tail -25 <log>
```

One loop waits 570 seconds at most. When it prints `still-running`, run it again. `EXIT=0` means the command passed. This loop ran successfully once, for a full gate run in a worktree.

## Dispatch a sub-agent

Dispatch a sub-agent with the Agent tool, and `subagent_type` set to `senior-dev`. The agent reads the instruction files of the repository itself. Put the rules the stage skill names into its prompt, because it inherits no loaded instruction.

## Write a plan

Use `superpowers-extended-cc:writing-plans` for the plan format only. Submit the plan to Loupe. Save no plan file, and skip its question about how to execute. Nobody approves the plan, so start the work as soon as it is linked.

## Run the plan task by task

Use `superpowers-extended-cc:subagent-driven-development` for the task loop only. Never invoke `superpowers-extended-cc:finishing-a-development-branch`. Never merge locally into the base branch, and never remove the worktree.

## Final reply

`claude -p` prints only the final reply, and the bridge keeps the first 4 KB of it.
