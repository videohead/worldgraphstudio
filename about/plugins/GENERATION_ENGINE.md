# World Graph Studio Generation Engine

> **Delivery status:** complete for the current release. See
> [Delivery Status](../Delivery_Status.md) for the repository-wide status
> contract. Provider availability, credentials, installed models, and network
> access are deployment conditions rather than unfinished application work.

## Purpose

The Generation Engine connects Story Graph content to configured media
providers. WordPress remains the control plane and source of truth for:

- provider Connections;
- reusable generation Templates;
- provider-neutral representative-media and demonstration recipes and intents;
- read-only plans, durable batches, and queued generation records;
- prompts, parameters, source-post links, and provider provenance;
- imported WordPress media, retained text results, and linked Asset records; and
- permissions, nonces, cancellation, status, and operator logs.

No separate Python orchestrator or application queue is required. Long-running
work is submitted and polled by WP-Cron.

```text
Story Graph item or Project
              |
              v
 representative or demonstration plan
              |
              v
 active Template + Connection per output
              |
              v
 durable batch + child worldgraph_gen records
              |
              v
         WP-Cron batch worker
              |
              v
 ComfyUI / fal / ElevenLabs / Suno / VideoDraft / OpenRouter adapter
              |
              v
 WordPress attachments / text results + provenance
              |
              v
 optional FFmpeg demonstration rough cut
```

## Current generation shapes

`WorldGraph\Utils\Generation_Modality` is the canonical registry. The current
release registers these modalities:

| Modality | Output | Required input | Optional input | Primary adapter |
| --- | --- | --- | --- | --- |
| `text_to_image` | image | `prompt` | `negative_prompt` | ComfyUI or a compatible fal Template |
| `image_to_image` | image | `image` | `prompt`, `negative_prompt` | Compatible ComfyUI or VideoDraft Template |
| `image_text_to_image` | image | `image`, `prompt` | `negative_prompt` | Compatible ComfyUI or VideoDraft Template |
| `text_to_video` | video | `prompt` | `negative_prompt` | VideoDraft or OpenRouter |
| `text_image_to_video` | video | `prompt`, `image` | `negative_prompt` | VideoDraft or OpenRouter |
| `video_to_video` | video | `start_frame` | `prompt`, `negative_prompt`, `end_frame` | Compatible ComfyUI Template |
| `video_with_audio` | video | `audio` | `prompt`, `negative_prompt`, `video` | VideoDraft |
| `text_to_speech` | audio | `prompt` | none | ElevenLabs |
| `text_to_dialogue` | audio | `prompt` | none | ElevenLabs |
| `text_to_sound_effect` | audio | `prompt` | none | ElevenLabs |
| `text_to_music` | audio | `prompt` | none | ElevenLabs or Suno |
| `text_to_voice` | audio | `prompt` | none | ElevenLabs voice design |
| `text_to_lyrics` | text | `prompt` | none | Suno |

The media-import boundary can accept image, video, and audio provider results,
and Assets can describe broader media. That storage capability does not make
every media shape a registered generation modality. Custom or future adapters
must register and validate their own executable contract rather than relying on
unused modality names.

The story-post **World Graph Studio Assets** metabox exposes an explicit direct
still-image/text-to-video selector and the Story Graph item's
representative-media plan. Direct video is available for a Shot, whose plan can
therefore require both an image-output Template and a video-output Template.
The generic generation REST endpoint accepts `image`,
`video`, `audio`, or `text` as a requested result type, but the selected active
Template and provider adapter still determine whether a request can run.
On a Project, the same editor also exposes **Demonstration**, which previews and
queues a dependency-aware whole-story pass with image, video, and audio tasks
and still, subtitle, and silence fallbacks. Demonstration provides separate
image and video Template selectors and shows an audio Template selector only
when its plan contains audio that must be generated. Each may remain configured
per item (`0`) or override generated tasks of its type; direct image/video runs
do not expose audio controls.

## Story-aware prompts and representative workflows

Project, Story World, Character, Prop, Location, Shot, Scene, and Episode have
an optional `generation_prompt` SCF textarea for media-specific instructions.
When no author-edited base prompt is supplied, the prompt composer combines:

1. for video, a motion-first direction and the selected output intent's
   creative objective;
2. the content type and title, then bounded inherited ancestor context;
3. non-duplicate excerpt and post content;
4. labeled, type-appropriate SCF details, including long-form descriptions,
   appearance, world rules, scripts, dialogue, production notes, and camera
   details where applicable;
5. dependent Story Graph context for Scene and Episode filmstrips;
6. for non-video outputs, the selected output intent's creative objective;
7. `generation_prompt`;
8. optional `base_prompt` text labeled **Additional request instructions**; and
9. output-specific continuity, detail, and no-watermark constraints.

Markup is removed, select values and relationship titles are made readable,
and the final provider prompt has a global 2,400-word bound. `base_prompt` adds
author direction without removing saved Story Graph context or
`generation_prompt`; in Project scope it is appended to every source prompt.
The `worldgraph_generate_asset_prompt` filter runs last and receives the prompt,
source post, and intent.

Template selection happens after this base composition. For a video Template,
`Generation_Prompt_Profiles` then prepends one idempotent family-specific
positive-prompt opening. Wan uses explicit camera/subject motion, temporal
progression, a cinematic-lighting anchor, and stable identity. LTX uses one
continuous chronological action description covering subject, environment,
camera, lighting, and color through the final frame; audio cues are recommended
only for workflows that generate audio. Negative-prompt notes remain separate
guidance. Prompt profiles do not override workflow model files or sampling,
resolution, frame-rate, CFG, denoise, or motion values.

For the official WAN 2.2 14B first/last-frame quality graph, preserve the
authored two-expert bundle: no Lightning LoRAs, shift 8, 20 steps, CFG 4,
Euler/simple, a high-noise pass over steps 0-10, then low-noise from 10 to the
end. Its default 81 frames at 16 fps produce about five seconds; Project profile
projection does not replace that fps while the graph exposes a fixed frame
count. Other WAN variants keep their own graph-authored values. The documented
LTX operator presets are Balanced (DPM++ 2M/Karras, 24, 4.5, 0.60, 0.55,
1920x1080/50 fps), Hero (DPM++ 2M/Karras, 36-40, 4.2, 0.55, 0.45,
2560x1440/50 fps), and Preview (UniPC, 16, 4.0, 0.65, 0.50,
768x432/24 fps), where the numbers after steps are CFG, denoise, and motion.
They are not enforced defaults: use them only when the saved graph exposes
equivalent nodes. Official LTX distilled/custom sampling remains authoritative,
raw latent dimensions must be divisible by 32, and literal frame counts must be
`8n + 1`. See [Text to Video with Local ComfyUI](../how-to-text-to-video.md).

`WorldGraph\Utils\Generation_Workflows` defines the delivered representative
recipes:

| Content type | Workflow | Outputs |
| --- | --- | --- |
| Project | `project-key-art` | `project-key-art` image |
| Story World | `world-key-art` | `world-key-art` image |
| Character | `character-look-set` | full, front, three-quarter, profile, back, and close-up images |
| Prop | `prop-look-set` | full, front, three-quarter, profile, back, and close-up images |
| Location | `location-look-set` | full establishing, front, three-quarter, profile, back, and detail close-up images |
| Shot | `shot-still-and-video` | `shot-representative-still` image and `shot-video` video |
| Scene | `scene-filmstrip` | one filmstrip image informed by ordered Shot descriptions |
| Episode | `episode-bookend-filmstrip` | one filmstrip image informed by the opening and final Scenes |

Each output is independently queued and records its stable intent slug. An item
plan contains one content item's recipe. Project scope walks canonical ownership
edges from the Project and plans every supported descendant once. The default
maximum is 5,000 child tasks, filterable with
`worldgraph_generation_batch_max_tasks`.

The separate Project-only `demonstration` scope orders Episodes, Scenes, and
Shots into a frozen editorial timeline. It requires reusable still references
for story-occurring Characters and referenced Locations and a durable Shot
still (or Scene or Project card when there are no Shots). Moving Shots and
generated Sound cues are optional enhancements. A moving task prefers
first-frame/last-frame video when the next Shot still exists, then
Shot-still-conditioned I2V, then text-to-video. Recurring Characters remain
ordered first for reusable reference generation. Its symbolic media references
identify the current Shot still as `start_frame`, the following Shot still as
`end_frame`, and the current Shot still as the primary I2V `image`. Character
and Location reference tasks remain available for editorial review and future
reference-conditioned still workflows. Scene-ending Shots have no following
endpoint, so they use the start-image or text fallback rather than forcing an
FLF graph. The adjacent Shot still is suitable for a rough transition; a final
production shot should instead use a deliberately authored matching end
keyframe when it must not morph toward the next composition.

## Connections and provider adapters

A `worldgraph_conn` post binds a provider type to an environment, endpoints,
credential value or reference, model restrictions, status, and optional limits.
A `worldgraph_template` post binds an executable provider template or local
workflow to that Connection.

The shipped generation adapters are:

### Local ComfyUI

Local ComfyUI is reached through its HTTP API. The Connection's `endpoint_url`
must be reachable from the WordPress runtime. The adapter uses:

- `POST /prompt` to submit a workflow;
- `GET /history/{prompt_id}` to poll it;
- `POST /upload/image` for bound media inputs;
- `GET /object_info` for nodes and installed model choices; and
- `GET /view` for generated outputs.

World Graph Studio creates a managed, registry-backed text-to-image Template
during local setup. It deterministically prefers the published Z-Image-Turbo
workflow and stores its converted API graph plus exact model requirements. It
does not silently construct a legacy checkpoint graph when registry conversion
or readiness fails. Operators can still author separate Templates
with API-format workflow JSON. The readiness panel checks the selected graph's
exact nodes and models before a job is queued.

All local HTTP operations use the endpoint resolved from the Template's own
Connection: workflow submission and conversion, media upload, history polling,
`/object_info` readiness, and `/view` retrieval do not silently switch to a
different global ComfyUI server.

A separate Comfy MCP endpoint is optional. A bare ComfyUI HTTP endpoint does
not speak MCP, so do not append `/mcp` to port `8188`. When an actual MCP
server is configured, it can advertise templates and handle model-download
requests.

### Comfy Cloud MCP

Comfy Cloud uses Streamable HTTP JSON-RPC at
`https://cloud.comfy.org/mcp`. `Comfy_Cloud_MCP` establishes a session, calls
advertised tools, normalizes JSON or event-stream responses, and correlates
logs with the owning Connection.

Execution uses `run_template` and `get_job_status`. Catalog support is probed
from `tools/list`; discovery and provisioning are offered only when the server
advertises `list_templates`, `get_template`, and `download_models` as needed.

### fal MCP

The fal adapter connects to `https://mcp.fal.ai/mcp`. On save or connection
test, catalog provisioning inspects allowed endpoint schemas and maintains
paired active Templates. `Model Access` is the endpoint allowlist when set;
otherwise the preferred `Model` or a provider-selected current text-to-image
endpoint is used.

### ElevenLabs

The ElevenLabs adapter uses `https://api.elevenlabs.io/v1`. Catalog sync reads
available speech models and voices and provisions endpoint-specific Templates
for the five registered audio modalities. Completed audio, including voice
design previews, is imported into the WordPress media library before the job is
marked complete.

### Suno REST and MCP

One `suno` Connection holds the SunoAPI.org REST endpoint and the AceData Cloud
Suno MCP endpoint, but their bearer tokens remain in separate
`credential_reference` and `mcp_credential_reference` fields. These are
independent third-party providers; credentials and model names are not
interchangeable.

Catalog sync provisions six transport-specific Templates: prompt music,
custom music, and lyrics for REST, plus the same three operations for MCP.
SunoAPI.org jobs use a token-protected callback only to schedule polling and
are reconciled through the provider record-info endpoint. MCP jobs use their
returned task ID and `suno_get_task`. Music completion imports both returned
tracks before the generation is marked complete. See [Suno
Integration](SUNO.md) for the operator and transport contracts.

### VideoDraft hosted MCP

A `videodraft` Connection calls `https://app.videodraft.ai/api/mcp` directly
from WordPress with a VideoDraft personal access token. Connection testing
reads `tools/list`, verifies the generation and Project tools used by the
integration, and provisions active Templates from the live input schemas for
image, video, audio, voiceover, music, and sound-effect generation.

Image and video tools return asynchronous jobs that the batch worker polls
with `check_generation_status`; completed image, video, and audio URLs are
imported through the normal media and provenance path. Bound local media uses
VideoDraft's presigned upload flow. The Node CLI is the protocol reference,
not a WordPress runtime dependency. See [VideoDraft Connection and
Sync](VIDEODRAFT.md).

### Manually managed providers

Connections may record other provider types, and users can always generate in
an external web application and attach the downloaded result. Provider names
present in the Connection schema are not a promise of a direct executable
adapter. Hosted services can impose their own prices, credits, quotas,
moderation, licenses, and availability; World Graph Studio does not override
those terms.

## Templates and input bindings

An active Template records the provider, Connection, modality, provider
template ID, optional workflow JSON, model/checkpoint information, and default
configuration. Templates are WordPress configuration records, not permission
to run arbitrary server code.

Runnable Template DTOs expose a sanitized `run_controls` contract with
`version: 1`, a deterministic definition `fingerprint`, and ordered `fields`.
Each field contains a provider-neutral `key`, `label`, UI `type`, and only
the applicable default, required, `min`, `max`, `step`, labeled `options`,
group, and bounded description metadata. Public UI types are `string`,
`textarea`, `integer`, `number`, `boolean`, and `select`; their submitted values
remain scalar. The DTO is an allowlist for rendering, not a copy of the provider
schema or workflow. It never exposes node IDs, binding paths, credentials, or
filesystem paths. WordPress re-derives this contract from the selected Template
when a job is submitted, so a stale or modified browser DTO cannot expand what
the Template permits.

The conditional field vocabulary covers controls only when the Template's
workflow or provider schema discovers or declares them: negative conditioning;
fixed integer seed; bounded sampling steps; classic diffusion CFG or,
separately, FLUX-style guidance; allowlisted sampler and scheduler; image width
and height; video duration and frames per second; prompt enhancement when the
saved workflow exposes it safely; and distinct extra text-conditioning or
dual-CLIP channels. The engine does not infer dual
encoders from a model-family label, and it does not expose both CFG and FLUX
guidance when the workflow has only one of them.

These fields are per-run scalar overrides. Media inputs remain Template
bindings: image-to-video and text-plus-image-to-video continue resolving their
image or start-frame input through `Template_Bindings`. Checkpoint/model, VAE,
and CLIP file selection remains part of Template authoring, catalog discovery,
requirements, and readiness rather than a client-supplied run value.
The Template Workflow Test projects the exact fixed loader selections from a
local ComfyUI workflow as bounded filename, models folder, loader-node class,
and field labels. Operators use that display and the requirements check to
install the files expected by that saved workflow on the selected Connection;
the application does not translate prompt keywords into model selections.
For local ComfyUI, editor graphs are converted to API format before media
binding. Explicit `{{image}}`, `{{start_frame}}`, and `{{end_frame}}` loader
markers win; otherwise a single image loader or topology-proven first/last
frame is bound. Unrelated literal loaders remain Template-owned, while an
ambiguous required binding fails before upload and submission.

The saved graph is authoritative. In particular, the official WAN 2.2 5B
hybrid workflow selects the FP8 UMT5 encoder and `wan2.2_vae.safetensors`
(a tested compatible FP16 encoder can be selected when FP8 is unsupported),
whereas official WAN 2.2 14B high/low-noise workflows use paired task-specific
diffusion loaders and `wan_2.1_vae.safetensors`. LTX 2.5 workflows have their
own transformer, text encoder, video/audio VAE, and optional latent-upscaler
selections. The Workflow Test display—not a prompt keyword or family label—is
the exact list the selected Connection must provide.

The author first chooses a conditional **Image**, **Sequence**, **Video**, or
Project-only **Demonstration** mode. Image and video each reveal only their own
defined output selector,
matching active runnable Template, applicable featured/linked-Asset choices,
and contextual action. Sequence reveals the complete item or Project workflow,
image/video counts, and only the Template controls needed by that plan before
confirming a durable batch. Demonstration shows image, video, and audio work,
separate applicable Template selectors, fallback behavior, and the latest
whole-story batch before confirmation. Its audio selector and scalar run
controls appear only for generated audio tasks; linked audio needs no provider
override. Modes
not defined for the current CPT are disabled.
The complete prompt for a single output remains server-composed and appears in
a collapsed read-only preview; a Sequence instead previews its distinct jobs.
Template-conditional fields live in a separate collapsed **Run controls
(optional)** disclosure. Its effective precedence is explicit per-run value,
compatible Project output framing, Template sampling/negative default, then
the provider/workflow baseline. The browser sends only deliberate changes.

Runnable Template lists exclude disabled Connections, mismatched output types,
and Templates whose required bindings cannot be resolved for every applicable
task. `Template_Bindings` resolves declared media slots from a featured image,
the post's asset gallery, or an SCF/post-meta field. Invalid combinations fail
before provider execution.

Direct story-aware generation may submit `run_values`. A batch may submit one
`image_run_values`, `video_run_values`, or `audio_run_values` map for its
corresponding generated tasks when it also selects the explicit per-type
Template override. Values must be scalar, use advertised keys, and match the
advertised types, bounds, and allowed choices. A non-empty batch map without
its matching Template ID, unknown keys, and nested arrays/objects are errors;
they are not
silently forwarded. Omitting these maps applies compatible Project framing and
otherwise preserves the Template/provider defaults. Omitting `seed`
specifically preserves the existing randomization behavior; integer `0` is not
treated as omission.

For each task, Template resolution follows this cascade:

1. an explicit image, video, or audio Template supplied with the batch request;
2. per-post `_worldgraph_generation_template_{intent}` metadata;
3. the intent mapping in `worldgraph_generation_preferences_v1`;
4. the output-type mapping in that option;
5. the managed local ComfyUI text-to-image Template for image output; and
6. the first runnable compatible Template.

Every candidate is revalidated against the source post, output type, input
bindings, and Connection availability. See [Generate Preferences and
Generation Intents](GENERATE_PREFERENCES.md) for the option shape and intent
contract.

## Catalogs, manifests, and readiness

ComfyUI catalog and requirement work is delivered:

- catalogs are cached per Connection in `comfy_template_catalog`;
- operator choices are stored separately in `enabled_templates`;
- MCP-capable Connections discover provider templates by registered task type;
- HTTP-only local Connections synthesize entries from the registered modality
  list and inspect `/object_info`;
- enabled entries can be materialized into `worldgraph_template` posts;
- requirement manifests derive node classes and model files from the workflow;
- the Template editor and REST API can validate requirements; and
- provider-advertised model URLs can be sent to an MCP
  `download_models` tool when that tool is available.

Model downloads are never performed by arbitrary shell execution inside
WordPress. If no download tool or source URL is available, the operator installs
the requirement in ComfyUI and rechecks readiness. Custom nodes remain an
operator-managed ComfyUI concern.

See [Comfy Template Catalog](COMFY_TEMPLATE_CATALOG.md) for the complete current
catalog flow.

## Job lifecycle

Generation records are stored as internal `worldgraph_gen` posts. Submission
persists the record before external execution and schedules
`worldgraph_process_generation_batch`.

Representative-media and demonstration batches are also durable
`worldgraph_gen` posts, marked with `_worldgraph_gen_batch_kind =
representative_media` or `demonstration_video`. The parent stores the
root post, `item`, `project`, or `demonstration` scope, requester, optional
idempotency key, a
versioned frozen task plan, materialization cursor, planned total, and aggregate
status. The current frozen workflow contract is version 2. Each task snapshot
retains its step, source, workflow, intent, output type, Template, prompt,
prompt hash, featured behavior, normalized explicit run
values, and separately projected Project output values. Both effective value
sets are frozen before materialization. Child jobs store
`_worldgraph_gen_batch_id`, `_worldgraph_gen_batch_step`, and
`_worldgraph_gen_intent`. This separation lets a Project batch run for hours or
days while every child remains independently observable through the ordinary
worker lifecycle.

A demonstration batch additionally freezes task keys, reference/audio/video
phases, required versus optional behavior, dependencies, symbolic input
references, preferred modalities, fallbacks, and `_worldgraph_gen_assembly_plan`.
Reference and generated audio work can be queued before moving Shots; linked
Sound Assets bypass provider generation. A video task waits
until media required by its selected modality has completed. Missing optional
motion becomes its still fallback. Missing audio becomes silence, while Scene
dialogue/captions can become subtitle graphics. A terminal skipped child
records optional work that could not be generated without holding the batch
open indefinitely.

Planning performs no writes. Starting validates edit permission for every
source, resolves all required Templates, and re-derives and validates the run
control allowlist before freezing the batch plan. A
requester-scoped non-empty idempotency key returns the prior batch for the same
root and the same normalized run values instead of creating duplicate provider
work. Image, video, and audio run values are part of the request fingerprint,
so reuse of a key with different settings fails rather than returning or
creating the wrong run. Batch summaries derive their
counts from the frozen total and persisted child states and report `pending`,
`active`, `cancelling`, `cancelled`, `failed`, `completed`, or
`completed_with_errors`.

Representative preparation phases are durable: `batch_materializing` creates
up to 20
non-runnable `staged` children per tick; after every frozen task exists,
`batch_activating` promotes up to 50 staged children per tick; only then does the
parent enter `batch_active`. A renewable coordinator lease and step lookup keep
a restarted materialization cursor from duplicating a child. The complete root
metadata and worker wake-up are verified before the visible commit marker is
published; an idempotent retry safely re-establishes a missing wake-up.

The worker:

1. materializes and activates bounded groups from frozen batch plans, resolving
   demonstration dependencies before dependent video dispatch;
2. polls up to ten submitted jobs;
3. submits up to five queued jobs;
4. records the provider's remote job identifier;
5. reschedules itself after 60 seconds while work remains;
6. imports completed media before recording success; and
7. after demonstration children are terminal, transitions the parent to
   assembly and schedules the independent rough-cut worker.

The rough-cut worker uses its own WP-Cron hook and claim, separate from provider
polling. It advances a bounded assembly phase/cursor per tick, checkpoints the
signed `_worldgraph_rough_cut_state`, verified batch-specific work directory,
and progress, and reschedules while work remains. One tick initializes,
attempts one segment/fallback, concatenates, handles subtitles, mixes audio or
silence, or imports. A heartbeat protects a live FFmpeg operation; a stale
claim can be recovered on a
later tick. Completion stores the imported rough-cut attachment, while an
unrecoverable or unavailable-FFmpeg result stores a bounded diagnostic.
Successful or terminal-error cleanup removes private assembly state and safe
generated temporary files; import retries reuse batch-tagged attachments.

The durable states are:

| State | Meaning |
| --- | --- |
| `queued` | Persisted and waiting for the batch worker |
| `submitting` | Claimed locally and preparing a provider request; still cancellable |
| `dispatching` | Provider dispatch began; an interrupted outcome requires reconciliation rather than automatic resubmission |
| `submitted` | Accepted by an asynchronous provider and being polled |
| `completed` | Provider work and required media import succeeded |
| `failed` | Validation, provider execution, or media import failed |
| `cancelled` | Cancelled in World Graph Studio |

Cancelling a representative batch cancels child jobs that remain `staged`,
`queued`, or `submitting` before dispatch. Jobs already `dispatching`, submitted,
or terminal retain their real state and continue to contribute to the aggregate
summary. The response reports how many not-yet-dispatched jobs were stopped.
The same boundary applies to a demonstration: cancellation stops pending local
work and prevents a new assembly from starting, but it cannot retract provider
work already dispatched.

The rough-cut assembler prefers each completed Shot video, normalizes it to the
Project frame profile, and falls back to its completed still when video is
missing or cannot be normalized. It preserves the frozen editorial order,
builds still/title cards for stories without Shots, renders subtitles, mixes
bounded generated or linked audio cues where available, uses silence when none
is usable, concatenates H.264 segments, and imports
the result into the Media Library. It is deliberately a watchable first pass,
not an assertion that provider generations need no editorial revision.

Assembly is deployment-dependent. PHP must provide `proc_open`; WordPress needs
a writable temporary directory; and an executable FFmpeg binary must be on the
runtime path or configured with `WORLDGRAPH_FFMPEG_BINARY` or the
`worldgraph_ffmpeg_binary` filter. If those checks fail, child outputs are
retained and batch status exposes a bounded assembly error/diagnostic. The
repository does not imply that FFmpeg or provider models are installed on a
particular host.

Automatic local assembly currently runs in the WordPress PHP runtime. Lando
installs FFmpeg in `appserver` for that reason; `lando ffmpeg` targets the
separate `cli` container and is not callable by the WP-Cron worker. ComfyUI may
become the preferred backend only when the selected Connection exposes a
capability-verified, narrowly scoped assembly Template/node that accepts a
bounded manifest and constructs fixed FFmpeg argument vectors internally. The
ordinary `/prompt` API does not expose ComfyUI's FFmpeg executable, and a graph
that decodes an entire story into IMAGE tensors is not a production assembly
fallback.

ElevenLabs and VideoDraft audio may return completed results synchronously.
ComfyUI, fal, Suno, and VideoDraft image/video tools can return asynchronous
jobs. A local ComfyUI Connection with an MCP endpoint can
fall back to the local HTTP adapter when MCP submission fails. Suno REST and
MCP Templates do not fall back across transports because their credentials and
provider contracts are different.

## Result import and provenance

Completed image, video, and audio outputs are downloaded through WordPress,
validated by type and size, and inserted as media attachments. Multiple image
or audio results are retained when the provider returns them. Depending on the
originating request, the primary attachment can become the post's featured
media and a linked `worldgraph_asset` record can be created.

Large video and URL-based audio outputs use bounded streamed temporary files;
image responses and local ComfyUI media inputs are also byte-limited. The
importer refuses to complete unless an attachment matches the job's requested
image, video, or audio output type.

Text-output jobs such as Suno lyrics retain their normalized provider result on
the generation record and do not create a media attachment.

Generation metadata retains the source post, Template, provider, Connection,
workflow/provider-template reference, prompt, normalized run parameters,
timestamps, remote job ID, result attachment IDs, and terminal status. Raw
synchronous audio bytes are removed before the provider result is persisted.

Generated attachments use identifiable, portable filenames:

```text
{project-slug}-{cpt-type}-{source-slug?}-{intent?}-job-{job-id}.{ext}
```

`project-slug` uses the Project's canonical `project_slug`, falling back to its
WordPress slug. The `worldgraph_` prefix is removed from `cpt-type`; the source
slug is omitted when it would repeat the Project slug, and the intent is omitted
when the job has none. Imports without a queued job ID use a UTC
`YYYYMMDD-HHMMSS` suffix instead. Tokens are sanitized for filenames.

The Media Library title uses the readable form
`Project — CPT type — source — intent/media type`. The Project source omits the
repeated source component, a registered intent uses its human label, and media
without an intent falls back to Image, Video, or Audio. This naming is display
and portability metadata; generation provenance remains attached separately.

Recent diagnostic events are available under the Generation Log admin page.
Logs and generation records must not contain authorization headers or secret
keys.

## REST surface

The canonical REST base is `/wp-json/worldgraph/v1/`.

| Method and route | Purpose |
| --- | --- |
| `GET /assets/generate/prompt?post_id={id}` | Direct image/Shot-video actions, read-only prompts, and runnable Templates with sanitized run controls |
| `POST /assets/generate` | Queue one story-aware image or Shot video with optional `run_values` |
| `GET /assets/generate/plan?post_id={id}&scope=item\|project\|demonstration` | Preview representative or whole-story outputs, prompt hashes, runnable Templates and run controls, defaults, fallbacks, and the latest batch |
| `POST /assets/generate/batches` | Validate and start a durable item, Project representative-media, or Project demonstration batch with optional image/video/audio Template overrides and run values |
| `GET /assets/generate/batches/{id}` | Read aggregate batch progress and child jobs |
| `POST /assets/generate/batches/{id}/cancel` | Cancel not-yet-dispatched children and return refreshed batch status |
| `POST /generation` | Create a Template-backed generation record |
| `GET /generation/{id}` | Read job status and identity |
| `POST /generation/{id}/cancel` | Mark a job cancelled |
| `GET /generation/asset/{asset_id}/history` | Read generation history for an Asset |
| `GET /generation/templates/{id}/requirements` | Read and optionally validate a Template manifest |
| `GET/POST /connections` | List or create provider Connections |
| `GET/PUT/DELETE /connections/{id}` | Manage one Connection |
| `GET /connections/{id}/resolve` | Read normalized Connection configuration |
| `POST /connections/{id}/test` | Run the adapter health check |
| `POST /connections/sync` | Refresh the provider capability snapshot |

Catalog enable, materialize, and download controls are current admin actions,
not public catalog REST routes. They require an edit-capable administrator and
a `worldgraph_conn_configurator` nonce.

The story-aware run-value contract is additive. Clients that omit
`run_values`, `image_run_values`, `video_run_values`, and `audio_run_values`
keep the prior Template-default behavior. See the REST specification for their
exact request and response shapes.

## WordPress Abilities and MCP exposure

On WordPress versions that provide `wp_register_ability`, World Graph Studio
registers generation-related abilities including:

- `worldgraph/templates-manifest`;
- `worldgraph/template-requirements`;
- `worldgraph/suggest-asset-prompt`; and
- `worldgraph/generate-asset`.

An installed WordPress MCP adapter may expose public abilities to external MCP
clients. The in-editor filmmaking advisors do not autonomously invoke these
abilities; their current LLM requests use `tool_choice: none`. See
[ComfyUI and Prompt Guidance](COMFY_AND_PROMPT_AGENTS.md).

## Security and operating boundaries

- Generation and Connection operations use WordPress capability checks and
  nonces or REST authentication.
- Secrets belong in deployment configuration where supported; never commit
  them or place them in client-side JavaScript.
- A local ComfyUI without authentication must be protected by the deployment
  network boundary.
- Treat provider catalogs, workflow descriptions, URLs, and error text as
  untrusted input.
- Treat submitted run values as untrusted even when they came from a
  server-rendered control. Re-derive the selected Template's allowlist and
  normalize only scalar, typed, bounded, allowlisted values before persistence
  or provider dispatch.
- WP-Cron must be triggered reliably in production.
- Demonstration assembly additionally requires FFmpeg, `proc_open`, temporary
  storage, and suitable runtime limits; failure is surfaced diagnostically and
  does not erase completed child outputs.
- World Graph Studio remains useful for writing, planning, analysis, and asset
  management with no generation Connection.

## Implementation map

- [Provider registry](../../wordpress/wp-content/plugins/worldgraph/includes/utils/connection-adapters.php)
- [Generation modalities](../../wordpress/wp-content/plugins/worldgraph/includes/utils/generation-modality.php)
- [Generation worker](../../wordpress/wp-content/plugins/worldgraph/includes/utils/generation-batch.php)
- [Representative workflows and batches](../../wordpress/wp-content/plugins/worldgraph/includes/utils/class-generation-workflows.php)
- [Model-aware prompt profiles](../../wordpress/wp-content/plugins/worldgraph/includes/utils/class-generation-prompt-profiles.php)
- [FFmpeg rough-cut assembler](../../wordpress/wp-content/plugins/worldgraph/includes/utils/class-rough-cut-assembler.php)
- [Generation REST controller](../../wordpress/wp-content/plugins/worldgraph/includes/rest-api/generation-controller.php)
- [Assets metabox controller](../../wordpress/wp-content/plugins/worldgraph/includes/rest-api/asset-generation-controller.php)
- [Asset import and provenance](../../wordpress/wp-content/plugins/worldgraph/includes/utils/class-asset-generator.php)
- [ComfyUI catalog](../../wordpress/wp-content/plugins/worldgraph/includes/utils/comfy-catalog.php)
- [ComfyUI manifests](../../wordpress/wp-content/plugins/worldgraph/includes/utils/comfy-manifest.php)
- [Setup and Connections](../Deployment_and_Connections.md)
- [Suno Integration](SUNO.md)
