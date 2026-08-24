# World Graph Studio Content Model Specification v1.0

> Your ideas. Your assets. No credits needed.

## Overview

The World Graph Studio Content Model defines the canonical Story Graph used by the platform.

All story, production, asset, and editorial information is represented as structured entities stored within WordPress using Custom Post Types (CPTs), Structured Content Fields (SCF), taxonomies, relationships, and metadata.

The Story Graph serves as the primary source of truth.

Scripts, storyboards, generated assets, production views, and editorial
artifacts are derived views of Story Graph data. A scheduling extension can
derive call sheets or shoot-day records without making them core entities.

The model described here is delivered in the current repository. See
[Delivery Status](Delivery_Status.md) for the authoritative inventory of
shipped capabilities and integration boundaries.

---

# Core Design Principles

## Story First

Narrative entities drive every workflow.

## Structured Content

Stories are represented as connected objects rather than documents.

## Reusable Data

Information entered once can be reused throughout the production lifecycle.

## AI Accessible

All entities must be queryable by AI advisors and workflows.

## Interoperability

Entities expose WordPress and World Graph Studio API surfaces where appropriate.
The default-enabled Story Import & Export feature plugin delivers canonical
World Graph Studio JSON import/export, Markdown screenplay/storyboard export,
and preview/confirm LLM decomposition for persisted JSON, TXT, Markdown,
Fountain, RTF, text-layer PDF, EPUB, DOCX, and ODT story sources. Final Draft
FDX import and optional VideoDraft structural synchronization reuse the same
canonical importer. The separate deterministic Fountain-to-FDX page, Celtx,
Descript, and Google Web Stories remain scaffold or prototype surfaces; EDL
format functions and its admin workflow are delivered. Further lossless,
application-specific formats can be added without changing the entity model.

---

# Entity Relationship Overview

Project
├── Story Worlds
├── Characters
├── Locations
├── Props
├── Organizations
├── Episodes
├── Scenes
├── Shots
├── Storyboards
├── Sounds
├── Assets
├── Editorial Artifacts
├── Generation Templates
└── Connections

---

# CPT: Project

Represents a top-level creative project.

## Fields

- Project Name
- Project Slug
- Description
- Generation Prompt Instructions
- Genre
- Target Medium
- Production Status
- Owner
- Start Date
- End Date
- Team Members
- Production Stage
- Frame Width and Height
- Aspect Ratio
- Frame Rate

## Relationships

- Linked from Story Worlds and Episodes through their Project fields
- Contains the Character records selected as Team Members

---

# CPT: Story World

Represents a fictional universe.

## Fields

- World Name
- Synopsis
- Generation Prompt Instructions
- Timeline
- Rules
- Themes
- Geography
- References

## Relationships

- Contains Characters
- Contains Locations
- Contains Organizations
- Belongs To Project

---

# CPT: Character

## Fields

- Name
- Biography
- Age
- Visual Description
- Generation Prompt Instructions
- Voice Description
- Personality Traits
- Motivation
- Backstory
- Avatar Asset

## Relationships

- Appears In Scenes
- Associated With Locations
- Related To Other Characters
- Referenced By Storyboards
- Referenced By Assets
- Belongs To Story World

## Taxonomies

- Character Role

---

# CPT: Location

## Fields

- Name
- Description
- Generation Prompt Instructions
- Geography
- Environment Type
- Mood
- Visual References

## Relationships

- Contains Scenes
- Appears In Storyboards
- Linked To Assets
- Belongs To Story World

---

# CPT: Prop

## Fields

- Name
- Description
- Generation Prompt Instructions
- Purpose
- Owner Character
- Notes

## Relationships

- Used In Scenes
- Appears In Assets

---

# CPT: Organization

## Fields

- Name
- Type
- Description
- Leadership
- Goals

## Relationships

- Belongs To Story World
- Links Leadership To Characters

---

# CPT: Episode

## Fields

- Episode Number
- Title
- Synopsis
- Generation Prompt Instructions
- Status

## Relationships

- Contains Scenes
- Belongs To Project

---

# CPT: Scene

## Fields

- Scene Number
- Title
- Summary
- Generation Prompt Instructions
- Script Content
- Dialogue (structured speaker, line, description, and sequence entries)
- Location
- Time Of Day
- Emotional Tone
- Production Notes
- Sequence

## Relationships

- Belongs To Episode
- Contains Shots
- Contains Sounds
- Located In a Location
- References Characters and Props
- References Assets
- References Storyboards

---

# CPT: Shot

## Fields

- Shot Number
- Shot Name
- Shot Type
- Camera Angle
- Lens
- Duration
- Take Number
- Slate ID
- Shot Description
- Generation Prompt Instructions
- Editorial Notes
- Sequence

## Relationships

- Belongs To Scene
- Linked From Sounds
- References Assets

---

# CPT: Sound

Represents one planned soundtrack cue in a Scene, optionally narrowed to a
Shot. It stores sound intent independently from the recorded/generated file so
the same audio Asset can be reused by multiple cues.

## Fields

- Sound Type
- Production Status
- Spoken Text (Narration / Voice-over / ADR)
- Lyrics (Music)
- Start Timecode
- Duration
- Story-world Relation (Diegetic / Non-diegetic / Internal / Mixed)
- Production Notes

## Relationships

- Belongs To Scene
- Belongs To Shot (Optional)
- Linked To Narrator / Voice Character (Optional)
- Linked To Rendered Audio Asset (Optional)

Ordinary dialogue continues to live in structured Scene dialogue metadata and
is not mirrored into Sound records.

## Schema.org Alignment

- Planned Sound cue: `CreativeWork`
- Music Sound cue: `MusicComposition`
- Linked audio Asset or attachment: `AudioObject`

In the current model, a music cue carries its composition text directly.
Extensions can introduce a reusable composition entity when a project needs to
normalize lyrics shared by multiple cue occurrences.

---

# CPT: Asset

## Fields

- Asset Title
- Asset Type
- Source Workflow
- Prompt
- Model
- Seed
- Generation Parameters
- Version
- Status
- Storage Location

## Relationships

- Linked To Characters
- Linked To Locations
- Linked To Scenes
- Linked To Storyboards
- Linked From Sounds

---

# CPT: Editorial Artifact

## Fields

- Artifact Type
- Export Format
- Generated Date
- Source Scene
- Source Shot
- Project
- Notes

## Supported Types

- EDL
- Timeline Metadata
- XML
- Shot Lists
- Production Reports

The `aaf` value is reserved in the schema for cataloguing an external artifact,
but the current release does not include an AAF or OMF import/export codec.

---

# CPT: Generation Template

A Generation Template stores reusable provider-neutral generation
configuration. It selects a modality and Connection, keeps a provider Template
or endpoint identity, validates JSON configuration and input bindings, and can
record model requirements and defaults. Only published Templates with the
internal status `active` can be submitted.

## Fields

- Template Name and Description
- Generation Structure and Modality
- Connection ID and Provider Type
- Provider Template / Model Endpoint ID
- Checkpoint and Model Family
- Workflow JSON and Configuration JSON
- Input Bindings and Default Values
- Model Requirements
- Version and Status

---

# CPT: Connection

A Connection is a private control-plane record for a provider endpoint. It is
managed through the permission-aware World Graph Studio admin and REST
controller rather than the public native CPT REST surface.

## Fields

- Connection Name, Provider Type, Environment, and Status
- Endpoint URL and optional MCP Endpoint URL
- Credential Reference
- Model, Max Tokens, and Temperature
- Model Access, Enabled Structures, and Enabled Templates
- Rate Limits and Cost Controls

Credentials can be stored as environment references such as `env://FAL_KEY`.
The current setup UI can also store a literal key in this administrator-only
record for local evaluation. Resolved environment values are not exposed as
ordinary Story Graph content; production deployments should prefer an
environment reference.

---

# Internal Generation Job

The optional **Generation Prompt Instructions** field on Project, Story World,
Character, Prop, Location, Shot, Scene, and Episode contains media-generation
constraints specific to that entity. It is additive context: the prompt
composer also reads the entity's detailed authorial fields and the creative
objective for the selected representative output.
In the Assets metabox, one-off directions use a separate blank additive field;
the composed provider prompt is available only as a collapsed read-only review.
The conditional top level separates **Image**, **Sequence**, **Video**, and,
for Projects, **Demonstration**.
Each available mode reveals only its own output selector, Template controls,
explanation, and action; undefined modes remain visibly unavailable. Shots
expose all three, multi-view Characters/Props/Locations expose Image and
Sequence, Projects expose key-art Image and Project-wide Sequence, and
single-output recipes expose Image only. Project Demonstration reviews a
whole-story plan and exposes compatible Image, Video, and generated-audio
Template selectors when those task types require generation.

The default representative-media model is:

| Entity | Default representation |
| --- | --- |
| Project | One project key-art image |
| Story World | One defining world key-art image |
| Character | Full, front, three-quarter, profile, back, and close-up images |
| Prop | Full, front, three-quarter, profile, back, and close-up images |
| Location | Full establishing, front, three-quarter, profile, back, and detail close-up images |
| Shot | One representative still and one video |
| Scene | One filmstrip image summarizing its ordered Shots |
| Episode | One filmstrip image contrasting its first and last Scenes |

`worldgraph_gen` records represent individual work and durable
representative-media or demonstration-video batches. A batch record is parented
to the requested item or Project and retains batch kind, scope, requester,
idempotency key, versioned frozen plan, request fingerprint, materialization
cursor, aggregate counts, and state. Each child job is parented to its source
Story Graph item and retains the batch ID, frozen-plan step, stable task key,
and creative intent together with the Template, Connection, provider,
workflow, prompt, inputs, state, remote job
identity, results, imported attachment IDs, and provenance.

An item plan covers one entity. A project plan traverses canonical `contains`
and `belongs_to` ownership edges from the Project and includes every supported
descendant once. Planning does not queue work. Starting first resolves every
required output, then persists a versioned frozen plan. WP-Cron materializes
and activates child jobs in bounded groups, allowing the batch to execute and
report work safely over hours or days without one large start request.

A Project demonstration has batch kind `demonstration_video`. Its task plan
also freezes phases, dependencies, preferred modalities, symbolic input and
fallback references, and required/generation-required flags. Completed sibling
media resolves those symbolic references to immutable attachment inputs;
unavailable optional enhancements remain visible as `skipped` children. A
separate frozen assembly plan owns timeline order, video-to-still fallback,
subtitles, and Sound task placement. Once all children are terminal, the
`worldgraph_process_rough_cut_assembly` WP-Cron hook advances resumable FFmpeg
normalization, concatenation, subtitle, audio/silence, and import stages. The
parent stores assembly progress and finally either a provenance-verified rough
cut attachment DTO or a terminal assembly error. Cancellation is durable across
both generation and assembly ticks.

These are internal workflow records, not SCF-backed editorial content types.
Generated attachment filenames retain the Project slug, source CPT type,
optional source slug and intent, and generation job ID; synchronous imports use
a UTC timestamp fallback. Their Media Library titles use the equivalent
human-readable Project, type, source, and intent/media-type labels.

---

# Taxonomies

## Genre

- Drama
- Comedy
- Sci-Fi
- Fantasy
- Horror
- Documentary
- Animation
- Action
- Thriller
- Romance

## Asset Type

- Image
- Character
- Prop
- Environment
- Storyboard
- Video
- Audio
- Lookbook
- Concept Art

## Sound Type

- Narration
- Voice-over
- Music
- Sound Effect
- Ambience
- Foley
- Intentional Silence
- ADR

## Production Status

- Draft
- In Development
- In Production
- In Post-Production
- Approved
- Archived
- On Hold

## Character Role

- Protagonist
- Antagonist
- Deuteragonist
- Mentor
- Ally
- Foil
- Love Interest
- Comic Relief
- Ensemble
- Unknown

## Character Relation

- Protagonist
- Antagonist
- Mentor
- Ally
- Family
- Love Interest
- Rival
- Sidekick
- Neutral
- Unknown

## Scene Tag

- Action
- Drama
- Comedy
- Tension
- Revelation
- Exposition
- Emotional
- Quiet
- Chaotic
- Flashback
- Voiceover
- Montage

## Sequence

- Setup
- Rising Action
- Complication
- Midpoint
- Climax
- Resolution

## Template Category

- Character
- Scene
- Storyboard
- Concept
- Editorial
- Marketing
- Asset Variation
- Video
- Image

---

# AI Advisor Access Model

Story Graph entities expose structured metadata for:

- Narrative Advisors
- Prompt Advisors
- Production Advisors
- Editorial Advisors
- Technical Advisors

Advisors retrieve context directly from Story Graph entities.

---

# Script Integration Mapping

## Celtx Integration Scaffold

The optional `worldgraph-celtx` WordPress plugin defines mappings for intended
World Graph Studio-to-Celtx synchronization. Current response handling and
Scene-call defects block a verified outbound workflow.

### Intended Entity Mapping

| World Graph Studio CPT | Celtx Entity | Current Sync Direction |
|-------------|--------------|----------------|
| Project | Generic element in the current connector source | Intended World Graph Studio → Celtx |
| Character | `/element` (character) | World Graph Studio → Celtx |
| Location | `/element` (location) | World Graph Studio → Celtx |
| Scene | Episode Scene endpoint; episode resolution needs repair | Intended World Graph Studio → Celtx |
| Shot | Comment in the current connector source | Intended World Graph Studio → Celtx |

### ID Mapping

Persistent mapping is stored in `_worldgraph_celtx_mapping`. The array is keyed
by entity category and records the remote `element_id` and `synced_at`
timestamp. Unsync removes the local category mapping without deleting the
remote Celtx record.

### API Endpoints

- `GET /wp-json/worldgraph/v1/celtx/test`
- `GET|POST /wp-json/worldgraph/v1/celtx/sync`
- `POST /wp-json/worldgraph/v1/celtx/sync/{type}`
- `POST /wp-json/worldgraph/v1/celtx/sync/{type}/{id}`
- `GET /wp-json/worldgraph/v1/celtx/mapping/{type}/{id}`
- `DELETE /wp-json/worldgraph/v1/celtx/unsync/{type}/{id}`

### Interchange Status

- The bundled Story Import & Export feature plugin owns canonical World Graph
  Studio version 1.2 JSON import and export. Import validation can run without
  committing changes; export projects one live Project and its supported graph
  without serializing Connections, Templates, generation jobs, users, or
  fields outside the import contract.
- Persisted JSON, TXT, Markdown, Fountain, RTF, text-layer PDF, EPUB, DOCX, and
  ODT story sources can produce a normalized, importer-validated preview.
  Canonical JSON bypasses the LLM; other sources use only the selected
  compatible LLM Connection and require explicit confirmation before commit.
  The source remains a WordPress attachment and is not a Story Graph entity.
- Final Draft FDX import normalizes screenplay structure into the canonical
  JSON contract and reuses the same entity and relationship persistence.
- Markdown export produces screenplay and storyboard views from live project
  data.
- VideoDraft synchronization optionally pushes and pulls its shared structural
  Project subset with persistent mapping and conflict checks.
- The separate deterministic Fountain-to-FDX integration and Celtx target
  these contracts but remain non-delivered scaffolds until their documented
  runtime blockers are repaired. `.fountain` remains an accepted unstructured
  source for LLM decomposition.

Lossless Fade In, Highland, Story Architect, format-specific merge workflows,
OCR for image-only PDFs, and additional professional script exporters are
possible extensions. They are not current API or schema contracts and do not
reopen the closed roadmap item.

---

# Editorial Integration Mapping

Story Graph → Editorial Outputs

Supported Targets:

- CMX 3600 EDL PHP parsing and formatting
- SMPTE 436m XML EDL PHP parsing and formatting
- Timeline Metadata
- Storyboard

AAF, OMF, and NLE-specific panels are extension points rather than current
delivery commitments.

---

# Vocabulary Assumptions

World Graph Studio aligns with widely used story and film terminology to keep metadata portable across writing, production, and editorial workflows.

## Structural Terms

- Shot: smallest filmed unit
- Scene: dramatic unit composed of one or more shots
- Sequence: dramatic run composed of one or more scenes

Current model coverage:

- Scene and Shot are modeled directly.
- Sequence is modeled as an optional taxonomy attached to Scene and Shot
  records.

## Story Terms

- Protagonist and Antagonist are Character roles.
- The current Project `description` can hold a premise or logline; sites that
  need separately addressable values can add SCF extension fields.
- Conflict, stakes, and turning points can be recorded in Episode synopsis or
  Scene summary/production notes, or normalized by extension fields.
- Climax and Resolution are seeded Sequence terms that can classify Scenes and
  Shots.

## Film Production Terms

- Coverage is captured through shot-level metadata (type, angle, lens, duration).
- Shot List is a view derived from ordered Scene -> Shot relations.
- The local continuity checker currently reports empty Scene/Shot content;
  configured AI assistance can perform broader contextual review.
- EDL can be catalogued as an Editorial Artifact linked to source Scene/Shot
  records. The optional EDL formatter does not yet derive those live links into
  its admin export clip list.

## Film Grammar Terms

- Take is the recording instance of a shot and is captured by `take_number`.
- Slate/Clapperboard identity is captured by optional `slate_id` metadata.
- Establishing, Insert, Cutaway, and Reaction are shot function categories and should map to shot_type values.
- Continuity errors are validation findings generated from graph comparisons, not standalone entities.

## Lifecycle Terms

- Pre-Production covers planning entities and metadata.
- Principal Photography covers capture-oriented shot/scene execution data.
- Post-Production covers editorial artifacts, timeline metadata, and exports.

## Preferred Wording in UI and API

- Use Source for provenance links (for example, Source Scene, Source Shot).
- Use Linked for non-provenance associations (for example, Linked Character).

---

# Design Principle

The Story Graph is the canonical source of truth.

Every script, storyboard, generated asset, production plan, and editorial
artifact produced by World Graph Studio is traceable back to structured story
entities.
