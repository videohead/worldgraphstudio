# Story Export

Export creates portable deliverables from the live Story Graph. Use it for
backups, review copies, writing workflows, storyboards, or moving a project to
another World Graph Studio installation.

## Available export types

The current Story Import & Export feature provides:

- Canonical World Graph Studio JSON for structured project interchange.
- Markdown screenplay output for readable, editable text.
- Markdown storyboard output for a scene-and-shot-oriented review document.

JSON preserves the project structure and relationships for re-import. Markdown
is designed for people and general writing tools, but it does not preserve the
entire graph as an importable project package.

## Export a project

1. Save any records you recently edited.
2. Open the World Graph Studio export screen.
3. Select the Project and desired format.
4. Generate and download the file.
5. Open the downloaded file and check that it contains the expected material.

Treat an export as a snapshot. Changes made in the exported Markdown do not
automatically update WordPress. To preserve a restorable project version,
export canonical JSON after important milestones and store it with your normal
backups.

## Choosing a deliverable

Use canonical JSON when another World Graph Studio installation needs to
recreate the connected project. Use screenplay Markdown when collaborators
need a readable narrative document. Use storyboard Markdown when the review is
centered on Scenes, Shots, and boards.

World Graph Studio can store editorial and production information beyond these
formats, but the presence of integration code does not mean every professional
format is a completed exporter. In particular, live EDL project export and
additional screenplay formats remain extension areas in the current release.

Always inspect a deliverable before sending it to a collaborator or production
system. For exact format boundaries, see
[Delivery Status](../about/Delivery_Status.md).
