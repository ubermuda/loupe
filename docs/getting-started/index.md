---
title: "First steps"
description: "Connect a coding agent to a Loupe instance, send a first document, and review it."
---

To run an instance of your own, read [Installing Loupe](../operating/install/index.md)
first.

This page is for a person who already has a Loupe account and the URL of an
instance. At the end, your agent sends a plan to Loupe, and you review it in the
browser. The examples use `https://loupe.example.com` as the instance URL.

## 1. Install the CLI

The `loupe` CLI connects a coding agent on your machine to Loupe. It runs on
macOS and Linux. Follow [Installing the CLI](cli.md), then check the install:

```bash
loupe version
```

## 2. Sign in and name the project

Sign in once on each machine:

```bash
loupe login --url https://loupe.example.com
```

The CLI prints a link and a code. Open the link in a browser where you are
signed in to Loupe. Make sure that the page shows the same code, then choose
**Allow**. [Signing in the CLI with a code](../using/connected-apps.md#signing-in-the-cli-with-a-code)
tells what the page shows.

Then name the project in each repository:

```bash
cd ~/code/my-project
loupe init
```

`loupe init` writes `.loupe.yaml`, which holds the project id. It lists the
projects your login covers and asks which one. Commit the file, so everyone in
the repository reaches the same project.

When Claude Code does not start the `loupe` MCP server yet, `loupe init` offers
to declare it for every repository:

```bash
claude mcp add --scope user loupe -- loupe mcp
```

To commit the server to the repository instead, run `loupe init --mcp-json`. It
writes `.mcp.json`:

```json
{
  "mcpServers": {
    "loupe": { "command": "loupe", "args": ["mcp"] }
  }
}
```

The file holds no credential. Claude Code asks you to approve the server the
first time. [Connecting through the CLI](../using/mcp.md#connecting-through-the-cli)
has the details.

## 3. Install the Claude Code plugin

The plugin adds skills that tell the agent how to write a document, work a board
and act on feedback. It holds no credential.

```bash
claude plugin marketplace add ubermuda/loupe
claude plugin install loupe@loupe
```

[The Claude Code plugin](../using/mcp.md#the-claude-code-plugin) lists the
skills.

## 4. Write a first document

Start Claude Code in the repository, and ask it for a plan. For example:

> Write an implementation plan for the next feature, and send it to Loupe for
> review.

The agent calls the `document_create` tool. Loupe stores the plan as a document
in your project, and the tool returns a review URL. The agent gives you that
URL.

## 5. Read a first review

Open the review URL. Select a passage to comment on it. A comment can also
suggest a replacement for the passage.

When you finish, select **Finish review**. Approve the version, or request
changes with a note. When you request changes, ask the agent to read the
review. It calls `document_get_review` and sends a new version. [Documents and review](../using/documents.md) describes the review page.

## Other ways to connect

A client that is not on your machine connects by URL over OAuth, with no CLI.
Claude reaching your instance from Anthropic's servers is an example. See
[Connecting by URL](../using/mcp.md#connecting-by-url).

Loupe issues no static API token, so every connection starts with a sign-in in a
browser.
