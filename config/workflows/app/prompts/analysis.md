Run the analysis {subjectId} of project {project} (projectId {projectId}).
Use the loupe-analysis skill when it is installed.

An analysis reads the worker runs of the project, or the comparison of one experiment, and ends with a report document and proposals.
Call the loupe MCP tool analysis_get with the analysis id first.
Stop when its state is done or failed.
Submit the report with analysis_report.

An analysis is read-only. Do not commit, push or create a branch.
Do not create, edit or move a card.
Do not change the project settings or its columns.
