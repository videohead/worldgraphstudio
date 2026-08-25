# World Graph Studio JSON Import Specification

## Purpose

This document defines the portable World Graph Studio JSON contract used by the
example workflow. The default-enabled Story Import & Export feature plugin owns
both directions: its importer normalizes a document into WordPress posts,
Structured Content Fields (SCF), taxonomies, and Story Graph relationships,
while its canonical exporter projects a live Project back into version 1.2
JSON.

Two examples are maintained:

- `little-red-riding-hood.worldgraph.json` is the legacy version 1.1 example.
  It remains intentionally small for compatibility testing.
- `little-red-riding-hood-full-featured.worldgraph.json` is the comprehensive
  version 1.2 example. It exercises every story/content CPT exposed by the
  custom `worldgraph/v1` REST API: Project, Story World, Character, Location,
  Prop, Organization, Episode, Scene, Shot, Sound, Asset, and
  Editorial Artifact.

Connections, Templates, and internal generation records are configuration or
runtime state and are deliberately excluded from the interchange example.

## Version Compatibility

- Writers using the comprehensive contract emit `worldgraph_version: "1.2"`.
- Readers continue to accept version 1.0 and 1.1 documents.
- `sounds` was added in version 1.1. A missing `sounds` section is treated as an
  empty array.
- `assets`, `organizations`, `episodes`, and `editorial_artifacts` were added to
  the comprehensive example in version 1.2. Each is optional; a missing section
  is treated as an empty array.
- Version 1.0/1.1 dialogue rows may use `text`; version 1.2 uses the canonical
  `line` key. Readers normalize either key to stored dialogue `line` metadata.
- Version 1.0/1.1 Character `archetype` values remain valid compatibility input.
  Version 1.2 writers use `roles` taxonomy slugs.
- Explicit Scene, Shot, dialogue, and Sequence ordering added
  in 1.2 falls back to array order for legacy documents.
- Fields added in 1.2 are optional when reading older documents. Their absence
  must not prevent import.
- When a 1.0/1.1 document matches a legacy Sequence term by title and that term
  has no `external_id`, the importer performs a one-time identity migration by
  attaching the document's Sequence ID. Version 1.2 documents require
  overwrite before claiming an unkeyed same-title term.

## Common Conventions

### External IDs

Every imported entity has a portable string `id`. All relationship values in
the interchange document use those external IDs, never WordPress post IDs or
taxonomy term IDs. The importer resolves references only after validating the
complete document.

External IDs must be unique across the document, including Project, World,
Sequence, and all array entities. They are also stable upsert keys in a
WordPress installation, so writers must namespace them and must not reuse an ID
for a different record in another document. The comprehensive example uses the
`lrrh_full_` prefix so it can be imported alongside the legacy example without
updating or skipping the legacy records.

### Taxonomies

Taxonomy values are lowercase WordPress term slugs. Numeric term IDs and term
display names are installation-specific and are rejected:

- `project.genres[]` -> `worldgraph_genre`
- `project.production_status` -> `worldgraph_status`
- `characters[].roles[]` -> `worldgraph_character_role`
- `characters[].relations[]` -> `worldgraph_character_relation`
- `episodes[].production_status` -> `worldgraph_status`
- `scenes[].tags[]` -> `worldgraph_scene_tag`
- `sounds[].type` -> `worldgraph_sound_type`
- `sounds[].production_status` -> `worldgraph_status`
- `assets[].asset_type` -> `worldgraph_asset_type`

Seeded terms should be referenced by their existing slugs. Ordinary Scene
dialogue must not be represented by the reserved `dialogue` Sound type.

### API Representation

The import document is a portable, flattened interchange format rather than a
literal REST request body. After import, custom Story Graph REST resources
represent the same data as follows:

- `external_id` is returned at resource top level and is the original JSON `id`.
- WordPress identity and lifecycle values such as `id`, `title`, `content`,
  `status`, and `menu_order` are top-level response properties.
- Scalar SCF values are returned in `meta` under their canonical CPT field names.
- Taxonomy assignments are returned in `taxonomies`, keyed by taxonomy name.
- Relationships are returned in `relationships` after external IDs have been
  resolved to WordPress objects. Related resources expose their own
  `external_id` values for portable correlation.
- Sequence is a `worldgraph_sequence` taxonomy term, not a CPT. Its JSON `id`
  is persisted as term `external_id` metadata.

For example, `characters[].appearance` becomes `meta.appearance`, while
`characters[].roles` becomes terms under
`taxonomies.worldgraph_character_role`. A relationship such as
`avatar_asset: "asset_red_character_concept"` becomes a resolved Story Graph
relationship to that Asset.

### Owner and Author

Project `owner` and WordPress `post_author` are derived from the authenticated
WordPress user performing the import. User IDs are installation-specific and
must not be embedded in portable JSON.

### Redundant Relationship Declarations

The version 1.2 example deliberately declares a few relationships from both
sides so that the document is readable without reconstructing the graph. These
fields are validated and imported; they are not descriptive values that a
reader may silently ignore:

- `world.project` must equal `project.id` and becomes the World-to-Project
  relationship.
- `characters[].story_world`, `locations[].story_world`, and
  `organizations[].story_world` must equal `world.id`. They become each
  resource's World relationship and the reciprocal World containment edge.
- `organizations[].members` contains Character external IDs and becomes
  Organization-to-Character membership relationships.
- `episodes[].scenes` is the Episode's ordered Scene declaration. Every listed
  Scene must name the same Episode in `scenes[].episode`; the importer persists
  both Episode containment and each Scene's Episode relationship. Explicit
  `scene_number` values retain editorial order.
- `scenes[].sequence` must equal `sequence.id`, and each Scene must also occur
  in `sequence.order`. The importer persists the taxonomy assignment;
  `sequence.order` is authoritative for Scene order.
- `shots[].sequence` must equal `sequence.id` and agree with the Shot's Scene
  membership. The importer assigns both Scene and Shot objects to the Sequence
  term.
- `assets[].project` must equal `project.id` and becomes Project-to-Asset
  membership.

Version 1.0 and 1.1 documents may omit the redundant per-record fields. Their
relationships are derived from the legacy top-level sections and
`sequence.order`.

## Import Workflow

```text
World Graph Studio JSON
      ↓
Version, shape, taxonomy, and reference validation
      ↓
CPT and Sequence taxonomy creation
      ↓
SCF and taxonomy population
      ↓
External-ID relationship resolution
      ↓
Sequence assignment and ordering
      ↓
Verification and import report
```


When overwrite is disabled, an existing entity with the same external ID is
resolved for references but is not mutated. When overwrite is enabled, the
importer applies patch semantics to optional fields: omitted keys retain their
stored value, while an explicitly empty string or `null` clears a scalar field
and an empty array clears an array-valued field or relationship. Required
identity and relationship values present in the document are always applied.
`sequence.order` is a snapshot: overwrite also removes stale Scene and Shot
assignments from that Sequence. Other Project and World container relationships
are additive: an omitted child section or omitted child record does not remove
an existing container edge.

## Canonical Export

The feature plugin can export one readable live Project as a version 1.2
document from the WordPress Export screen or the administrator-only
`GET /worldgraph/v1/export/{project_id}?format=json` compatibility route. It
uses the nearest-Project Story Graph boundary and requires exactly one directly
related Story World.

The exporter emits `project`, `world`, `characters`, `locations`, `props`,
`organizations`, `episodes`, `scenes`, `shots`, `sounds`, `assets`,
`editorial_artifacts`, and one synthetic `sequence`. Stored external IDs are
preserved. A record without one receives a deterministic, non-persisted
`worldgraph-{cpt}-{post_id}` fallback so repeated exports from the same site are
stable.

Ordering is deterministic: Episodes use `episode_number`; Scenes use Sequence
order and then `scene_number`; Shots and Sounds are grouped by Scene; remaining
entity collections use external ID order. Project visual direction, Scene look
and lighting changes, Scene camera and audio direction, and Shot camera
movement, motion direction, and exceptional generation constraints are
portable. Connections, Templates, generation jobs, WordPress users/status, the
non-existent Storyboard CPT, and other fields outside the actual importer
contract are excluded.

## Top-Level Document

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `worldgraph_version` | string | Yes for 1.2 writers | Current value is `1.2`; missing is treated as legacy input. |
| `project` | object | Yes | One Project. |
| `world` | object | Yes | One Story World. |
| `characters` | array | Yes | May be empty. |
| `locations` | array | Yes | May be empty. |
| `props` | array | Yes | May be empty. |
| `organizations` | array | No | Missing means `[]`. |
| `episodes` | array | No | Missing means `[]`. |
| `scenes` | array | Yes | May be empty for compatibility adapters. |
| `shots` | array | Yes | May be empty. |
| `sounds` | array | No | Missing means `[]`. |
| `assets` | array | No | Missing means `[]`; records are planned media metadata, not uploaded binaries. |
| `editorial_artifacts` | array | No | Missing means `[]`. |
| `sequence` | object | Yes | One Sequence taxonomy term and its ordered Scene list. |



## Project

### Target

```text
project -> worldgraph_project
```

| JSON field | Type | WordPress/API target | Notes |
| --- | --- | --- | --- |
| `id` | string | `external_id` | Required and unique. |
| `title` | string | `post_title`, `meta.project_name` | Required. |
| `project_slug` | string | `meta.project_slug` | URL-friendly identifier; legacy readers may derive it from `id`. |
| `description` | string | `post_content`, `meta.description` | Project overview. |
| `generation_prompt` | string | `meta.generation_prompt` | Optional concise Project Visual Direction applied pervasively to generated media: medium/rendering, lighting, palette, contrast, or texture rather than plot or shot action. |
| `genres` | string[] | `worldgraph_genre` terms | Taxonomy slugs. |
| `target_medium` | string | `meta.target_medium` | `film`, `short_film`, `tv_series`, `web_series`, `anime`, `animation`, `documentary`, `game`, or `other`. |
| `production_status` | string | `worldgraph_status` term | Existing taxonomy slug. |
| `start_date` | string | `meta.start_date` | ISO `YYYY-MM-DD`. |
| `end_date` | string | `meta.end_date` | ISO `YYYY-MM-DD`. |
| `team_members` | string[] | `meta.team_members`, relationships | Character external IDs. |
| `production_stage` | string | `meta.production_stage` | `concept`, `development`, `pre_production`, `production`, `post_production`, or `released`. |
| `frame_width` | number | `meta.frame_width` | Positive pixel width. |
| `frame_height` | number | `meta.frame_height` | Positive pixel height. |
| `aspect_ratio` | string | `meta.aspect_ratio` | For example `16:9`. |
| `frame_rate` | number | `meta.frame_rate` | Positive frames per second. |
| derived | current user | `meta.owner`, `post_author` | Authenticated importing user. |

The Project contains the Story World, Episodes, and planned Assets. Other
records resolve Project membership through their canonical Story Graph paths.

## Story World

### Target

```text
world -> worldgraph_world
```

| JSON field | Type | WordPress/API target | Notes |
| --- | --- | --- | --- |
| `id` | string | `external_id` | Required and unique. |
| `name` | string | `post_title`, `meta.world_name` | Required. |
| `description` | string | `post_content`, `meta.synopsis` | World synopsis. |
| `timeline` | string | `meta.timeline` | Story-world chronology. |
| `rules` | string | `meta.rules` | Physical, social, or magical rules. |
| `themes` | string | `meta.themes` | Thematic framework. |
| `geography` | string | `meta.geography` | Spatial context. |
| `references` | string | `meta.references` | Creative and production references. |
| `project` | string | `meta.project`, relationship | Project external ID; must equal the top-level Project ID. |

The World contains Characters, Locations, and Organizations.

## Characters

### Target

```text
characters[] -> worldgraph_character
```

| JSON field | Type | WordPress/API target | Notes |
| --- | --- | --- | --- |
| `id` | string | `external_id` | Required and unique. |
| `name` | string | `post_title`, `meta.display_name` | Required. |
| `description` | string | `post_content`, `meta.biography` | Biography or story function. |
| `age` | string | `meta.age` | Exact or approximate age. |
| `appearance` | string | `meta.appearance` | Visual description. |
| `personality` | string | `meta.personality` | Personality traits. |
| `motivation` | string | `meta.motivation` | Immediate and dramatic motivation. |
| `backstory` | string | `meta.backstory` | Relevant history. |
| `voice_profile` | string | `meta.voice_profile` | Performance and vocal direction. |
| `roles` | string[] | `worldgraph_character_role` terms | Slugs such as `protagonist`, `mentor`, or `antagonist`. |
| `relations` | string[] | `worldgraph_character_relation` terms | Slugs such as `family`, `ally`, or `rival`. |
| `avatar_asset` | string | `meta.avatar_asset`, relationship | Optional Asset external ID. |
| `story_world` | string | `meta.story_world`, relationship | World external ID; must equal the top-level World ID. |

Scene appearances are declared by `scenes[].characters`. A relation taxonomy
classifies a Character; it does not identify the other endpoint of a specific
Character-to-Character graph edge.

## Locations

### Target

```text
locations[] -> worldgraph_location
```

| JSON field | Type | WordPress/API target | Notes |
| --- | --- | --- | --- |
| `id` | string | `external_id` | Required and unique. |
| `name` | string | `post_title`, `meta.location_name` | Required. |
| `description` | string | `post_content`, `meta.description` | Visual and narrative description. |
| `environment_type` | string | `meta.environment_type` | `indoor`, `outdoor`, `urban`, `rural`, `fantasy`, `sci_fi`, or `abstract`. |
| `geography` | string | `meta.geography` | Geographic placement. |
| `mood` | string | `meta.mood` | Mood and atmosphere. |
| `visual_reference` | string | `meta.visual_reference`, relationship | Optional Asset external ID. |
| `story_world` | string | `meta.story_world`, relationship | World external ID; must equal the top-level World ID. |

Scene use is declared by `scenes[].location`.

## Props

### Target

```text
props[] -> worldgraph_prop
```

| JSON field | Type | WordPress/API target | Notes |
| --- | --- | --- | --- |
| `id` | string | `external_id` | Required and unique. |
| `name` | string | `post_title`, `meta.prop_name` | Required. |
| `description` | string | `post_content`, `meta.description` | Physical description. |
| `purpose` | string | `meta.purpose` | Narrative or production purpose. |
| `owner_character` | string | `meta.owner_character`, relationship | Optional Character external ID. |
| `notes` | string | `meta.notes` | Continuity and handling notes. |

Scene use is declared by `scenes[].props`.

## Organizations

### Target

```text
organizations[] -> worldgraph_org
```

The section is optional.

| JSON field | Type | WordPress/API target | Notes |
| --- | --- | --- | --- |
| `id` | string | `external_id` | Required and unique. |
| `name` | string | `post_title`, `meta.organization_name` | Required. |
| `organization_type` | string | `meta.organization_type` | Human-readable type. |
| `description` | string | `post_content`, `meta.description` | Organization overview. |
| `leadership` | string | `meta.leadership`, relationship | Optional Character external ID. |
| `goals` | string | `meta.goals` | Purpose and objectives. |
| `story_world` | string | `meta.story_world`, relationship | World external ID; must equal the top-level World ID. |
| `members` | string[] | Story Graph relationships | Character external IDs contained by the Organization; each is validated and imported. |

## Episodes

### Target

```text
episodes[] -> worldgraph_episode
```

The section is optional.

| JSON field | Type | WordPress/API target | Notes |
| --- | --- | --- | --- |
| `id` | string | `external_id` | Required and unique. |
| `episode_number` | number | `meta.episode_number`, `menu_order` | Required positive order. |
| `title` | string | `post_title`, `meta.title` | Required. |
| `synopsis` | string | `post_content`, `meta.synopsis` | Episode synopsis. |
| `production_status` | string | `worldgraph_status` term | Existing taxonomy slug. |
| `project` | string | `meta.project`, relationship | Required Project external ID; must equal the top-level Project ID. |
| `scenes` | string[] | Story Graph relationships | Ordered Scene external IDs contained by the Episode; each Scene's `episode` field must agree. |

Scene records also carry their scalar `episode` relationship. The two views
must resolve to the same Episode.

## Scenes

### Target

```text
scenes[] -> worldgraph_scene
```

| JSON field | Type | WordPress/API target | Notes |
| --- | --- | --- | --- |
| `id` | string | `external_id` | Required and unique. |
| `scene_number` | number | `meta.scene_number`, `menu_order` | Required positive editorial order. |
| `title` | string | `post_title`, `meta.title` | Required. Legacy `label` is accepted as a fallback. |
| `summary` | string | `post_content`, `meta.summary` | Concise scene summary. |
| `script_content` | string | `meta.script_content` | Full screenplay/action text; does not replace the summary. |
| `dialogue` | object[] | `meta.dialogue` | Structured rows described below. |
| `location` | string | `meta.location`, relationship | Optional Location external ID. |
| `characters` | string[] | Story Graph relationships | Character external IDs appearing in the Scene. |
| `props` | string[] | Story Graph relationships | Prop external IDs used in the Scene. |
| `time_of_day` | string | `meta.time_of_day` | `dawn`, `morning`, `midday`, `afternoon`, `dusk`, `evening`, or `night`. |
| `emotional_tone` | string | `meta.emotional_tone` | Dramatic tone. |
| `lens` | string | `meta.lens` | Optional scene-wide baseline lens or field-of-view note; a Shot lens may override it. |
| `camera_movement` | string | `meta.camera_movement` | Optional scene-wide video-camera default using the same values as Shot `camera_movement`; a Shot value may override it. |
| `generation_prompt` | string | `meta.generation_prompt` | Optional Scene Look & Lighting Changes. Project Visual Direction is the baseline; include only Scene-specific differences, which take precedence inside that Scene, without repeating story action. |
| `audio_direction` | string | `meta.audio_direction` | Optional concise Scene-wide ambience, music, and sonic palette inherited by linked Sound generation; excludes dialogue, lyrics, and individual cue events. |
| `production_notes` | string | `meta.production_notes` | Staging, continuity, or capture notes. |
| `tags` | string[] | `worldgraph_scene_tag` terms | Existing taxonomy slugs. |
| `sequence` | string | `worldgraph_sequence` term | Sequence external ID; must agree with `sequence.id` and `sequence.order`. |
| `episode` | string | `meta.episode`, relationship | Optional Episode external ID. |

### Dialogue Rows

| JSON field | Type | Stored field | Notes |
| --- | --- | --- | --- |
| `speaker` | string | `speaker` | Display name of the speaking Character. |
| `line` | string | `line` | Spoken dialogue. Legacy `text` is accepted. |
| `description` | string | `description` | Optional parenthetical or performance direction. |
| `sequence` | number | `sequence` | Positive order within the Scene; legacy input falls back to array order. |

Ordinary dialogue remains canonical Scene metadata and must not be duplicated as
Sound records. Narration, voice-over, ADR, music, ambience, and effects belong
in `sounds[]`. Scene-wide sound and music direction remains represented by those
linked Sound records; there is no second Scene sound-prose field.

## Shots

### Target

```text
shots[] -> worldgraph_shot
```

| JSON field | Type | WordPress/API target | Notes |
| --- | --- | --- | --- |
| `id` | string | `external_id` | Required and unique. |
| `shot_number` | number | `meta.shot_number`, `menu_order` | Required positive editorial order. |
| `title` | string | `post_title`, `meta.shot_name` | Human-friendly shot name; legacy `label` is a fallback. |
| `scene` | string | `meta.scene`, relationship | Required Scene external ID. |
| `sequence` | string | `worldgraph_sequence` term | Sequence external ID. |
| `type` | string | `meta.shot_type` | Canonical slug such as `wide`, `medium`, or `close_up`. |
| `camera_angle` | string | `meta.camera_angle` | Canonical slug such as `eye_level`, `low_angle`, or `high_angle`. |
| `lens` | string | `meta.lens` | Lens or field-of-view note. |
| `camera_movement` | string | `meta.camera_movement` | Optional primary video-camera behavior: `locked_off`, `handheld`, `pan_left`, `pan_right`, `tilt_up`, `tilt_down`, `push_in`, `pull_back`, `track_left`, `track_right`, `follow_subject`, `orbit_left`, `orbit_right`, `crane_up`, `crane_down`, `zoom_in`, or `zoom_out`. |
| `motion_direction` | string | `meta.motion_direction` | Optional generated-video direction describing one continuous visible action in temporal order, including pace or ambient motion when important. |
| `duration` | string | `meta.duration` | ISO 8601 duration preferred. |
| `take_number` | number | `meta.take_number` | Planned or selected take. |
| `slate_id` | string | `meta.slate_id` | Production slate identifier. |
| `description` | string | `post_content`, `meta.shot_description` | Composition and action. |
| `generation_prompt` | string | `meta.generation_prompt` | Optional exceptional generation constraints not already captured by the Shot description, framing, motion fields, or Project visual direction. |
| `editorial_notes` | string | `meta.editorial_notes` | Cut, transition, or timing notes. |

Each Shot owns one required `belongs_to` Scene relationship. The containing
Scene discovers Shots through incoming graph traversal.

## Sounds

### Target

```text
sounds[] -> worldgraph_sound
```

The section is optional. Each record is a planned soundtrack cue; any rendered
or planned media encoding is a linked audio-typed Asset.

| JSON field | Type | WordPress/API target | Notes |
| --- | --- | --- | --- |
| `id` | string | `external_id` | Required and unique. |
| `title` | string | `post_title` | Required. |
| `description` | string | `post_content` | Cue description. |
| `type` | string | `worldgraph_sound_type` term | Required taxonomy slug. |
| `production_status` | string | `worldgraph_status` term | Optional existing taxonomy slug. |
| `spoken_text` | string | `meta.spoken_text` | Narration, voice-over, or ADR copy. |
| `lyrics` | string | `meta.lyrics` | Multiline text; valid only for `music`. |
| `start_timecode` | string | `meta.start_timecode` | Project timecode convention. |
| `duration` | string | `meta.duration` | ISO 8601 duration preferred. |
| `diegetic` | string | `meta.diegetic` | `unspecified`, `diegetic`, `non_diegetic`, `internal`, or `mixed`. |
| `production_notes` | string | `meta.production_notes` | Recording, mix, or generation direction. |
| `scene` | string | `meta.scene`, relationship | Required Scene external ID. |
| `shot` | string | `meta.shot`, relationship | Optional Shot external ID; it must belong to `scene`. |
| `character` | string | `meta.character`, relationship | Optional narrator or voice Character external ID. |
| `asset` | string | `meta.asset`, relationship | Optional audio Asset external ID. |

Seeded Sound type slugs include `narration`, `voiceover`, `music`,
`sound-effect`, `ambience`, `foley`, `silence`, and `adr`.

## Planned Assets

### Target

```text
assets[] -> worldgraph_asset
```

The section is optional. It imports Story Graph Asset records and metadata; it
does not download a remote file or create a WordPress attachment. An Asset may
describe a planned result whose `storage_uri` will be fulfilled later.

| JSON field | Type | WordPress/API target | Notes |
| --- | --- | --- | --- |
| `id` | string | `external_id` | Required and unique. |
| `title` | string | `post_title`, `meta.asset_title` | Required. |
| `asset_type` | string | `worldgraph_asset_type` term | Required slug such as `image`, `character`, `environment`, `storyboard`, or `audio`. |
| `workflow_name` | string | `meta.workflow_name` | Provider-neutral source workflow. |
| `prompt` | string | `meta.prompt` | Generation or acquisition intent. |
| `model_name` | string | `meta.model_name` | Model or source system. |
| `seed` | number | `meta.seed` | Reproducibility seed. |
| `generation_parameters` | object | `meta.generation_parameters` | Normalized to canonical JSON text for SCF storage. |
| `version` | string | `meta.version` | Asset version label. |
| `status` | string | `meta.status` | `pending`, `processing`, `done`, or `error`. |
| `storage_uri` | string | `meta.storage_uri` | Planned or resolved storage location. |
| `project` | string | Story Graph relationship | Project external ID; must equal the top-level Project ID and establishes Project Asset membership. |
| `character` | string | `meta.character`, relationship | Optional Character external ID. |
| `location` | string | `meta.location`, relationship | Optional Location external ID. |
| `scene` | string | `meta.scene`, relationship | Optional Scene external ID. |

Sound `asset` references must resolve to an Asset whose `asset_type` is `audio`.
Character avatars and Location visual references must resolve
to Assets typed `image`, `character`, `environment`, `prop`, `storyboard`,
`lookbook`, or `concept-art`.

## Editorial Artifacts

### Target

```text
editorial_artifacts[] -> worldgraph_editorial
```

The section is optional. It represents planned or generated editorial records,
not an attached export file.

| JSON field | Type | WordPress/API target | Notes |
| --- | --- | --- | --- |
| `id` | string | `external_id` | Required and unique. |
| `title` | string | `post_title` | Required display title. |
| `artifact_type` | string | `meta.artifact_type` | `edl`, `timeline_metadata`, `xml`, `aaf`, `shot_list`, or `production_report`. |
| `export_format` | string | `meta.export_format` | Human- or machine-readable format label. |
| `generated_date` | string | `meta.generated_date` | ISO `YYYY-MM-DD`. |
| `source_scene` | string | `meta.source_scene`, relationship | Optional Scene external ID. |
| `source_shot` | string | `meta.source_shot`, relationship | Optional Shot external ID; must belong to `source_scene` when both are present. |
| `notes` | string | `meta.notes` | Editorial intent, review, or delivery notes. |
| `project` | string | `meta.project`, relationship | Required top-level Project external ID. |

## Sequence

### Target

```text
sequence -> worldgraph_sequence taxonomy term
```

| JSON field | Type | WordPress/API target | Notes |
| --- | --- | --- | --- |
| `id` | string | term `external_id` metadata | Required and unique; used for idempotent lookup. |
| `title` | string | term name | Required; legacy `name` is a fallback. |
| `sequence_order` | number | `worldgraph_sequence_order` term metadata | Positive order among Sequence terms. |
| `order` | string[] | term assignment plus Scene ordering metadata | Ordered Scene external IDs. |

Scenes and Shots that name the Sequence external ID are assigned to the term.
`sequence.order` is authoritative for Scene order. Shot `menu_order` and explicit
`shot_number` preserve the cut order within the Sequence.

## Required Relationship Rules

Before creating posts or terms, readers validate that:

- `world.project` resolves to `project.id`;
- Project `team_members` resolve to Characters;
- Character and Location World relationships resolve;
- Character avatars and Location visual references resolve to Assets;
- Prop owners resolve to Characters;
- Organization leadership/members resolve to Characters and its World resolves;
- Episode Project and Scene references resolve, and Scene `episode` values agree;
- Scene Location, Character, Prop, Sequence, and Episode references resolve;
- Shot Scene and Sequence references resolve;
- Sound Scene, Shot, Character, and Asset references resolve, and a referenced
  Shot belongs to the same Scene;
- audio-linked Sounds reference audio Assets;
- Asset Project, Character, Location, and Scene references resolve;
- Editorial Project, Scene, and Shot references resolve, and a referenced Shot
  belongs to its source Scene; and
- every Scene in `sequence.order` resolves and is assigned to that Sequence.

## Example Expected Totals

### Legacy 1.1 Example

```text
Projects:           1
Worlds:             1
Characters:         3
Locations:          3
Props:              3
Organizations:      0
Episodes:           0
Scenes:             3
Shots:              9
Sounds:             7
Assets:              0
Editorial Artifacts: 0
Sequences:          1 taxonomy term
```

### Full-Featured 1.2 Example

```text
Projects:            1
Worlds:              1
Characters:          4
Locations:           3
Props:               4
Organizations:       1
Episodes:            1
Scenes:              4
Shots:              12
Sounds:              9
Assets:               6
Editorial Artifacts: 1
Sequences:           1 taxonomy term
```

## Verification Checklist

- [ ] Every expected post and Sequence term resolves by external ID.
- [ ] All canonical scalar values survive sanitization and SCF persistence.
- [ ] Project, Episode, Sound, Asset, Character, and Scene taxonomies use the requested slugs.
- [ ] Project owner is the authenticated importing WordPress user.
- [ ] Explicit Scene, Shot, Sequence, Episode, and dialogue ordering is preserved.
- [ ] Full Scene script content and structured dialogue are both preserved.
- [ ] Every relationship field resolves to the intended CPT and graph slot.
- [ ] Sound cues are imported without duplicating ordinary Scene dialogue.
- [ ] The music cue retains multiline lyrics and its linked audio Asset.
- [ ] Asset generation parameter objects are normalized without data loss.
- [ ] Organization membership and Episode containment are traversable.
- [ ] The Editorial Artifact resolves its Project, source Scene, and source Shot.
- [ ] No imported entity is orphaned from the sample Story Graph.
- [ ] The import report contains the expected totals and passes verification.

## MVP Goal

Importing the full-featured example should create a complete miniature production
graph in one action: a richly described Project and World, production-ready
Characters and Locations, continuity-aware Props, an Organization and Episode,
full Scenes and dialogue, camera-specific Shots, planned Sounds and Assets,
an Editorial Artifact, and a stable ordered
Sequence. The same values must be discoverable through the World Graph Studio
REST API using their external IDs.
