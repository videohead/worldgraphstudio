# World Graph Studio Story Graph Specification v1.0

> Your ideas. Your assets. No credits needed.

## Purpose

The Story Graph is the core architectural component of World Graph Studio.

It provides a structured, interconnected representation of narrative, production, asset, and editorial information. Rather than treating a story as a collection of documents, World Graph Studio treats a story as a living graph of related entities.

The Story Graph serves as the canonical source of truth for all World Graph Studio workflows.

This specification describes the delivered current release. See
[Delivery Status](Delivery_Status.md) for the release boundary.

---

# Design Goals

## Narrative Continuity

Preserve relationships between story elements throughout the project lifecycle.

## Reusability

Allow data entered once to be reused across writing, storyboarding, generation, production, and editing.

## AI Accessibility

Enable advisors and agent workflows to access contextual project knowledge.

## Traceability

Every generated artifact is traceable back to originating story entities.

## Extensibility

Allow plugins to add entity types, workflows, integrations, and alternative
graph projections without changing the canonical WordPress model.

---

# Canonical Principle

The Story Graph is the source of truth.

The following are generated views or artifacts of graph data in the current
core:

- Markdown screenplays and storyboards
- Storyboard records and Asset collections such as lookbooks
- Shot lists
- Production pipeline, task, and timeline views
- CMX/XML EDL PHP format functions (the bundled admin download workflow remains
  incomplete and uses sample clip input)
- Editorial metadata
- Generated Assets and their provenance

Schedules, call sheets, shoot-day entities, and provider-specific production
documents can be supplied by extensions; they are not first-class current
content types.

---

# Core Entity Graph

Project
├── Story World
│   ├── Characters
│   ├── Locations
│   └── Organizations
│
├── Episodes
│   └── Scenes
│       ├── Shots
│       └── Sounds
│
├── Props
├── Storyboards
├── Assets
└── Editorial Artifacts

Generation Template → Connection → Generation Job → Asset/Attachment

Generation Templates and Connections form the control plane. Internal
generation jobs link a source Story Graph item or Asset to the chosen Template,
Connection, result attachments, and provenance.

---

# Relationship Types

## Contains

Parent-child ownership.

Examples:

- Project contains Episodes
- Episode contains Scenes
- Scene contains Shots

## Appears In

Used for narrative participation.

Examples:

- Character appears in Scene
- Prop appears in Shot

## Located In

Represents geographic or environmental placement.

Examples:

- Scene located in Location
- Organization located in Location

## References

Associates supporting information.

Examples:

- Storyboard references Character
- Asset references Scene

## Derived From

Tracks provenance.

Examples:

- Asset derived from Scene
- EDL derived from Shot List

---

# Character Graph

Character
│
├── Relationships
├── Scenes
├── Assets
├── Dialogue
├── Sounds
├── Storyboards
└── Locations

Character nodes become major continuity anchors.

---

# Scene Graph

Scene
│
├── Characters
├── Locations
├── Props
├── Shots
├── Sounds
├── Storyboards
├── Assets
└── Editorial References

Scenes act as the primary narrative aggregation point.

---

# Sound Graph

Sound
│
├── Sound Type
├── Scene
├── Shot (Optional)
├── Narrator / Voice Character (Optional)
├── Spoken Text or Lyrics
├── Timing and Diegesis
└── Rendered Audio Asset (Optional)

A Sound is a planned cue, while an audio-typed Asset represents the rendered
encoding or WordPress attachment. Ordinary screenplay dialogue remains structured Scene
metadata. This separation supports narration, music, effects, ambience, Foley,
and intentional silence without duplicating dialogue or media files.

---

# Asset Graph

Asset
│
├── Source Scene
├── Source Character
├── Prompt
├── Workflow
├── Model
├── Version
└── Storage Location

Assets must maintain lineage information.

---

# Production Graph

Production planning is connected to story entities. The delivered model uses
Project `production_stage`, Project task/timeline metadata, ordered Scenes and
Shots, and related Assets. Shot-list and pipeline views are derived from those
records. A production Schedule, Call Sheet, or Shoot Day is not a first-class
current entity; extensions can derive one without replacing the Story Graph.

---

# Editorial Graph

Project
→ Scene
→ Shot
→ Editorial Artifact

Editorial artifacts remain linked to source Project, Scene, or Shot records.
The current core stores timeline views as Project metadata rather than a
separate Timeline Segment content type. The optional EDL package formats clip
arrays, but its current admin export does not yet resolve this live graph into
clips.

---

# AI Retrieval Model

Agents retrieve context through graph traversal.

Example Query:

Character → Scenes → Sounds / Storyboards → Assets

This allows advisors to access relevant project knowledge without requiring full-project context.

---

# Continuity Engine

Continuity checking is delivered as local Story Graph analysis, an admin panel,
on-save checks for Scenes and Shots, persisted issue summaries, and AI Editor
continuity assistance when an LLM connection is configured. The local checker
currently flags structural/content findings such as an empty Scene; the graph
and advisor context allow broader character, location, prop, relationship,
asset, and Sound review without defining a separate Continuity Error entity.

---

# Search and Discovery

World Graph Studio delivers entity-filtered WordPress search, suggestions, and
search modes through `/wp-json/worldgraph/v1/search`. The current semantic mode
uses the same WordPress-backed retrieval as keyword mode; no vector
infrastructure or separate semantic provider is registered in the core plugin.

Examples:

- Find all scenes involving a specific character.
- Find all assets related to a location.
- Find all shots derived from a storyboard.
- Find all editorial artifacts associated with an episode.

---

# Graph Traversal Examples

## Narrative Query

Character
→ Scene
→ Episode

Story-arc classification can be represented with existing Episode/Scene
metadata or supplied by an extension; it is not a current first-class entity.

## Production Query

Project
→ Scene
→ Shot
→ Asset

## Asset Query

Character
→ Asset
→ Generation Job / Template
→ Prompt

## Editorial Query

Project
→ Scene
→ Shot
→ Editorial Artifact / EDL

---

# Delivered Intelligence and Extension Points

## Analytics

The delivered WordPress-native graph analyzer reports entity and relationship
counts, density, relationship-type distribution, connected and isolated
entities, character networks, scene presence, and co-occurrence. Project-scoped
admin views expose these results and cache them for responsive exploration.

The same analyzer supplies a deterministic Development Compass. It identifies
missing Character, Location, or Scene foundations; Characters, Locations, and
Props not represented in a Scene directly or through a Scene-owned Shot; and
Scenes without Character context directly or through their Shots, or without a
direct Location connection. When those checks are clear, it returns a
next-event question about what changes and which Scene or element could expose
that change, so the result remains generative rather than becoming an empty
scorecard. Findings report graph evidence and an open development question.
They are advisory projections, not canonical nodes, inferred edges, continuity
errors, or story-quality scores. WordPress exposes non-mutating creator links,
while the headless Project view uses the visibility-filtered published result.

## Story Intelligence

Continuity findings, graph traversal, summaries, dramaturgy assistance,
narrative recommendations, and specialist advisors operate on Story Graph
context in the current release. Their output remains advisory rather than a
new canonical entity unless a user saves it to the graph.

## Alternative Graph Stores

Neo4j, ArangoDB, RDF stores, and provider-specific visualization systems are
possible extension targets. They are not required by, or promised as part of,
the current WordPress-native release.

---

# Strategic Importance

The Story Graph is the long-term differentiator of World Graph Studio.

AI models, image generators, video generators, and external tools will evolve rapidly.

The structured representation of story knowledge remains the enduring asset.

By treating stories as connected data, World Graph Studio can support the entire lifecycle of creative development, production planning, asset generation, and editorial workflows.
