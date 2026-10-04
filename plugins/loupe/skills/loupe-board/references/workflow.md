# How the workflow starts work on a card

An agent cannot read the template rules or a bridge's `rules.yaml` through the
MCP. This file says what the workflow does, so an agent can reason about a move
and ask the owner the right question. The Workflow page of the project shows the
template of the board and its rules.

The workflow of a board checks a card after each change, such as a move, an
approval or a pull request change, and again on a timer. A rule of the template
fires when its condition turns true. It moves the card, asks a bridge for a kind
of work, writes to the pull request, or pauses the card.

A bridge runs only the kinds of work that its `work:` map names, and it never
decides when work runs. A card that a person made unmanaged gets no move and no
work.

A template links each slot to a column by the column's id, so a rename keeps
the rules working. A deleted column leaves its slot empty, and a card that needs
the slot pauses with "workflow slot missing".
