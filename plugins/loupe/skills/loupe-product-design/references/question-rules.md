# Question rules

Each rule has a stable ID, so a comment can cite it.

## Rules

1. Q1: Find facts yourself. Code, existing behaviour, docs and board history are facts. Look them up, with a subagent when that helps. A subagent only reads. It asks the owner nothing, and it changes no card and no document. Never ask the owner for a fact. Ask the owner only for decisions.
2. Q2: Give every question a recommendation. Each question carries a recommended answer and a one-line reason why it matters. Say when your confidence is low.
3. Q3: Ask one question at a time. Ask one question per turn. Use AskUserQuestion when the answer has clear options, and plain chat when the tool is missing. Never bundle two decisions in one question.
4. Q4: Ask for disagreement. A recommended answer makes it easy to agree with everything. The owner can agree with several answers in a row. Then name the answer you are least sure of, and ask again.
5. Q5: Send look and feel to a mockup. A question about how something looks gets a sketch or a prototype, not more questions.
6. Q6: Park technical choices. An entity, a table, an API or a module goes to the "For tech design" section. Do not settle it in the session.

Write each answer in the "Decisions log" section, with the question. Write each guess that the owner did not check in the "Assumptions" section.

## Coverage checklist

P6 works through this checklist. Mark each area Clear, Partial or Missing for yourself, and do not show the marks to the owner. Ask about a Partial or Missing area when its answer changes the behaviour. Ask first about the area with the highest impact and the most uncertainty.

1. Functional scope. What the change does, and what it leaves out.
2. Users and roles. Who uses the change, and which roles the product already has for them.
3. Main journeys. The steps a user takes from the trigger to the result.
4. Empty states. What a user sees before any data exists.
5. Error states. What a user sees when an action fails, and how the user recovers.
6. Edge cases. Limits, duplicates, concurrent edits, and a removed or renamed item.
7. Permissions. Who may see, create, change and delete each thing.
8. Data lifecycle. How data is created, changed, archived, deleted and exported.
9. Non-functional expectations. Limits, volume, speed a user notices, and availability.
10. Integrations. The MCP tools, the bridge, email, and any external service the change touches.
11. Terminology. The names a user sees, and whether they match the names the product already uses.
12. Completion signals. How a user knows that the action worked.
13. Docs and landing page. The pages the change alters, when the profile is missing.
