# The Story Graph

The Story Graph is the central idea behind World Graph Studio. Instead of
keeping a screenplay, character bible, shot list, and media folder separate,
it stores each important item as a record and preserves the relationships
between them.

## What can be connected

The graph includes Projects, Story Worlds, Characters, Locations, Props,
Organizations, Episodes, Scenes, Shots, Sounds, Storyboard Frames, Assets,
Editorial Artifacts, Templates, and Connections.

For example, a Scene can take place at a Location, include several Characters
and Props, lead to ordered Shots, and reference visual or audio Assets. Editing
the Character does not require copying the same description into every Scene.

## A practical hierarchy

A common project can be understood like this:

```text
Project
├── Story World
├── Characters, Locations, Props, and Organizations
├── Episodes or other story groupings
│   └── Scenes
│       └── Shots and Sounds
│           └── Storyboard Frames and Assets
└── Editorial Artifacts
```

This is a useful mental model, not a rigid creative formula. A Character or
Location can be reused across many Scenes, and an Asset can be related to the
record it depicts or supports.

## Structured fields and relationships

The main editor contains ordinary title and description areas plus structured
fields provided by Secure Custom Fields. Use structured fields for details
that should remain searchable or reusable, such as narrative order, production
metadata, or relationship references.

Relationships give context to tools such as graph traversal, summaries,
continuity checks, search, AI assistance, import/export, and production
planning. Add connections deliberately; a descriptive mention in prose is not
automatically the same as a saved graph relationship.

## How different creators can use it

- Writers can maintain characters, settings, plot events, and continuity.
- Filmmakers can extend Scenes into Shots, storyboards, sounds, assets, and
  editorial records.
- Game creators can model reusable places, organizations, props, and story
  events without flattening them into one document.
- Educators can build connected learning worlds and trace relationships among
  people, places, events, and media.

Begin with only the record types your work needs. The graph becomes valuable
through meaningful connections, not through filling every available field.
