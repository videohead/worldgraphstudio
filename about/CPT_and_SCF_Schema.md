# World Graph Studio CPT and SCF Schema Specification v1.0

> Your ideas. Your assets. No credits needed.

## Purpose

This document defines the WordPress Custom Post Types (CPTs), Structured Content Fields (SCF), taxonomies, and relationships used to implement the World Graph Studio Story Graph.

The schema described here is delivered in the current repository. See
[Delivery Status](Delivery_Status.md) for the release boundary. Delivered JSON,
FDX, and VideoDraft adapters map to this same canonical schema. Bundled
Fountain, Celtx, and Descript scaffolds target it as well, but are not currently
delivered workflows; further format adapters extend interchange without
changing the contract.

The schema serves as the foundation for:

- Story development
- AI advisor context retrieval
- Template-backed media and lyrics generation
- Production planning
- Script integration
- Editorial workflows

---

# Architecture Principle

```text
WordPress
    ↓
Custom Post Types
    ↓
Structured Content Fields
    ↓
Story Graph Relationships
    ↓
World Graph Studio Services
```

The Story Graph is the canonical source of truth.

---

# SCF Persistence and Runtime Contract

World Graph Studio archives one SCF field group per CPT under
`wordpress/wp-content/plugins/worldgraph/acf-json/`. These JSON files are committed
with the plugin and provide the portable, versioned seed for each field schema.
They are deliberately not registered as an always-on SCF load path: the
editable database group is the runtime authority, so a failed filesystem write
cannot hide a newer administrator change.

Content groups are exposed through SCF's native REST integration. The
Connection group is deliberately excluded because it contains private
control-plane configuration; Connections remain available only through the
authenticated World Graph Studio API.

On a privileged administration or WP-CLI request, World Graph Studio validates a changed
archive and merges it into the corresponding database group. Canonical fields
receive required storage updates, while database-managed labels, instructions,
layouts, choices, order, and site-added extension fields (including nested
fields) are preserved. The synchronizer is versioned, locked, verified after
import, and reports a retryable administrator notice instead of replacing an
invalid or ambiguous schema.

Saving a World Graph Studio group—or using SCF's standalone field tools—routes the owning
`group_worldgraph_*` definition back to the plugin archive. Unrelated SCF groups
retain their configured save paths. When the plugin directory or an existing
JSON file is not writable, the database definition remains editable and
runtime-authoritative, but World Graph Studio warns that the edit is not portable. Archive
changes intended for deployment should be reviewed and committed like code.
The plugin-directory archive is a source-controlled deployment artifact, not
durable per-site storage: export and commit intended changes before replacing
or upgrading the plugin. On multisite, the archive is shared by the network,
while each site's database groups remain its runtime definitions.

SCF database field groups are the runtime authority for the World Graph Studio field
schema. World Graph Studio's PHP definitions validate the canonical contract, provide a
fallback, and retain World Graph Studio-only semantics that SCF cannot express. Runtime
consumers use `worldgraph_get_fields()`, and value reads, writes, and deletes use
SCF's field APIs when a declared field exists. Compatibility hooks also
maintain SCF's hidden field-key reference when legacy code writes named post
meta directly.

Schema-backed CPTs do not enable WordPress's native Custom Fields editor. SCF
is the single authoring surface for declared fields; private operational,
mapping, health, and generation-job metadata remains ordinary post meta when
it is not part of a CPT's declared field group.

Committed core fields use deterministic, per-CPT keys so common field names
remain globally unique:

- Group key: `group_{cpt}`, for example `group_worldgraph_project`
- Field key: `field_{cpt}_{field_name}`, for example
  `field_worldgraph_project_status`

Keep these keys stable when changing labels or other field settings. Additions
to the committed core contract use the same per-CPT convention; extension
fields created in SCF retain the stable key generated for them by SCF.
Canonical group location, activation/REST exposure, and each canonical field's
key, name, type, parent, required state, and storage-sensitive settings are
protected. Labels, instructions, presentation settings, extra choices, field
order, and extension fields remain manageable in SCF.
Relationship extension field names are also stable Story Graph slot identifiers;
change their labels rather than renaming the field name after values exist.

SCF complex fields participate in the wider WordPress and World Graph Studio models:

- Taxonomy fields load assigned terms, save edited selections back to the
  object, allow term creation, and return term IDs.
- SCF post-object and relationship fields are bridged to named Story Graph
  slots. Saving a control replaces the slot's graph edges, and loading it reads
  the current targets from the Story Graph. Legacy named relationship meta is
  only a fallback until graph relationship metadata exists; matching legacy
  edges are adopted when that SCF control is explicitly saved.
- Scene `dialogue` is an importer-managed SCF repeater with speaker, line,
  description, and sequence values. Its value is read-only in the content edit
  form so manual edits cannot diverge from the imported screenplay structure.

The canonical WordPress post-type key for Editorial Artifact is
`worldgraph_editorial`. The longer name `worldgraph_editorial_artifact` is a
REST-facing base and legacy identifier, not a valid current CPT key.

---

# CPT: Project

## Purpose

Top-level container for all story assets.

## Fields

- `project_name` (text)
- `project_slug` (text)
- `description` (wysiwyg)
- `generation_prompt` (textarea; optional generation-specific instructions)
- `genre` (taxonomy: `worldgraph_genre`, multiple)
- `target_medium` (select)
- `status` (taxonomy: `worldgraph_status`)
- `owner` (user)
- `start_date` (date)
- `end_date` (date)
- `team_members` (relationship to `worldgraph_character`, multiple)
- `production_stage` (select)
- `frame_width` (number)
- `frame_height` (number)
- `aspect_ratio` (text)
- `frame_rate` (number)

## Relationships

- has_many Story Worlds
- has_many Episodes
- has_many Assets

---

# CPT: Story World

## Fields

- world_name
- synopsis
- generation_prompt
- timeline
- rules
- themes
- geography
- references

## Relationships

- belongs_to Project
- has_many Characters
- has_many Locations
- has_many Organizations

---

# CPT: Character

## Fields

- display_name
- biography
- age
- appearance
- generation_prompt
- personality
- motivation
- backstory
- voice_profile
- avatar_asset

## Taxonomies

- worldgraph_character_role

## Relationships

- belongs_to Story World
- appears_in Scenes
- linked_to Assets
- related_to Characters

---

# CPT: Location

## Fields

- location_name
- description
- generation_prompt
- environment_type
- geography
- mood
- visual_reference

## Relationships

- belongs_to Story World
- used_in Scenes
- linked_to Assets

---

# CPT: Prop

## Fields

- prop_name
- description
- generation_prompt
- purpose
- owner_character
- notes

## Relationships

- appears_in Scenes
- linked_to Assets

---

# CPT: Organization

## Fields

- organization_name
- organization_type
- description
- leadership
- goals

## Relationships

- belongs_to Story World
- contains Characters

---

# CPT: Episode

## Fields

- episode_number
- title
- synopsis
- generation_prompt
- status

## Relationships

- belongs_to Project
- contains Scenes

---

# CPT: Scene

## Fields

- scene_number
- title
- summary
- generation_prompt
- script_content
- dialogue (structured importer-managed entries: speaker, line, description, sequence)
- location
- time_of_day
- emotional_tone
- production_notes
- sequence

## Relationships

- belongs_to Episode
- contains Shots
- contains Sounds
- references Characters
- references Assets
- references Storyboards

## Celtx Sync Metadata (Connector Scaffold)

When an item is synced to Celtx, the optional integration stores one private
post-meta value:

| Meta Key | Type | Description |
|----------|------|-------------|
| `_worldgraph_celtx_mapping` | array | Map keyed by entity category (for example `scene`), with the remote `element_id` and `synced_at` timestamp |

The intended sync service uses this metadata for supported Celtx records.
Current response handling and Scene-call defects require repair before the
outbound workflow is classified as delivered. Removing a mapping does not
delete the remote record.

---

# CPT: Shot

## Fields

- `shot_name` (text)
- `shot_number` (number)
- `shot_type` (select)
- `camera_angle` (select)
- `lens` (text)
- `duration` (text)
- `take_number` (number)
- `slate_id` (text)
- `shot_description` (wysiwyg)
- `generation_prompt` (textarea; optional image and motion instructions)
- `editorial_notes` (wysiwyg)
- `scene` (relationship to `worldgraph_scene`)
- `sequence` (taxonomy: `worldgraph_sequence`)

## Relationships

- belongs_to Scene
- linked_from Sounds
- references Assets

`menu_order` remains the Shot's project-wide editorial position. The Scene edit
screen presents only Shots whose canonical child-owned `scene` relationship
targets that Scene. Reordering there swaps those Shots among their existing
editorial slots instead of renumbering every Scene from one, so other Scenes'
global cut positions are preserved. Legacy Shots with zero or duplicate global
positions receive new collision-free slots after the current cut the first time
they are explicitly ordered. The Shot editor does not expose WordPress Page
Attributes ordering, and the custom REST create/update resources reject direct
`menu_order` writes; interactive order changes use the complete Scene-scoped
sequencer.

---

# CPT: Sound

Represents a planned soundtrack cue. Sound is authorial and production intent;
the recorded or generated file remains a WordPress attachment represented by
an audio-typed `worldgraph_asset` linked through the `asset` relationship.

Ordinary screenplay dialogue remains structured Scene dialogue metadata and is
not duplicated as Sound records.

## Fields

- sound_type (taxonomy)
- production_status (worldgraph_status taxonomy)
- spoken_text (textarea; narration, voice-over, or ADR only)
- lyrics (textarea; music cues)
- start_timecode (text)
- duration (text; ISO 8601 preferred)
- diegetic (select: unspecified, diegetic, non_diegetic, internal, mixed)
- production_notes (textarea)

The WordPress title and content are the canonical cue title and description.

## Relationships

- belongs_to Scene (required)
- belongs_to Shot (optional)
- linked_to Character (optional narrator or voice source)
- linked_to Asset (optional rendered audio encoding)

When a Shot is selected, it must belong to the selected Scene. One Sound record
represents one cue occurrence; repeated cues may link to the same Asset.

Schema.org alignment uses `CreativeWork` for a planned Sound and
`MusicComposition` for a music cue. Audio-typed Assets remain `AudioObject`
encodings. The current model keeps composition text such as lyrics on the cue;
an extension can add a reusable composition entity for repeated music works.

---

# CPT: Asset

## Fields

- `asset_title` (text)
- `asset_type` (taxonomy: `worldgraph_asset_type`)
- `workflow_name` (text)
- `prompt` (wysiwyg)
- `model_name` (text)
- `seed` (number)
- `generation_parameters` (wysiwyg)
- `version` (text)
- `status` (select)
- `storage_uri` (text)
- `character` (relationship to `worldgraph_character`)
- `location` (relationship to `worldgraph_location`)
- `scene` (relationship to `worldgraph_scene`)

## Relationships

- linked_to Character
- linked_to Location
- linked_to Scene
- linked_to Storyboard
- linked_from Sounds

---

# CPT: Generation Template

The `worldgraph_template` CPT stores reusable, provider-neutral generation
configuration. It is an editorial configuration record, not an executable
workflow and not a replacement for the World Graph Studio Assets metabox.

## Fields

- `template_name` (text)
- `description` (wysiwyg)
- `generation_structure` (text)
- `modality` (select)
- `connection_id` (text; a `worldgraph_conn` post ID)
- `checkpoint` (text)
- `model_family` (select)
- `workflow_json` (textarea)
- `provider_template_id` (text)
- `configuration_json` (textarea)
- `input_bindings` (textarea)
- `model_requirements` (textarea)
- `default_values` (textarea)
- `provider_type` (text)
- `version` (text)
- `status` (select: `draft`, `active`, or `archived`)

`configuration_json` contains the parameter definitions, reference roles, and
SCF field mappings used to resolve a generation request. `default_values` may
provide reusable starting values, but explicit user input takes precedence
after validation.

The `run_controls` response property is not another SCF field. It is a
sanitized runtime DTO derived from declarations in the effective Template
configuration, a provider schema, or an inspectable workflow. Its contract
has `version: 1`, a deterministic `fingerprint`, and an ordered `fields` list.
Each field exposes a provider-neutral `key`, `label`, UI `type`, and only
applicable default, required, `min`, `max`, `step`, labeled `options`, group,
and bounded-description metadata. Public UI types are `string`, `textarea`,
`integer`, `number`, `boolean`, and `select`; their submitted values remain
scalar. Provider schema objects, workflow node IDs, binding paths, credentials,
and filesystem paths are not part of the DTO.

Template authors or trusted catalog sync may declare/discover negative prompt,
fixed integer seed, sampling steps, classic diffusion CFG or FLUX-style
guidance, sampler, scheduler, width, height, video duration, frames per second,
and distinct extra text-conditioning channels. These controls are exposed only
when present in the effective Template; dual-CLIP channels are not inferred
from `model_family` alone. Omitting a fixed seed leaves the Template/provider's
existing randomization behavior in force.

Media source values remain governed by `input_bindings`. In particular,
image-to-video and text-plus-image-to-video Templates resolve their image or
start frame from the source item's featured media, gallery, or configured
SCF/post-meta binding rather than from scalar run values. Checkpoint/model,
VAE, and CLIP file selection remains Template-authoring metadata validated by
catalog and readiness flows, not an Assets-run input.

## Delivered Generation Contract

Project, Story World, Character, Prop, Location, Shot, Scene, and Episode expose
an optional `generation_prompt` textarea. It stores instructions that apply to
generated media, such as house style, continuity requirements, camera motion,
or "no watermark." It augments the entity's descriptive fields; it does not
replace its synopsis, description, appearance, script, or production notes.
Like the other canonical SCF fields, its value is stored as `generation_prompt`
post meta with the stable SCF reference key for that CPT.

The representative-media registry supplies these default workflows and output
intents:

| CPT | Default workflow | Representative output intents |
| --- | --- | --- |
| Project | `project-key-art` | `project-key-art` image |
| Story World | `world-key-art` | `world-key-art` image |
| Character | `character-look-set` | full, front, three-quarter, profile, back, and close-up images |
| Prop | `prop-look-set` | full, front, three-quarter, profile, back, and close-up images |
| Location | `location-look-set` | full establishing, front, three-quarter, profile, back, and detail close-up images |
| Shot | `shot-still-and-video` | `shot-representative-still` image and `shot-video` video |
| Scene | `scene-filmstrip` | `scene-filmstrip` image using its shot progression |
| Episode | `episode-bookend-filmstrip` | `episode-bookend-filmstrip` image using its opening and final scenes |

Each output becomes a separate generation job so it can be retried, inspected,
and attributed independently. A plan may cover one item or, for a Project, the
Project and all supported descendants reachable through canonical ownership
relationships.

The Assets workflow resolves an active `worldgraph_template` and its associated
`worldgraph_conn` for each output. A queued internal `worldgraph_gen` child
record preserves the template, connection, provider, workflow identifier,
creative intent, prompt, resolved inputs, normalized scalar run parameters,
source Story Graph item, state, and timestamps. Relevant meta keys use the
`_worldgraph_gen_*` prefix,
including `_worldgraph_gen_template_id`, `_worldgraph_gen_connection_id`,
`_worldgraph_gen_provider_type`, `_worldgraph_gen_intent`, and
`_worldgraph_gen_status`.

A representative-media run also has a durable parent `worldgraph_gen` record.
It records `_worldgraph_gen_batch_kind = representative_media`, item or project
scope, a versioned frozen task plan, materialization cursor,
requester-scoped idempotency key, and aggregate status. The current workflow
version is stored in `_worldgraph_gen_workflow_version`. Children reference the
batch and frozen-plan position through `_worldgraph_gen_batch_id` and
`_worldgraph_gen_batch_step`. Planning is read-only;
starting first verifies that every required image and video output has a
runnable Template and re-derives and validates all submitted
`image_run_values` and `video_run_values` against their corresponding explicit
per-type Template override. A non-empty values object without that Template ID
is invalid. The plan then persists the normalized values frozen per task, and
those values are included in the idempotency request fingerprint. WP-Cron
materializes and activates children in bounded groups instead of holding one
request open for the complete Project run. Each task also freezes the Project
output values supported by its resolved Template, separately from explicit run
values. Omitting run values therefore uses compatible Project framing followed
by the Template sampling and negative-conditioning defaults.

A whole-Project demonstration uses the same parent/child contract with
`_worldgraph_gen_batch_kind = demonstration_video` and
`_worldgraph_gen_batch_scope = demonstration`. Its
`_worldgraph_gen_batch_plan` freezes each task's stable key, phase, required and
generation-required flags, dependencies, preferred modalities, symbolic media
input references, fallback, prompt, Template, and run/profile values. Resolved
generated-media attachment inputs are written back once and then treated as
immutable. Optional unavailable work is represented by a terminal `skipped`
child rather than removed from the plan. `_worldgraph_gen_assembly_plan`
separately freezes editorial timeline order, video/still fallbacks, subtitle
text, and Sound task keys; `_worldgraph_gen_assembly` stores the verified final
rough-cut result or assembly error. Audio overrides follow the same validated
contract as images and video through `audio_template_id` and
`audio_run_values`.

WP-Cron processes bounded batches, submits or polls the configured adapter,
supports cancellation, imports completed media for supported Template
modalities into the WordPress media library, and retains normalized results for
text-output Templates before marking those jobs complete.

After demonstration children are terminal, the separately scheduled
`worldgraph_process_rough_cut_assembly` hook advances bounded FFmpeg stages.
Signed `_worldgraph_rough_cut_state` makes normalization, concatenation,
subtitle, audio/silence, and import work resumable across cron ticks; assembly
worker token, heartbeat, attempt, and progress metadata protect recovery and
cancellation. Successful completion is published only after the resulting
video attachment and its batch/Project provenance are verified.

The built-in catalogs currently provision text-to-image, VideoDraft image and
video, ElevenLabs audio, VideoDraft audio, and Suno prompt-music,
custom-music, and `text_to_lyrics` Templates. Suno
Templates are transport-specific for SunoAPI.org REST or AceData Cloud MCP;
other modalities require an adapter extension. Imported attachments and Asset
records retain generation lineage. Featured-image and
`_worldgraph_asset_gallery_ids` updates remain separate from the Template
configuration itself. The Story Media Gallery editor allows authorized users
to add, remove, and reorder image, audio, and video attachments in that same
ordered metadata value. Presentation adapters preserve manual order for
unlabeled media and use `_worldgraph_gen_intent` to put recognized generated
Character and Prop views in their canonical look-development order.

Generated attachment filenames follow
`{project_slug|project-wp-slug}-{cpt-type}-{source-slug?}-{intent?}-job-{job_id}.{ext}`.
Synchronous imports without a queued job use a UTC timestamp in place of the
job token. Media Library titles mirror the readable Project, CPT type, source,
and intent or media type.

Credentials stay on the Connection or in environment-backed references. They
are not copied into a generation request or result record.

---

# CPT: Editorial Artifact

Canonical CPT key: `worldgraph_editorial`.

## Fields

- `artifact_type` (select)
- `export_format` (text)
- `generated_date` (date)
- `source_scene` (relationship to `worldgraph_scene`)
- `source_shot` (relationship to `worldgraph_shot`)
- `notes` (wysiwyg)
- `project` (relationship to `worldgraph_project`)

## Artifact Types

- EDL
- XML
- Timeline Metadata
- Shot List
- Production Reports

The schema also reserves `aaf` as an artifact-type value so imported or
externally produced records can be catalogued. World Graph Studio does not ship
an AAF or OMF codec in the current release; those are extension points.

---

# CPT: Connection

The `worldgraph_conn` CPT is a control-plane record for a configured provider
endpoint. Its `credential_reference` and `mcp_credential_reference` fields may
contain `env://` pointers or literal provider keys entered through the current
setup UI. Use environment references in managed deployments and restrict
Connection access to administrators. A `suno` Connection uses the former for
SunoAPI.org REST and the latter for AceData Cloud MCP; those credentials are
distinct and are not interchangeable. A `videodraft` Connection uses a
dedicated PAT or `env://VIDEODRAFT_API_KEY` reference for hosted JSON-RPC
generation and optional Project sync. Templates and generation jobs select a
Connection by post ID; this association is currently stored as configuration
rather than as a Story Graph edge.

## Fields

- `connection_name` (text)
- `provider_type` (select)
- `environment` (select)
- `status` (select)
- `is_default` (select: `yes` or `no`; unique per provider and environment)
- `endpoint_url` (text)
- `mcp_endpoint_url` (text)
- `credential_reference` (text)
- `mcp_credential_reference` (text)
- `capabilities` (textarea containing non-secret JSON object)
- `mcp_configuration` (textarea containing non-secret JSON object)
- `model` (text)
- `max_tokens` (text)
- `temperature` (text)
- `model_access` (textarea containing JSON)
- `enabled_structures` (textarea containing JSON)
- `enabled_templates` (textarea containing JSON)
- `rate_limits` (textarea containing JSON)
- `cost_controls` (textarea containing JSON)

`capabilities` describes the interfaces and generation shapes supported by the
Connection's selected provider/model. A multimodal model can advertise more
than one interface from the same Connection, for example:

```json
{
  "chat": true,
  "vision": true,
  "asset_generation": true,
  "modalities": ["text_to_image", "image_text_to_image", "text_to_video"]
}
```

The capability profile is declarative metadata. Asset execution remains
Template-driven: each listed modality must have a compatible Template before
it can be used for generation. Chat remains a Connection interface and does
not require creating a separate asset Template.

`mcp_configuration` stores non-secret deployment metadata for an accompanying
MCP service, such as `transport`, `host`, `port`, `path`, `service`, and health
checks. Credentials remain in the credential-reference fields.

---

# Global Taxonomies

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

## Project Status

- Draft
- In Development
- In Production
- In Post-Production
- Approved
- Archived
- On Hold

## Asset Type

- Image
- Character
- Environment
- Prop
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

# Relationship Table

```text
Project -> Story World
Project -> Episode
Episode -> Scene
Scene -> Shot
Sound -> Scene
Sound -> Shot
Sound -> Character
Sound -> Asset
Scene -> Character
Scene -> Location
Asset -> Character
Asset -> Location
Editorial Artifact -> Scene
Editorial Artifact -> Shot
Editorial Artifact -> Project
```

---

# AI Advisor Access Requirements

Story Graph fields are exposed through the native WordPress or World Graph
Studio APIs according to their sensitivity. Connection configuration,
including credential references, remains behind the administrator-only
Connections controller.

Agents must be able to:

- Query entities
- Traverse relationships
- Retrieve metadata
- Store recommendations
- Create assets and production artifacts

---

# Vocabulary Alignment (Story Science + StudioBinder)

To reduce ambiguity, World Graph Studio uses a controlled vocabulary that aligns with common story and film terminology.

## Core Hierarchy

- Shot: a single camera setup/take unit
- Scene: a dramatic unit made from one or more shots
- Sequence: a larger dramatic movement made from one or more scenes

Implementation note:

- World Graph Studio models Shot and Scene as first-class entities.
- Sequence is currently implemented as an optional taxonomy shared by Scene
  and Shot records (`worldgraph_sequence`).

## Canonical Narrative Terms

- Protagonist: model as a Character role/tag, not a separate CPT
- Antagonist: model as a Character role/tag, not a separate CPT
- Stakes and Conflict: capture in Episode synopsis, Scene summary or production
  notes, relationship metadata, or site-added SCF fields
- Climax and Resolution (Denouement): use the seeded Sequence terms on Scene or
  Shot records
- Premise and Logline: use Project description today, or add separate SCF
  extension fields when individually addressable values are required

## Canonical Production Terms

- Shot List: represented by ordered Shot entities per Scene
- Coverage: represented by Shot variants (shot_type, angle, lens, duration)
- Continuity context: represented by graph relationships across Scene, Shot,
  Sound, Asset, Character, and Location; the current local checker itself
  reports empty Scene/Shot content
- EDL: catalogued as an Editorial Artifact with links to source Scene/Shot;
  current EDL formatting does not yet derive live clips from those links

## Film Grammar Terms

- Take: a single uninterrupted recording instance of a shot
- Clapperboard/Slate: production marker identifying scene and take for sync and tracking
- Establishing Shot: a context-setting shot, represented in World Graph Studio by shot_type and scene metadata
- Insert/Cutaway/Reaction Shot: shot function categories, represented in shot_type taxonomy/enum values
- Continuity Error: a validation outcome for continuity checks, not a first-class entity

## Production Lifecycle Terms

- Pre-Production: planning phase (scripts, storyboards, shot planning)
- Principal Photography: active scene/shot capture phase
- Post-Production: editorial/finishing phase (EDL, timeline, exports)
- Daily Call Sheet: a production schedule artifact; not a first-class current entity
- Dailies: raw daily review media; store as Assets in the current model

## Field-to-Vocabulary Crosswalk

- scene_number -> Scene
- sequence -> Sequence
- shot_number -> Shot
- shot_description -> Coverage notes
- prompt_text -> Storyboard prompt
- prompt -> Asset generation prompt
- source_scene/source_shot (editorial) -> provenance lineage
- linked_to / derived_from relationships -> association vs provenance semantics
- status (asset/editorial) -> lifecycle state across production phases

## Naming Rule

When a relationship implies provenance, prefer Source wording in UI labels and docs.
When a relationship implies generic association, keep Linked wording.

---

# Current Schema

The current release registers 14 schema-backed content types:

- Project, Story World, Character, Location, Prop, Organization, and Episode
- Scene, Shot, and Sound
- Asset and Editorial Artifact
- Generation Template and Connection

Generation jobs use the internal `worldgraph_gen` record type. Additional
entity types can be supplied by extensions without changing the canonical
relationship model.
