---
title: Projects
description: "Create projects and describe their purpose."
---

Open All projects to choose a workspace. Each tile links to its Workshop and shows that project's counts.
Press Tab to reveal Skip to content. Press Enter to bypass navigation and focus the page content.
The next Tab moves to a control within that content.
The sidebar picker marks the current project with a check. It includes the current project even when newer projects fill the list.
Select another project to open its Workshop. Escape closes the picker and returns focus to Switch project.
A page outside a project, such as your account settings, keeps the last project you opened in the sidebar.
The sidebar marks the current page. Document and inbox counts remain visible beside their labels on the selected row.

Select New project to open the form. Enter a name and, optionally, a description of up to 500 characters.
Descriptions appear as plain text on project tiles. Line breaks remain visible.
The domain and document language remain separate settings.
Select Add project to create it and open its Workshop.

The header keeps New project reachable on narrow screens and with enlarged text.

Use a tile's Edit control or Project settings to change its description.
Clear the description and save to remove it.
An agent connected to the project can change the name, description, domain and document language with the `project_update` MCP tool.
It changes the allowed origins of the sign-in widget with `project_origins_set`.
See [Changing the project settings](mcp.md#changing-the-project-settings).
An invalid submission keeps the entered text so you can correct it.

While the inbox is on, Project settings also has an Inbox section.
The project owner chooses there which card waits open an item in the inbox.
See [Choosing which waits open an item](inbox.md#choosing-which-waits-open-an-item).

The first-project setup form also accepts a description.
Account data exports include descriptions in `projects.json`.

Open Agents to see the project's reported bridge connections, CLI versions, and latest heartbeats.
Connection details and setup buttons wrap to fit narrow screens and enlarged text.
Workshop's Your crew section shows these connections and their health. Select a row to open its connection details in Agents.
When the project has no reported connections, Your crew links to connection setup.
Each connection shows its own health. This does not report whether an individual agent is available or running.
Agent configuration and rules remain read-only in Loupe. Configure them through the CLI and its rule file.

## Agent readiness

Project settings has an Agent readiness tab. Only the project owner can open it.
The Discovery part shows the state of the latest discovery run, with the same status as the Repository row of the [readiness guide](workshop.md#run-discovery).
The Agent GitHub account part links to the page that sets up a [GitHub user for the agents](../getting-started/agent-github-account.md).
The Workshop guide part has one switch, "Show the readiness checklist on the Workshop".
Turn it on and select Save to show the [readiness guide](workshop.md#the-readiness-guide) on the Workshop again.
Turn it off and select Save to hide the guide.
While the guide is hidden, the tab shows the date it was hidden.
An agent connected to the project can show or hide the guide with the `readiness_guide_set` MCP tool.

## Repositories

The Connections tab of a project has a Repositories section. Only the project owner sees it.
Connect a GitHub repository there, and a merge, a review or a check result in it reaches the cards of this project that link the pull request.
[The board](board.md#what-github-tells-a-card) says what a card receives.

Loupe knows a repository by the ID GitHub gives it, so a rename or a transfer keeps it in the same project.
Through the GitHub App, a repository belongs to one project only. When the App of another project already holds a repository, Loupe does not connect it here.
A webhook feeds only the project it belongs to, and it blocks no other project.

The section offers two methods. The operator of the instance decides which ones are available.

### A webhook for the project

Select Create a webhook. Loupe shows a payload URL and a secret.
Copy the secret at once. Loupe shows it one time only.
On GitHub, open the Settings of each repository, then Webhooks, then Add webhook. Paste the payload URL and the secret.
Set the content type to `application/json`.
Select "Let me select individual events", then select Pull requests, Pull request reviews, Check suites and Repositories.
A project has one webhook. Add the same URL and secret to each repository you want to connect.
A repository is connected when its first delivery arrives.

The webhook shows its own state. Waiting for the first delivery means GitHub has not sent anything yet.
Working shows the time of the last delivery.
Failing shows the time and the reason of the last refused delivery. A wrong secret or a content type other than JSON is the usual cause.
Select Generate a new secret to replace the secret. The old secret stops working at once, so paste the new one into each repository on GitHub.

### The GitHub App

Select Install on GitHub. On GitHub, choose the account and the repositories the App can reach.
GitHub then asks you to authorize Loupe. Loupe connects the installation only when GitHub lists it for your account.
The repositories of the installation that your GitHub account can reach join the list at once. Under "All repositories", each other repository joins on its first delivery.
When you add or remove a repository in the App settings on GitHub, the list follows.
The section says when GitHub suspends the installation or when someone uninstalls it.

### The list

Each repository shows a state.
Waiting for the first delivery means Loupe has not received anything from the repository yet.
Working shows the time of the last delivery.
Quiet means no delivery arrived for 30 days. A quiet repository can be a repository with no activity, or a connection that stopped working on GitHub.

Select Remove to disconnect a repository. Loupe stops updating cards from it.
With a webhook, or with an App installation on "All repositories", the next delivery from that repository connects it again. So also remove the webhook, or remove the repository from the App on GitHub.
With an App installation on selected repositories, Loupe ignores the repository until you remove it from the installation on GitHub and select it again.
