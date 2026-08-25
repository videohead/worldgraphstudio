# World Graph Studio REST API Specification v1.0

> Your ideas. Your assets. No credits needed.

## Status and Scope

The REST API described here is delivered in the current repository. Its core
namespace is `worldgraph/v1`, exposed by WordPress beneath:

```text
/wp-json/worldgraph/v1/
```

This document records the implemented contract rather than a prospective API.
See [Delivery Status](Delivery_Status.md) for the release boundary and
[Deployment and Connections](Deployment_and_Connections.md) for runtime and
credential setup.

The API covers Story Graph resources, relationships, production/editorial
views, canonical JSON import/export, uploaded-story decomposition preview,
generation jobs, the AI Editor, search, and optional integrations. Final Draft
FDX is a delivered WordPress admin import workflow that normalizes into the
canonical JSON importer; it does not register a `/scripts/*` REST route. The
separate deterministic Fountain-to-FDX page has a current browser bootstrap
blocker, while the Story Import & Export plugin can accept `.fountain` as an
unstructured text source for LLM decomposition. Further lossless,
application-specific script adapters are extension opportunities rather than
part of the v1 API contract.

## Authentication and Permissions

World Graph Studio uses WordPress authentication and capabilities. Supported
deployment mechanisms include an authenticated WordPress session with REST
nonces and WordPress Application Passwords over HTTPS. Read, edit, create,
delete, generation, import, connection, and administrator operations use the
capability checks in their controller. The Search routes are the explicit
current exception and are registered without an authentication requirement.

World Graph Studio does not implement a separate OAuth, Microsoft Entra ID, or
service-account protocol. A site may add those mechanisms with a WordPress
authentication plugin, but that is an extension boundary rather than part of
this API.

Connection routes are administrator-only because `credential_reference` and
`mcp_credential_reference` may be `env://` pointers or credentials entered for
local evaluation. Treat their responses as sensitive control-plane data; do
not expose them to public clients or application logs. Suno uses the first for
SunoAPI.org REST and the second for AceData Cloud MCP; the values are distinct.

## Common Resource Contract

The primary Story Graph collection routes follow a shared pattern:

```http
GET    /wp-json/worldgraph/v1/{resource}
POST   /wp-json/worldgraph/v1/{resource}
GET    /wp-json/worldgraph/v1/{resource}/{id}
PUT    /wp-json/worldgraph/v1/{resource}/{id}
DELETE /wp-json/worldgraph/v1/{resource}/{id}
GET    /wp-json/worldgraph/v1/{resource}/{id}/graph
```

The implemented resource bases are:

| Resource | CPT key |
| --- | --- |
| `projects` | `worldgraph_project` |
| `storyworlds` | `worldgraph_world` |
| `characters` | `worldgraph_character` |
| `locations` | `worldgraph_location` |
| `props` | `worldgraph_prop` |
| `organizations` | `worldgraph_org` |
| `episodes` | `worldgraph_episode` |
| `scenes` | `worldgraph_scene` |
| `shots` | `worldgraph_shot` |
| `sounds` | `worldgraph_sound` |
| `assets` | `worldgraph_asset` |
| `editorial-artifacts` | `worldgraph_editorial` |

Collection responses support controller-specific filters. Resource responses
include the WordPress identity and lifecycle fields, SCF-backed `meta`, assigned
`taxonomies`, outgoing `relationships`, Schema.org mapping hints, featured
media, and the World Graph Studio asset gallery where applicable.

Project, Story World, Character, Location, Prop, Episode, Scene, and Shot
responses expose the optional `generation_prompt` textarea under `meta`. It is
writable through the normal create and update routes and contains
generation-specific instructions, not a replacement for the entity's
description, synopsis, script, or other detailed fields.

Every content resource response also includes a top-level, read-only
`external_id` string. This portable identifier is distinct from the numeric
WordPress `id`: the JSON importer persists it for correlation across sites,
while the resource create and update routes do not accept it as writable input.
Records created outside the importer may return an empty string.

Each entry in a content resource's outgoing `relationships` array includes
`from_external_id` and `to_external_id` alongside the existing numeric
`from_id` and `to_id`, CPT types, relationship type, metadata, and Schema.org
property hint. Either portable identifier is an empty string when its endpoint
has no stored external ID.

The `storyworlds` route and its graph lookup use the canonical
`worldgraph_world` CPT key. The legacy `worldgraph_storyworld` identifier is not
the content type returned by this API.

Project, Story World, Scene, and Character list filters include their relevant
taxonomy and relationship criteria. Scene and Shot ordering is also exposed:

```http
POST /wp-json/worldgraph/v1/scenes/reorder
POST /wp-json/worldgraph/v1/shots/reorder
```

`scenes/reorder` requires a valid `sequence_id` and `ordered_ids` containing
that Sequence's complete Scene membership exactly once. The caller needs the
Sequence taxonomy's `assign_terms` capability and `edit_post` for every Scene.
The endpoint serializes requests per Sequence, updates only each Scene's
`sequence_order` metadata, verifies every stored position, and restores all
original values (including missing or duplicate raw metadata) if a write
fails.

`shots/reorder` requires `scene_id`, the current 64-character `revision`, and
`ordered_ids` containing that Scene's complete Shot membership exactly once.
It checks `edit_post` for the Scene and every Shot, preserves the Scene's
existing project-wide editorial slots, rejects stale concurrent edits, and
rolls back previously written positions if a later update fails. An optional
`sequence_id` assigns the validated Shot set to a Sequence. Direct
`menu_order` writes on Shot create/update resources are rejected; the scoped
reorder route is the authoritative interactive ordering path.

### Public Story Display Projection

The public WordPress `wp/v2` resources for displayable Story Graph post types
also include a read-only `worldgraph_display` object. This is the presentation
adapter used by the optional headless frontend alongside SCF's native `acf`
projection; it does not create writable Story Graph fields and never requires a
server-side administrator credential for public content.

Collection entries include the display `variant`, resolved featured/gallery/
linked-Asset `media`, Project stage and production-status labels where
applicable, and the `sound_kind` distinction (`song` for a Music Sound,
otherwise `sound`). A by-ID resource request adds detail aggregates:

- Scene responses embed the Scene's visible Shots in editorial `menu_order`,
  with their display fields and resolved media. Authenticated editors also
  receive `shot_order_revision` for compare-and-swap reorder requests.
- Project responses include a published/readable-node summary from the shared
  relationship analytics engine: entity and relationship totals, density,
  isolated count, entity counts, the five most-connected visible records, and
  a deterministic `development` compass. The compass contains `phase`,
  `total_opportunities`, `has_more`, at most twelve `opportunities`, and a
  deduplicated `elements_to_develop` index. Each opportunity has a stable `id`,
  `type`, `priority`, `title`, factual `evidence`, an open `question`, a
  `suggested_entity_type`, and an optional visible `entity` reference.
  Scene exposure includes an explicit element-to-Scene edge or an explicit
  element-to-Shot edge where that Shot is canonically owned by the Scene.
  When no foundation, exposure, or Scene-context gap is present, the analyzer
  returns one `next_story_event` opportunity instead of an empty list.
- Story World responses include visible related-entity counts.

Resolved media entries have this shape:

```json
{
  "id": 42,
  "asset_id": 0,
  "url": "https://example.test/media/frame.png",
  "thumbnail_url": "https://example.test/media/frame-300x169.png",
  "alt": "Forest path at dusk",
  "title": "Forest path",
  "caption": "Scene 2 continuity frame",
  "mime_type": "image/png",
  "width": 1280,
  "height": 720,
  "intent": "prop-front-view",
  "origin": "gallery"
}
```

`asset_id` is present for a direct Asset storage URL; attachment-backed entries
use `id`. Unknown or non-HTTP(S) Asset storage schemes are not made playable.
The post's Featured Media remains first. Recognized generated Character, Prop,
Location, Shot, Scene, and Episode intents then receive their canonical recipe
order; manually curated unlabeled media preserves the stored gallery order.
Anonymous projections include published, non-password-protected related records
only. For a password-protected root Story post, the final native REST response
also clears both `acf` and `worldgraph_display` unless WordPress recognizes an
edit-context, cookie, or explicit request password. An authenticated editor can
receive only non-public related records that WordPress authorizes that user to
read.

Project completion is represented by its real production-status taxonomy and
`production_stage`. No completion percentage is inferred because the canonical
model does not define one.

### Computed Relationship Counts

Several resource controllers add computed counts under `meta`:

| Resource | Computed response fields |
| --- | --- |
| Project | `scene_count`, `character_count`, `asset_count` |
| Character | `scene_count`, `shot_count`, `asset_count` |
| Location | `scene_count` |
| Story World | `location_count`, `character_count`, `organization_count` |

These counts traverse both incoming and outgoing Story Graph edges. Project
counts also follow its Story World and Episode ownership paths, and a
Character's Shot count follows that Character's Scene participation to the
Scene's Shots. This preserves useful totals for both legacy and version 1.2
imports. Related WordPress post IDs are de-duplicated before counting, so the
same entity linked along several paths contributes once.

### Sound Validation

Sounds are planned soundtrack cues, not audio file encodings. A Sound requires
a title, exactly one `worldgraph_sound_type`, and a Scene. An optional Shot must
belong to that Scene, and an optional rendered Asset must have the Audio asset
type.

Supported list filters include `scene`, `shot`, `sound_type`,
`production_status`, and the WordPress post `status`. Ordinary screenplay
dialogue remains structured Scene metadata; the reserved `dialogue` sound-type
slug cannot be assigned.

Example create payload:

```json
{
  "title": "Forest Path Song",
  "content": "A cautious traveling theme.",
  "meta": {
    "sound_type": "music",
    "production_status": "in-development",
    "lyrics": "Stay to the path through shadow and pine.",
    "start_timecode": "00:00:00:00",
    "duration": "PT18S",
    "diegetic": "non_diegetic",
    "scene": 827,
    "shot": 913,
    "character": 0,
    "asset": 0
  }
}
```

## Story Graph

The graph controller exposes entity discovery and canonical relationship
operations:

```http
GET    /wp-json/worldgraph/v1/graph/{id}
GET    /wp-json/worldgraph/v1/graph/entities
GET    /wp-json/worldgraph/v1/graph/relationships
POST   /wp-json/worldgraph/v1/graph/relationships
DELETE /wp-json/worldgraph/v1/graph/relationships/{from_id}/{to_id}
```

The resource-specific `/{resource}/{id}/graph` routes return the same graph
context around a typed entity. Graph nodes and entity-discovery results include
their `external_id`. Relationship records carry source and target IDs/types, a
relationship type, optional metadata, and `from_external_id` /
`to_external_id`. UI and API wording uses Source for provenance and Linked for
association.

Examples of canonical semantics:

- Project `contains` Story World or Episode.
- Episode `contains` Scene.
- Shot `belongs_to` Scene.
- Character `appears_in` Scene.
- Sound `belongs_to` Scene or Shot and may link to a Character and audio Asset.
- Asset `derived_from` or `references` a Story Graph source.

## Sequences

Sequences are `worldgraph_sequence` taxonomy terms with ordering helpers:

```http
GET    /wp-json/worldgraph/v1/sequences
POST   /wp-json/worldgraph/v1/sequences
GET    /wp-json/worldgraph/v1/sequences/{id}
DELETE /wp-json/worldgraph/v1/sequences/{id}
POST   /wp-json/worldgraph/v1/sequences/reorder
POST   /wp-json/worldgraph/v1/sequences/{id}/shots
POST   /wp-json/worldgraph/v1/sequences/{id}/scenes
```

Sequence collection entries and single-Sequence responses expose
`external_id`, read from `external_id` term metadata, alongside the numeric term
`id`. Imported version 1.2 Sequences use this value for portable correlation;
Sequences created without an external ID return an empty string.

`sequences/reorder` requires `ordered_ids` to contain every existing Sequence
term exactly once and rejects duplicate, unknown, or omitted IDs. Sequence
management capability is required. A short taxonomy-wide lock serializes the
batch; each `worldgraph_sequence_order` write is verified, and all original raw
term-meta values are restored if any position cannot be saved.

Scene assignment through `sequences/{id}/scenes` snapshots both the prior
Sequence terms and raw `sequence_order` metadata before writing either. If an
order write cannot be verified, both sets of values are restored and verified.

## Story Import & Export

The default-enabled Story Import & Export feature plugin owns canonical World
Graph Studio JSON import/export, Markdown screenplay/storyboard export, and
LLM-assisted decomposition previews for supported persisted story uploads. Its
administrator-only compatibility routes are:

```http
POST /wp-json/worldgraph/v1/import/validate
POST /wp-json/worldgraph/v1/import
POST /wp-json/worldgraph/v1/import/decompose
GET  /wp-json/worldgraph/v1/export/{project_id}?format=json
GET  /wp-json/worldgraph/v1/export/{project_id}?format=screenplay
GET  /wp-json/worldgraph/v1/export/{project_id}?format=storyboard
```

`/import/validate` performs a dry run. `/import` accepts the JSON document and
an optional `overwrite` boolean. The importer validates cross-references,
creates or updates supported Story Graph entities, assigns taxonomies, builds
relationships, and reports resolved counts. Canonical JSON uses these routes
and the WordPress Import screen without an LLM.

`/import/decompose` is preview-only. It accepts an `attachment_id` for a real
WordPress attachment stored inside the uploads tree. A `connection_id` for a
manageable LLM Connection is required for a non-canonical source and may be
omitted for canonical World Graph Studio JSON. The current source extractor
accepts JSON, TXT, Markdown, Fountain, RTF, PDF, EPUB, DOCX, and ODT files up
to 20 MB. PDFs must
have an extractable text layer; encrypted PDFs are rejected, and scanned or
image-only PDFs return an OCR-required error. Non-canonical sources are sent
only to the selected OpenAI-compatible, OpenAI, or Anthropic Connection. The
server normalizes and dry-run validates the candidate before returning. An
abbreviated response shape is:

```json
{
  "success": true,
  "document": { "worldgraph_version": "1.2" },
  "json": "{\n  \"worldgraph_version\": \"1.2\"\n}",
  "source": {
    "attachment_id": 123,
    "filename": "story.pdf",
    "format": "pdf",
    "characters": 48210
  },
  "decomposition": {
    "generated": true,
    "attempts": 1,
    "tokens": 9210,
    "backend": "openai_compatible",
    "model": "configured-model",
    "connection_id": 45,
    "chunks": 1
  }
}
```

For non-canonical sources, the response never contains the original extracted
manuscript. No response contains the endpoint, credential reference, or
resolved credential. A client must show the derived candidate for review and
submit the confirmed `json` value to `POST /import`; decomposition alone never
writes Story Graph records. The WordPress admin flow applies the same
preview/confirm boundary and keeps the original source as a Media Library
attachment after confirmation or cancellation.

`/export/{project_id}` requires a readable `worldgraph_project` and accepts
`json`, `screenplay`, or `storyboard`. Its response includes `project_id`,
`format`, suggested `filename`, `mime_type`, and `content`. JSON `content` is a
canonical version 1.2 object; Markdown `content` is a string. Canonical JSON
contains the supported live Project graph and a synthetic Sequence, with
deterministic ordering and stable stored external IDs or deterministic fallback
IDs. Connections, Templates, generation jobs, WordPress users/status, and
fields outside the importer contract are excluded.

Import and decomposition require `manage_options`. Decomposition additionally
requires permission to read the attachment and, when a Connection is supplied,
manage that selected Connection.
Export requires `manage_options` plus `read_post` for the named Project.
Successful decomposition and export payloads carry no-store headers because
they can contain private project material. Provider and credential failures
are normalized before they are returned.

There is no `/scripts/import` or `/scripts/export` alias in v1. Clients use the
routes above.

The bundled Final Draft FDX integration runs through a capability- and nonce-
protected WordPress admin action. It parses locally in the browser, normalizes
supported screenplay structure into the World Graph Studio JSON contract, and
delegates persistence to the importer above. The separate deterministic
Fountain source targets the same pattern but is currently bootstrap-blocked.
Neither adds REST routes.

Lossless Fade In, Highland, Story Architect, format-specific merge, and
professional screenplay-file export routes are not registered in v1.
Consumers must not depend on `/scripts/*` paths; future adapters should
document their own route contracts.

## Generation

Generation uses an active `worldgraph_template` paired with an available
`worldgraph_conn`. WordPress creates an internal generation record, schedules a
bounded WP-Cron batch, invokes the matching adapter, polls asynchronous jobs,
imports returned media or retains normalized text results, and records
provenance.

```http
POST /wp-json/worldgraph/v1/generation
POST /wp-json/worldgraph/v1/generation/suno-callback
GET  /wp-json/worldgraph/v1/generation/{id}
POST /wp-json/worldgraph/v1/generation/{id}/cancel
GET  /wp-json/worldgraph/v1/generation/asset/{asset_id}/history
GET  /wp-json/worldgraph/v1/generation/templates/{id}/requirements
```

The `POST /generation` payload contains an output `type`, prompt,
Template/workflow reference, parameters, and optional target Asset and bound
inputs. The controller accepts `image`, `video`, `audio`, and `text` type values,
but the Template and Connection must name the same registered provider adapter
and the requested output must match an available Template modality.
`params` remains the backward-compatible provider parameter map. New clients
may send Template-advertised scalar overrides in `run_values`; those values are
validated against the resolved Template and win over colliding legacy
parameters. Server-resolved Template media bindings sit beneath explicit
`inputs`, and required image/start-frame inputs are authorized and checked
before the job is queued. A local ComfyUI Template runs by its WordPress
Template ID and therefore does not require a cloud provider-template ID.

The editor-facing story-aware image/video workflow is also available through:

```http
GET  /wp-json/worldgraph/v1/assets/generate/prompt
POST /wp-json/worldgraph/v1/assets/generate
```

The prompt response exposes every directly selectable recipe output in the
ordered `actions` array. Each action includes its `type`, `intent`, label,
read-only composed prompt, featured behavior, readiness, and resolved default
Template. This preserves all six same-type look-development actions for a
Character, Prop, or Location and both still/video actions for a Shot. The
legacy `outputs.image` and `outputs.video` keys remain as first-of-type aliases
for older clients. Entries in `templates`, `image_templates`, and
`video_templates` include each Template's sanitized, provider-neutral
run-control contract:

```json
{
  "id": 101,
  "name": "Cinematic still",
  "run_controls": {
    "version": 1,
    "fingerprint": "opaque-deterministic-sha256-value",
    "fields": [
      {
        "key": "steps",
        "label": "Steps",
        "type": "integer",
        "default": 28,
        "min": 1,
        "max": 100,
        "step": 1
      }
    ]
  }
}
```

`fields` is ordered. A field always has `key`, `label`, and a UI-native `type`:
`string`, `textarea`, `integer`, `number`, `boolean`, or `select`. It may include
`default`, `required`, `min`, `max`, `step`, a rendering group, a bounded
`description`, and—for `select`—an `options` array of `{ "value": scalar,
"label": string }` objects. Submitted values remain scalar. The server omits
unsafe or unsupported provider schema details, including node IDs, binding
paths, model paths, and nested objects. `fingerprint` identifies the effective
v1 field definition for client cache/form invalidation; it is opaque, need not
be echoed in a request, and is not trusted as proof that a value is valid. The
metabox keeps a single-output provider prompt collapsed and uses a separate
blank field for one-off author instructions.

Direct generation accepts `type: "image"` (the backward-compatible default) or
`type: "video"`, an intent returned by the prompt route, a matching
`template_id`, optional `prompt` text, and an optional `run_values` object. That
optional prompt is additive: the server appends it to saved Story Graph/SCF
context rather than replacing the composed prompt. `run_values` is keyed by the
selected Template's advertised `run_controls.fields[].key`; values must be
scalar and match the advertised type, bounds, and select options. For example:

```json
{
  "post_id": 42,
  "type": "image",
  "intent": "shot-representative-still",
  "template_id": 101,
  "prompt": "Use a low camera position.",
  "run_values": {
    "negative_prompt": "logo, watermark",
    "steps": 32,
    "seed": 873645
  }
}
```

WordPress selects the Template, re-derives its run-control contract, and
normalizes the submitted object before creating a job. Unknown fields, nested
arrays or objects, wrong scalar types, out-of-range numbers, and values outside
advertised select `options` fail validation rather than being forwarded to a
provider. The server applies compatible output framing from the owning Project
before the submitted overrides; sampling and negative-conditioning defaults
remain owned by the Template.
Omitting `seed` means no fixed-seed override and preserves the Template or
provider's existing randomization behavior; an explicitly submitted integer
`0` remains a valid fixed seed. An omitted or empty `run_values` object uses
compatible Project framing and Template/provider defaults. Direct video is
defined by the Shot recipe; Project-wide Shot videos use the durable batch
route.

Story-aware representative-media planning and durable batches use:

```http
GET  /wp-json/worldgraph/v1/assets/generate/plan?post_id={id}&scope={item|project|demonstration}
POST /wp-json/worldgraph/v1/assets/generate/batches
GET  /wp-json/worldgraph/v1/assets/generate/batches/{id}
POST /wp-json/worldgraph/v1/assets/generate/batches/{id}/cancel
```

`scope=item` plans the default representative outputs for one supported Story
Graph item. `scope=project` requires a Project and traverses its canonical
ownership graph to plan the Project plus supported World, Character, Prop,
Location, Episode, Scene, and Shot descendants. `scope=demonstration` also
requires a Project and plans an editorially ordered, dependency-aware
whole-story pass plus rough-cut assembly. Planning is read-only and does not
reserve provider capacity or spend provider budget. Plan and start require
`edit_post` for the root, `upload_files`, and permission for every expanded
source. Batch status and cancellation require `upload_files` and are limited to
the original requester or a user who can edit the root.

The plan response includes `workflow`, `sources`, `total_jobs`, image/video/audio
`counts`, and a `tasks` array. Each task identifies its source, workflow,
creative `intent`, output `type`, featured-image behavior, whether it is
`optional`, its `generation_required` flag, phase and dependencies,
fallback key, and `prompt_hash`; long provider prompts are intentionally
omitted from expanded plan lists. It also returns `ready`, required Template
`blockers`, `optional_unavailable`, and the Templates runnable across the plan
as `image_templates`, `video_templates`, and `audio_templates`, including the
same sanitized `run_controls` object on each Template, resolved
`default_template_ids`, and `latest_batch` when one exists. Existing linked
media can satisfy a task with `generation_required: false`, so it does not need
a generation Template.

Starting a batch accepts:

```json
{
  "post_id": 42,
  "scope": "demonstration",
  "base_prompt": "",
  "image_template_id": 101,
  "video_template_id": 202,
  "audio_template_id": 303,
  "image_run_values": {
    "steps": 32,
    "seed": 873645
  },
  "video_run_values": {
    "duration": 6,
    "fps": 24
  },
  "audio_run_values": {
    "duration": 6
  },
  "idempotency_key": "client-operation-uuid"
}
```

`base_prompt` is optional additional author direction. It applies to the item,
or to every source in Project or demonstration scope; saved CPT/SCF context and
each source's `generation_prompt` remain in the final prompt. The image, video,
and audio Template IDs are optional explicit overrides shared by generated
outputs of that type; without them, the server applies the registered
preference and fallback cascade. `audio_template_id` does not replace a linked
Sound Asset already selected as an assembly input.
`image_run_values`, `video_run_values`, and `audio_run_values` are optional
scalar objects shared by generated tasks of the matching output type. A
non-empty object requires the corresponding explicit per-type Template ID; the
server re-derives and validates that one explicitly selected Template contract
before freezing the values.

This prevents one map from being interpreted against different per-item
fallback Templates. Media inputs are not accepted in these objects. Required
image or start-frame inputs for image-to-video and text-plus-image-to-video
Templates continue to resolve through Template bindings. Checkpoint/model,
VAE, and CLIP file selection remains Template-authoring configuration and is
not a per-run REST input.

`idempotency_key` is required and must be non-empty. It is scoped to the
requester and root post; repeating it returns the existing batch instead of
duplicating work. WordPress atomically reserves the key while the parent is
committed and fingerprints scope, additive prompt, Template overrides, and
normalized image/video/audio run values, so a concurrent retry cannot create a
duplicate paid batch or reuse the key for different settings. The normalized
values are frozen into every affected task in the durable batch plan and later
copied into its child job; later form or catalog refreshes cannot mutate an
already accepted batch's values. Starting fails before any child is queued if
a required generated task lacks a runnable Template, a submitted value is
invalid, or the requester cannot edit every source. Optional demonstration
motion or audio without a runnable Template is frozen as unavailable fallback
work rather than promoted to a hard blocker. A successful start returns
`202 Accepted` and a `Location` header for the batch status route. Omitting all
three per-type run-value objects preserves Template/provider defaults.

The version 2 demonstration snapshot freezes stable task keys, required versus
optional behavior, reference/audio/video phases, dependencies, symbolic media
references, preferred modalities, fallbacks, prompts, Templates, run values, and
the editorial assembly timeline. A Character reference gives
character-conditioned I2V precedence, with recurring Characters ordered first;
otherwise a compatible moving task uses
the current and following Shot stills for first/last-frame video when possible,
then still-conditioned I2V or text-to-video. Linked or completed generated
audio is mixed when usable. Missing optional motion or audio becomes a skipped
enhancement and the frozen timeline uses still cards, subtitle/title text, and
silence. Stories without Shots can use Scene or Project still cards. The API
does not claim that provider outputs are artistically final or that FFmpeg and
provider models are installed on every deployment.

Batch status includes `batch_id`, `batch_kind` (`representative_media` or
`demonstration_video`), root `post_id`, `scope`, aggregate `status`, planned
`total`, `materialized`, `remaining`, `active`, `completed`, `failed`,
`skipped`, `cancelled`, `progress_percent`, per-state `counts`, creation time,
any batch error, and `assembly`. Up to 200 child `jobs` are included inline;
`jobs_truncated` indicates that more exist. Each job reports its step, stable
task key when applicable, source, intent, output type, status, attachment ID,
and error. A skipped child is terminal and records optional work that used a
fallback rather than leaving the batch active forever.

Aggregate `status` is `pending`, `active`, `cancelling`, `cancelled`, `failed`,
`completed`, or `completed_with_errors`.

Before assembly starts, `assembly` can be empty. While the independent,
resumable FFmpeg worker is running it contains a pending DTO such as:

```json
{
  "status": "pending",
  "batch_id": 9001,
  "stage": "normalize",
  "completed_steps": 3,
  "total_steps": 16,
  "progress": 18,
  "progress_percent": 18,
  "message": "Normalized 3 of 12 planned segments."
}
```

The pending stages are `normalize`, `concat`, `subtitle`, `audio`, `silence`,
and `import`. Assembly runs on a separate WP-Cron hook, checkpoints signed
batch state and a verified batch-specific temporary directory, and advances a
bounded stage or segment attempt per tick. Aggregate batch progress remains
below 100 while assembly is waiting or active.

Successful assembly returns an imported rough-cut description under
`assembly`; representative fields are:

```json
{
  "status": "completed",
  "batch_id": 9001,
  "batch_kind": "demonstration_video",
  "project_id": 42,
  "attachment_id": 1201,
  "srt_attachment_id": 1202,
  "url": "https://example.test/wp-content/uploads/project-rough-cut.mp4",
  "burned_subtitles": false,
  "sidecar_srt": true,
  "segments": 12,
  "audio_cues": 4,
  "width": 1920,
  "height": 1080,
  "fps": 24,
  "duration": 72,
  "warnings": []
}
```

If assembly cannot complete, completed child media remains available, the
aggregate status becomes `completed_with_errors`, and `assembly` has the
failure contract:

```json
{
  "status": "failed",
  "code": "worldgraph_rough_cut_ffmpeg_unavailable",
  "error": "FFmpeg is unavailable, so the rough cut could not be assembled.",
  "data": {
    "binary": "ffmpeg",
    "status": 503,
    "diagnostic": "FFmpeg could not be started."
  }
}
```

Cancellation publishes its parent marker before child transitions, and
materialization/activation recheck it on every step. It changes children still
`staged`, `queued`, or claimed as `submitting` before the worker's atomic
`dispatching` boundary; unmaterialized planned work contributes to the
cancelled aggregate. It also prevents a new demonstration assembly stage from
starting. Work that crossed the provider-dispatch boundary continues
reconciling, polling, and importing because this endpoint cannot reliably
revoke paid work across every adapter. The response adds `stopped_queued` and
a `cancel_note` to the refreshed aggregate status; cancelling a terminal batch
is a no-op reported in that note.

Media imported by these routes is named
`{project_slug|project-wp-slug}-{cpt-type}-{source-slug?}-{intent?}-job-{job_id}.{ext}`;
the non-job synchronous fallback uses a UTC timestamp. Attachment titles mirror
the readable Project, CPT type, source, and intent or media type.

Generated video and URL-based audio downloads stream into bounded temporary
files before validation and Media Library import. Image responses and local
ComfyUI media inputs also have explicit byte limits, and a completed job must
import an attachment matching its requested image/video/audio output type.

Delivered execution adapters are Comfy Cloud MCP, local ComfyUI HTTP workflows,
fal MCP, ElevenLabs, Suno through SunoAPI.org REST and AceData Cloud MCP,
VideoDraft MCP, and OpenRouter video generation REST.
The Suno callback route is public because the provider calls it, but an HMAC
query token binds it to one Suno Connection. It only schedules an authenticated
poll; the worker still retrieves canonical status and imports every final track
before completing the job. The built-in catalogs provision text-to-image,
ElevenLabs audio, and Suno music/lyrics Templates. Additional output modalities
need an adapter that registers and executes a compatible Template; a provider
value without that implementation is configuration metadata only.

See [Suno Integration](plugins/SUNO.md) for the transport-specific Template,
callback, polling, credential, and result contracts.

## Connections

```http
GET    /wp-json/worldgraph/v1/connections
POST   /wp-json/worldgraph/v1/connections
POST   /wp-json/worldgraph/v1/connections/sync
GET    /wp-json/worldgraph/v1/connections/{id}
PUT    /wp-json/worldgraph/v1/connections/{id}
DELETE /wp-json/worldgraph/v1/connections/{id}
GET    /wp-json/worldgraph/v1/connections/{id}/resolve
POST   /wp-json/worldgraph/v1/connections/{id}/test
GET    /wp-json/worldgraph/v1/connections/{id}/catalog
POST   /wp-json/worldgraph/v1/connections/{id}/catalog/sync
POST   /wp-json/worldgraph/v1/connections/{id}/catalog/prepare
POST   /wp-json/worldgraph/v1/connections/{id}/catalog/entries/{entry_id}/enable
POST   /wp-json/worldgraph/v1/connections/{id}/catalog/entries/{entry_id}/disable
POST   /wp-json/worldgraph/v1/connections/{id}/catalog/entries/{entry_id}/materialize
POST   /wp-json/worldgraph/v1/connections/{id}/catalog/entries/{entry_id}/download
```

Connection status is the configured-startup and new-work authority for provider
adapters; an explicit administrator test may still load a disabled adapter for
diagnosis.
`resolve` reports the normalized Connection configuration, including its
sensitive credential fields as empty values or a fixed mask; trusted
server-side adapters receive the underlying value. `test` dispatches the
manifest-declared provider readiness callback; `sync` refreshes the fixed local
provider-capability descriptor. The manifest provisioner is automatically
scheduled by the common save lifecycle; a provider's test callback may invoke
it when Templates are part of readiness. The `/catalog*` routes are the
administrator-only, ComfyUI-specific workflow catalog surface and are not a
generic manifest-provisioning API.

The adapter registry is extensible through the `worldgraph_conn_adapters`
filter. An integration can contribute provider metadata, a callable loader,
optional initialization, Connection lifecycle callbacks, Template provisioning,
generation-client selection, and guided setup choices without changing the
Connection resource contract. Plugin-relative `files` are resolved inside the
main World Graph Studio plugin and are intended for bundled implementations;
external plugins should use a callable loader. See
[Adding Connections and Templates](Adding_Connections_and_Templates.md) for the
portable manifest schema and callback contracts.

## AI Editor and Advisors

The Gutenberg AI Editor exposes permission-aware routes under the core
namespace:

```http
POST /wp-json/worldgraph/v1/ai/chat
POST /wp-json/worldgraph/v1/ai/analyze
POST /wp-json/worldgraph/v1/ai/generate
POST /wp-json/worldgraph/v1/ai/continuity
GET  /wp-json/worldgraph/v1/ai/context
GET  /wp-json/worldgraph/v1/ai/agents
GET  /wp-json/worldgraph/v1/ai/settings
GET  /wp-json/worldgraph/v1/ai/health
```

The plugin also registers an older record-oriented route shape:

```http
GET|POST        /wp-json/worldgraph/v1/agents
GET|PUT|DELETE  /wp-json/worldgraph/v1/agents/{id}
POST            /wp-json/worldgraph/v1/agents/{id}/actions
GET             /wp-json/worldgraph/v1/agents/{id}/history
```

Those record routes expect a `worldgraph_agent` post type, which is not part of
the current 15-type Story Graph registration. They are retained implementation
surface, not a supported advisor-record API. Current clients should discover
the `.agent.md` advisor profiles through `GET /worldgraph/v1/ai/agents` and use
the `/ai/*` routes above.

The current bundle contains more than 50 specialist profiles. WordPress scans
the plugin-owned agent directory at runtime, so adding another focused profile
does not require a new REST route, data model, or execution service.

An LLM connection is optional for the Story Graph itself but required for
routes that request model output.

## Search

```http
POST /wp-json/worldgraph/v1/search
GET  /wp-json/worldgraph/v1/search/suggest
```

Search accepts a query, optional entity-type filters, mode, and result limit.
The current `semantic` mode uses the same WordPress-backed retrieval as keyword
mode; no vector-store integration is registered. Both Search routes are public.
Anonymous requests receive published records. Authenticated editors may also
receive non-public lifecycle states that WordPress permits them to read.

## Production and Editorial Views

Project-scoped production endpoints expose the delivered planning model:

```http
GET  /wp-json/worldgraph/v1/production/{project_id}/overview
GET  /wp-json/worldgraph/v1/production/{project_id}/pipeline
PUT  /wp-json/worldgraph/v1/production/{project_id}/stage
GET  /wp-json/worldgraph/v1/production/{project_id}/tasks
POST /wp-json/worldgraph/v1/production/{project_id}/tasks
PUT  /wp-json/worldgraph/v1/production/tasks/{task_id}/status
GET  /wp-json/worldgraph/v1/production/{project_id}/timeline
```

Project-scoped editorial routes provide views, records, export data, reviews,
and storyboards:

```http
GET  /wp-json/worldgraph/v1/editorial/{project_id}/overview
GET  /wp-json/worldgraph/v1/editorial/{project_id}/artifacts
POST /wp-json/worldgraph/v1/editorial/{project_id}/artifacts
POST /wp-json/worldgraph/v1/editorial/{project_id}/export
GET  /wp-json/worldgraph/v1/editorial/{project_id}/reviews
POST /wp-json/worldgraph/v1/editorial/{project_id}/reviews
GET  /wp-json/worldgraph/v1/editorial/{project_id}/storyboard
```

The optional EDL plugin contains CMX 3600 and SMPTE 436m XML PHP parsing,
timecode, and formatting functions plus a nonce- and capability-protected admin
scaffold. The admin page references missing assets and its AJAX action contract
conflicts; its export resolver also uses fixed sample clips, and confirmed
previews persist no Story Graph timeline records. The admin workflow is not
delivered, and the plugin does not register the speculative
`/editorial/edl/generate` REST path.

## Celtx Connector Routes (Scaffold)

When the bundled Celtx plugin is enabled and configured, it registers these
administrator-only routes in the core namespace:

```http
GET    /wp-json/worldgraph/v1/celtx/test
GET    /wp-json/worldgraph/v1/celtx/sync
POST   /wp-json/worldgraph/v1/celtx/sync
POST   /wp-json/worldgraph/v1/celtx/sync/{type}
POST   /wp-json/worldgraph/v1/celtx/sync/{type}/{id}
GET    /wp-json/worldgraph/v1/celtx/mapping/{type}/{id}
DELETE /wp-json/worldgraph/v1/celtx/unsync/{type}/{id}
```

The routes target outbound Project, Character, Location, Scene, and Shot work
and persistent `_worldgraph_celtx_mapping` metadata. Current response handling
and Scene-call defects block verified synchronization; the source also does not
implement remote-to-WordPress reconciliation.

## Optional VideoDraft Synchronization

When VideoDraft Sync is enabled and a `videodraft` Connection is selected, it
registers these administrator-only routes:

```http
GET    /wp-json/worldgraph/v1/videodraft/projects
GET    /wp-json/worldgraph/v1/videodraft/schema
POST   /wp-json/worldgraph/v1/videodraft/push
POST   /wp-json/worldgraph/v1/videodraft/pull
GET    /wp-json/worldgraph/v1/videodraft/mapping/{project_id}
DELETE /wp-json/worldgraph/v1/videodraft/mapping/{project_id}
```

Push accepts `project_id`, optional `connection_id`, optional
`remote_project_id`, and `force`. Pull accepts `remote_project_id`, optional
`connection_id`, `force`, and `dry_run`; `dry_run` defaults to `true`. Existing
remote Projects are checkpointed before update. Mapping state is stored in
`_worldgraph_videodraft_mapping` and credentials remain on the Connection.

The routes map the shared Project/script/storyboard/visual-asset subset rather
than promising lossless VideoDraft production-timeline interchange. See
[VideoDraft Connection and Sync](plugins/VIDEODRAFT.md).

## Google Web Stories Extension Prototype

The repository contains prototype source for a separate
`worldgraph-web-stories/v1` namespace:

```http
POST /wp-json/worldgraph-web-stories/v1/sync/story/{story_id}
POST /wp-json/worldgraph-web-stories/v1/sync/scene/{scene_id}
POST /wp-json/worldgraph-web-stories/v1/sync/all
GET  /wp-json/worldgraph-web-stories/v1/mapping/{post_id}
GET  /wp-json/worldgraph-web-stories/v1/status
GET  /wp-json/worldgraph-web-stories/v1/settings
POST /wp-json/worldgraph-web-stories/v1/settings
```

These paths document the prototype controller surface, not routes delivered by
the active World Graph Studio plugin. The main plugin does not load or register
the Web Stories package, and the package's current bootstrap and settings
paths are not production-ready. Clients must not depend on bidirectional sync,
automatic sync, storyboard-page sync, or an admin sync dashboard unless an
extension first completes and activates that integration.

## Errors and Versioning

Controllers return standard WordPress REST errors. A typical error has this
shape:

```json
{
  "code": "worldgraph_sound_scene_required",
  "message": "A Sound must belong to a Scene.",
  "data": { "status": 400 }
}
```

The stable current compatibility namespace is `worldgraph/v1`. It remains
supported for existing clients, but it is not the canonical product model or
the automatic contract for new headless work. Extensions should use their own
namespace when they do not implement the core contract, and clients should
feature-detect optional integration routes rather than assume they are active.
