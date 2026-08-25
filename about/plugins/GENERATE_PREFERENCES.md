# Generate Preferences and Generation Intents

> **Delivery status:** the provider-neutral representative-media registry,
> Template-resolution preferences, bounded Story Graph prompts, durable item,
> Project, and whole-story demonstration batches, dependency-aware fallback
> planning, FFmpeg rough-cut assembly, Template-conditional per-run controls,
> explicit Template/Project/item run-default layers, and their REST operations are
> delivered. Provider execution and final assembly
> still depend on the deployment's Connections, models, and FFmpeg installation.
> A dedicated
> graphical site-preferences editor is not required by this contract; sites can
> manage the versioned option through an integration or filter.

## Delivered authoring workflow

The **World Graph Studio Assets** Generate surface starts with four conditional
modes:

- **Image** selects one defined still-image intent, its image Template, and the
  applicable featured/Asset-record behavior;
- **Sequence** selects a complete multi-output item workflow or, on a Project,
  all Project representative media, then previews the image/video plan and
  Template behavior before queueing a durable batch; and
- **Video** selects one defined video intent and its video Template. The default
  registry enables this mode for Shots; and
- **Demonstration** appears on a Project and previews and queues an ordered,
  end-to-end story pass. It plans required reference/still work before optional
  motion and audio enhancements, then requests a rough-cut assembly. Its plan
  exposes separate image and video Template selectors and, only when at least
  one audio task must be generated, an audio Template selector. Each selector
  can remain at **Use each output's configured Template** (`0`) or override all
  generated tasks of that output type.

Modes that are not defined for the current CPT are visibly unavailable. Each
available mode has its own output selector, contextual explanation, relevant
Template controls, and primary action; image, Sequence, and video operations
are not mixed in one ambiguous output menu.

The editable textarea contains only optional **Additional instructions for
this run**. The complete automatically composed provider prompt is available
in a collapsed read-only preview. For a Sequence, that preview lists the
planned outputs because each job receives a separately composed prompt.
Template selections are retained per selected image, video, Sequence, or
Demonstration target and survive the read-only planning refresh before
confirmation.
Selecting a Template also reveals only the per-run controls that Template
declares or that its provider schema/workflow exposes safely. Changing the
Template replaces that control set and resets values that no longer belong to
the selected Template.

Planning reports the number of image, video, and audio jobs, every source and
creative intent, prompt fingerprints, Templates runnable across the plan,
resolved defaults, and the latest batch. It performs no writes and spends no
provider budget.
Starting a batch revalidates permissions, Templates, Connections, and bindings
before any child job is queued.

The original endpoints remain compatible:

```http
GET  /wp-json/worldgraph/v1/assets/generate/prompt?post_id={id}
POST /wp-json/worldgraph/v1/assets/generate
```

## Bounded prompt contract

Project, Story World, Character, Prop, Location, Shot, Scene, and Episode expose
an optional SCF textarea named `generation_prompt`. It contains additional
media-generation instructions such as a house style, wardrobe or material
constraint, camera movement, or "no watermark." It is not a negative-prompt
transport field and does not replace the entity's authorial description.

The composer starts with the requested shot or asset, not the enclosing story.
It assigns source data to semantic sections and uses only the smallest visual
vocabulary needed for that output:

| Content type | Detailed fields included when populated |
| --- | --- |
| Project | description and genre |
| Story World | synopsis, geography, references, timeline, and rules |
| Character | visible appearance, age, and distinguishing traits |
| Prop | description, purpose, and visible notes |
| Location | first useful description sentence, environment, geography, and mood |
| Shot | description first; framing, angle, lens, and the still-frame or timed-motion beat |
| Scene | summary, location, time of day, and mood |
| Episode | synopsis |

The semantic composition order is:

1. the source description or requested action as `primary`;
2. the intent's concise `objective`;
3. only relevant identity, subject, action, setting, character, camera, motion,
   look, and continuity sections;
4. minimal ancestor or dependent context when it changes what should be
   visible;
5. saved `generation_prompt` and optional `base_prompt` as protected author
   instructions; and
6. concise output constraints.

The composer removes markup, renders select values and relationships readably,
and deduplicates repeated core/SCF text. A Shot inherits its Scene location,
time, mood, and a compact setting cue, not the Project description, Episode
synopsis, complete Scene script, or dialogue transcript. Related Characters
are included only when relevant to the described shot.
`base_prompt` adds instructions without removing the assembled Story Graph
context or saved `generation_prompt`; in Project scope it applies to every
planned source. The
`worldgraph_generate_asset_prompt` filter runs last with the prompt, source
post, intent, and selected Template ID (zero before Template selection). Existing
callbacks registered for the original three arguments remain compatible.

After Template resolution, `Generation_Prompt_Policy` renders those sections
under a normalized, non-executable policy. A policy may specify
`limits.target_words`, hard `max_words`, `max_characters`, or `max_bytes`;
preferred or forbidden optional section IDs; and the allowlisted hints
`profile`, `lead_with` (`subject`, `action`, or `motion`), and `format`
(`natural_language`, `concise_phrases`, or `chronological_prose`). It cannot
suppress `primary`, `objective`, author instructions, constraints, or verbatim
author text. Hard limits from later layers only make the effective ceiling
more restrictive. Core fallbacks are deliberately short and remain subject to
an absolute provider-neutral safety ceiling.

The Template editor exposes the common operator choices as first-class fields:
`prompt_lead_with`, `prompt_format`, `prompt_target_words`, and
`prompt_max_words`. They are sparse overrides; blank means inherit the reviewed
Connection/model recommendation. The primary description always remains
first, and `prompt_lead_with` prioritizes its subject, action, or motion section
immediately afterward. The target is a creative composition goal; the maximum
is a hard ceiling and cannot loosen a stricter inherited provider/model bound.
The saved Template's **Effective Prompt Guidance** box shows the resolved
profile, order, format, and lengths, so common tuning does not require editing
`configuration_json`.

Prompt-policy precedence is core output/modality/intent fallback, trusted
adapter-manifest `generation.prompt_policy`, trusted Connection policy filter,
reviewed model-family/model-slug profile, discovered
`configuration.provider_prompt_policy`, operator
`configuration.prompt_policy`, first-class Template prompt-guidance fields,
bounded positive-prompt schema `maxLength`, and the final trusted prompt-policy
filter. Remote catalog or MCP descriptions,
schemas, resources, results, and free-form "best practice" prose are never
executed as prompt instructions. MCP may inform bounded provisioning-time
discovery, but only reviewed numeric or enumerated values normalized into the
Template policy are retained; generation does not consult MCP for prompt prose.
Negative suggestions remain a separate negative-conditioning run value and
are never appended silently to the positive prompt.

For compatible WAN graphs that expose the corresponding scalar controls, a
practical starting point is 1280x720, 24 fps, 20-25 steps, CFG 5-7, and either
DPM++ 2M with Karras or Euler ancestral; DPM++ 3M SDE is a higher-cost option
only when the graph advertises it. These values are suggestions, not automatic
defaults. Preserve a workflow's high/low-noise routing and custom sampler
topology.

The following LTX presets are operator starting points, not official
LTX 2.5 defaults. Apply one only when the imported graph declares safely
mutable equivalents and **Template Workflow Test** confirms the intended
nodes:

| Preset | Sampler/scheduler | Steps | CFG | Denoise | Motion | Requested output | FPS |
| --- | --- | ---: | ---: | ---: | ---: | --- | ---: |
| Balanced cinematic | DPM++ 2M / Karras | 24 | 4.5 | 0.60 | 0.55 | 1920x1080 | 50 |
| High-detail hero | DPM++ 2M / Karras | 36-40 | 4.2 | 0.55 | 0.45 | 2560x1440 | 50 |
| Fast-action preview | UniPC | 16 | 4.0 | 0.65 | 0.50 | 768x432 | 24 |

Do not replace an official LTX distilled/custom sampler graph just to apply
this table. `motion` has no generic engine meaning unless the Template maps it.
Raw LTX latent dimensions must be divisible by 32 and literal frame counts must
be `8n + 1`; workflow-provided output presets may perform compatible internal
rounding. The detailed model matrices and tuning cautions are in
[Text to Video with Local ComfyUI](../how-to-text-to-video.md).

## Template-conditional run controls

Every Template summary returned by the story-aware prompt and plan operations
includes a sanitized `run_controls` object. The object has `version: 1`, a
deterministic `fingerprint`, and an ordered `fields` list. The fingerprint lets
a client detect that the effective form changed; it is not an authorization
token, and the server never trusts a client-cached descriptor when a run
starts. Each field has a `key`, `label`, and UI-native `type` (`string`,
`textarea`, `integer`, `number`, `boolean`, or `select`), with
applicable `default`, `required`, `min`, `max`, `step`, labeled scalar
`options`, group, and bounded `description` metadata. Raw workflow JSON,
provider schemas, node IDs, binding paths, filesystem paths, and credentials
are not returned as run controls.

For recognized controls, `description` begins with concise provider-neutral
help. The UI expands `cfg` to **CFG (Classifier-Free Guidance)** and explains
that it controls prompt adherence, while `guidance` remains a distinct,
model-specific setting. When a provider schema supplies different
`description` or `help` prose, core strips markup and control characters,
collapses whitespace, bounds it, and appends it as **Provider note:**. A generic
allowlisted field may have only that bounded note. Provider notes are display
text only; they cannot alter allowed controls, types, bounds, defaults, or the
composed prompt.

Controls are conditional, not a universal form:

- `negative_prompt` is separate from the additive Story Graph prompt and is
  offered only when the Template has a negative-conditioning input;
- a fixed-seed integer appears when supported; leaving it unset preserves the
  Template/provider's existing randomization behavior, while an explicit `0`
  remains a fixed seed;
- sampling steps are bounded integers;
- classic diffusion CFG (Classifier-Free Guidance) and FLUX-style guidance are
  separate concepts, and
  only the control appropriate to the discovered or declared workflow is
  shown;
- sampler and scheduler choices are restricted to values advertised or
  declared for the Template;
- width and height, and video duration or frames per second, appear only when
  the selected Template exposes those inputs; and
- prompt enhancement appears only when a saved workflow exposes a safely
  mutable prompt-enhance input; and
- additional text-conditioning channels, including dual-CLIP channels, appear
  only when their distinct inputs are discovered or explicitly declared. They
  are not inferred merely from a model-family label.

In the Assets metabox these fields stay inside the collapsed **Run controls
(optional)** disclosure. Each field identifies the layer that supplied its
current value. Runtime precedence, from lowest to highest, is:

1. the selected Template's declared default;
2. compatible owning-Project profile values (`frame_width`, `frame_height`,
   `aspect_ratio`, and `frame_rate`);
3. the owning Project's saved override for the exact Connection + Template;
4. the source item's saved override for that exact pair; and
5. a one-off value submitted for the current direct run or batch.

The same item layer applies to every supported source type, including Shot,
Character, Location, Prop, Scene, Episode, and Story World; it is not a
Shot-specific schema. Template, Project, and item saves are explicit actions.
Generating media never changes a default. **Save current values as Template
defaults** changes the baseline for every Project and item that uses that exact
Template. The Project and item actions submit the same complete
visible form, which the server validates and stores sparsely against inherited
lower layers. The matching reset action removes only that layer and immediately
reveals its inherited value. A one-off value always wins and is never persisted
by the run.

The editor shows the status of each available layer and converts repository
warning codes into human-readable notices. Invalid or incompatible saved rows
are ignored and, when the current user can edit the affected target, retain a
visible reset action for recovery. Each field shows its initial provenance;
after any input or change event it reads **This run (not saved)** so an edited
value is never presented as inherited.

Template saves persist the validated flat scalar object as canonical JSON in
the Template's `default_values` field; Template reset writes `{}`. The raw
**Default Values JSON (Advanced)** field remains available as an escape hatch,
but ordinary editors should use the validated Generate surface. Project and
item defaults continue to use the exact-pair repository below.

Saved values use the versioned post-meta repository
`_worldgraph_generation_run_defaults`. Its conceptual JSON shape is:

```json
{
  "version": 1,
  "entries": {
    "c:41:t:203": {
      "connection_id": 41,
      "template_id": 203,
      "fingerprint": "64-character SHA-256 run-control fingerprint",
      "values": { "steps": 28 }
    }
  }
}
```

The pair key is exactly `c:{connection_id}:t:{template_id}`. Connection ID is
derived from the selected Template; clients cannot choose a different pair.
The fingerprint is the SHA-256 fingerprint of the current normalized
`run_controls` definition. Save and reset require the current fingerprint and
return a conflict for a stale form. On read, an older entry is revalidated
against the current contract and either exposed with a warning or ignored if
it is incompatible. Reparenting a Template to another Connection therefore
cannot accidentally reuse the old pair.

The contextual `run_defaults` DTO returned with a runnable Template exposes
the effective values, per-field sources, layers, editable
Template/Project/item targets, warnings, and current fingerprint. The defaults
endpoint is:

```http
GET    /wp-json/worldgraph/v1/assets/generate/defaults?post_id={id}&template_id={id}&scope={template|project|item}
POST   /wp-json/worldgraph/v1/assets/generate/defaults
DELETE /wp-json/worldgraph/v1/assets/generate/defaults
```

POST accepts the complete `values` object and required `fingerprint`; DELETE
accepts the required fingerprint. Both writes require permission to edit the
resolved Template, Project, or item target.

When a legacy Project/item defaults document is structurally unreadable, its
target remains marked as having overrides so Reset stays available. Because an
exact pair cannot be identified safely inside malformed storage, that explicit
reset clears the unreadable document as a whole; the same snapshot conflict
check prevents it from erasing a concurrent replacement. Malformed or
incompatible Template `default_values` similarly reports a warning and remains
resettable.

Media inputs are not scalar run controls. Image-to-video and
text-plus-image-to-video Templates continue to obtain their image or
start-frame inputs through `Template_Bindings`, with required bindings checked
for each source item. Local ComfyUI then binds each uploaded filename to an
explicit or unambiguous `LoadImage.image` target; auxiliary Template images are
left intact, and ambiguous graphs fail before provider submission. Likewise
checkpoint/model, VAE, and CLIP file selection remains a Template-authoring and
readiness concern; the Assets form cannot submit arbitrary model or filesystem
names for one run.

For a local ComfyUI Template, **Template Workflow Test** displays the exact
model-loader selections found in the saved graph, including the filename,
models folder, loader node class, and field. This is the authoritative list of
what that workflow asks that ComfyUI installation to load. Install matching
files in that specific Connection's ComfyUI model folders or edit/import the
workflow and recheck readiness. Requests, workflow conversion, uploads,
history polling, readiness, and output retrieval all use the selected
Connection's `endpoint_url`; model selections are not inferred from a different
global ComfyUI endpoint.

For example, the official WAN 2.2 5B hybrid graph selects
`umt5_xxl_fp8_e4m3fn_scaled.safetensors` and `wan2.2_vae.safetensors`; a tested
compatible FP16 UMT5 file can replace FP8 on unsupported hardware. Official
WAN 2.2 14B high/low-noise graphs instead select their paired task-specific
diffusion files and `wan_2.1_vae.safetensors`. Do not apply the 5B VAE name to
14B as a blanket quality change. The current official LTX 2.5 text-to-video
graph selects `ltx-2.5-22b-distilled-transformer-comfy-int8-convrot.safetensors`,
`gemma4-12b-with-proj-ltx-2.5-comfy-int8-convrot.safetensors`, the distinct
`ltx-2.5-video-vae-bf16.safetensors` and
`ltx-2.5-audio-vae-bf16.safetensors`, and
`ltx-2.5-latent-spatial-upscaler-x2-bf16-1.0.safetensors`. Other LTX input
shapes may expose a different subset, so the saved graph and Workflow Test
remain authoritative. Prompt keywords never alter any loader field.

For a direct run the client may send a `run_values` object keyed by the
selected Template's advertised fields. For a Sequence or Demonstration,
`image_run_values`, `video_run_values`, and, for generated demonstration audio,
`audio_run_values` may accompany the corresponding explicit per-type Template
override and apply to every generated task of that output type. Audio controls
are not exposed for direct image or video runs. WordPress re-derives the v1
contract from the selected Template at submission time, rejects unknown,
nested, wrongly typed, out-of-range, or non-allowlisted values, and passes only
normalized scalar values to generation.
A non-empty batch values object without its matching Template ID is invalid.
An omitted or empty values object still resolves the selected Template's
Project-profile, Project-pair, and per-source item-pair defaults. An explicit
per-type batch value is the one-off highest layer for every matching task.

## Delivered intent vocabulary

`WorldGraph\Utils\Generation_Workflows` owns the stable creative-intent slugs.
They describe what to make; the resolved Template and Connection decide how it
is executed.

| Content type | Workflow | Intent slugs and output types |
| --- | --- | --- |
| Project | `project-key-art` | `project-key-art` (image) |
| Story World | `world-key-art` | `world-key-art` (image) |
| Character | `character-look-set` | `character-full-view`, `character-front-view`, `character-three-quarter-view`, `character-profile-view`, `character-back-view`, `character-close-up` (images) |
| Prop | `prop-look-set` | `prop-full-view`, `prop-front-view`, `prop-three-quarter-view`, `prop-profile-view`, `prop-back-view`, `prop-close-up` (images) |
| Location | `location-look-set` | `location-full-view`, `location-front-view`, `location-three-quarter-view`, `location-profile-view`, `location-back-view`, `location-close-up` (images) |
| Shot | `shot-still-and-video` | `shot-representative-still` (image), `shot-video` (video) |
| Scene | `scene-filmstrip` | `scene-filmstrip` (image) |
| Episode | `episode-bookend-filmstrip` | `episode-bookend-filmstrip` (image) |

The first image in a recipe is eligible to become the source post's featured
image. Each view and each Shot output is an independent child job, so failures
and retries remain attributable. Scene filmstrips receive textual context from
ordered child Shots; Episode filmstrips receive context from the opening and
final Scenes. These composite prompts do not imply that the engine waits for or
automatically binds newly generated child images. Other generator-supported
post types retain the generic representative-image fallback.

The Project-only `demonstration` scope is a separate orchestration recipe. It
orders Story Graph Scenes and Shots and declares stable task keys for:

- one reusable still for each Character occurring in the story timeline and
  each referenced Location, with recurring Characters prioritized for
  continuity;
- a required representative still for every Shot, or a Scene/Project still
  when the story has no Shots;
- optional Sound cues, reusing a linked existing Sound Asset when present; and
- an optional moving version of each Shot.

A moving-shot task with a Character reference prefers character-reference
image-to-video, with recurring Characters ordered first. Otherwise it prefers
first-frame/last-frame video when a following Shot still exists, then
still-conditioned image-to-video, then text-to-video.
Symbolic `input_refs` make the current Shot still the start frame, the next Shot
still the end frame, and the first applicable Character reference the primary
I2V image;
the current Shot still remains the image fallback. This expresses intent and
allows task-aware Template selection. A provider receives only the media slots
required by the modality actually selected.

## Template resolution and preferences

A Template must be published, have `status = active`, produce the required
output type, belong to an available Connection, and resolve all required media
bindings for the task. Representative generation resolves each task through:

1. an explicit `image_template_id`, `video_template_id`, or
   `audio_template_id` in the request;
2. per-post `_worldgraph_generation_template_{intent}` metadata;
3. a site preference for the source CPT and intent;
4. a site preference for the `image`, `video`, or `audio` output type;
5. the managed local ComfyUI text-to-image Template for image output; and
6. the first runnable compatible Template.

Site preferences use the versioned option
`worldgraph_generation_preferences_v1`. Its supported shape is:

```json
{
  "intents": {
    "worldgraph_shot": {
      "shot-representative-still": 101,
      "shot-video": 202
    }
  },
  "outputs": {
    "image": 101,
    "video": 202,
    "audio": 303
  }
}
```

Values are `worldgraph_template` post IDs. Missing, partial, stale, or
incompatible mappings fall through to the next candidate. The
`worldgraph_generation_default_template_id` filter can alter a resolved
candidate. Filter implementations must return a Template suitable for the
task.

## Plans and durable batches

The story-workflow REST contract is:

```http
GET  /wp-json/worldgraph/v1/assets/generate/plan?post_id={id}&scope={item|project|demonstration}
POST /wp-json/worldgraph/v1/assets/generate/batches
GET  /wp-json/worldgraph/v1/assets/generate/batches/{id}
POST /wp-json/worldgraph/v1/assets/generate/batches/{id}/cancel
```

`scope=item` expands the selected post's recipe. `scope=project` requires a
Project and walks canonical `contains` and `belongs_to` ownership edges to
include the Project and each supported descendant once. `scope=demonstration`
also requires a Project and returns the ordered whole-story dependency and
assembly plan. A plan returns:

- `workflow`, `sources`, `total_jobs`, and image/video/audio `counts`;
- `tasks` with source identity, workflow, intent, label, type, featured flag,
  and `prompt_hash`, while omitting long provider prompts;
- `ready` and any Template `blockers`;
- image, video, and audio Templates runnable for applicable work in that plan,
  including each Template's sanitized `run_controls` contract and contextual
  `run_defaults` resolution;
- resolved `default_template_ids`; and
- `latest_batch`, when one exists for the same root and scope.

The start payload accepts `post_id`, `scope`, optional additive `base_prompt`,
optional `image_template_id`, `video_template_id`, and `audio_template_id`,
optional `image_run_values`, `video_run_values`, and `audio_run_values` objects,
and the required non-empty `idempotency_key`. The audio fields apply only to
audio tasks that require generation; a linked existing Sound Asset remains an
assembly input. The server refuses to start unless the requester can edit every
source, every required task resolves a runnable Template, and every submitted
run value validates against its explicitly selected per-type Template.
Plans are limited to 5,000 jobs by default;
`worldgraph_generation_batch_max_tasks` may change that bound.

The idempotency key is scoped to the requester and root batch request. Repeating
it returns the existing batch. The server atomically reserves the key and stores
a request fingerprint covering scope, additive instructions, Template
overrides, and normalized image/video/audio run values. This protects concurrent
starts and client retries from duplicate provider spending after a timeout or
lost response, while rejecting reuse for different settings.

## Batch storage, dependencies, assembly, and cancellation

A representative or demonstration batch is a parent `worldgraph_gen` record
with:

- `_worldgraph_gen_batch_kind = representative_media` or
  `demonstration_video`;
- `_worldgraph_gen_batch_scope`;
- `_worldgraph_gen_batch_plan`, a versioned frozen task list containing source,
  step, workflow, intent, output type, Template, prompt, prompt hash, and
  featured behavior, plus the saved-default snapshot, requested one-off
  snapshot, effective normalized run values, Project profile, run-control
  fingerprint, and effective prompt-policy fingerprint for that task. A
  demonstration snapshot additionally retains its stable task key, phase,
  required/optional flag, dependencies, symbolic media references, preferred
  modalities, generation-required flag, fallback, and editorial order;
- `_worldgraph_gen_assembly_plan`, the frozen demonstration timeline and its
  still, silence, and subtitle fallback policy;
- `_worldgraph_gen_batch_cursor`, which tracks bounded materialization;
- `_worldgraph_gen_workflow_version = 3`;
- `_worldgraph_gen_idempotency_key`;
- `_worldgraph_gen_request_hash`;
- requester, creation time, planned total, and aggregate status.

Each child remains an ordinary generation job and adds
`_worldgraph_gen_batch_id`, `_worldgraph_gen_batch_step`, and
`_worldgraph_gen_intent`. Status responses report the root and scope, aggregate
status, planned total, materialized/remaining/active/completed/failed/skipped/
cancelled counts, progress percentage, per-state counts, creation time, and
batch error.
Up to 200 child details are returned inline with source, intent, type, status,
attachment, and error; `jobs_truncated` marks a larger batch.

The coordinator-visible parent status is written only after the complete
frozen plan and a worker wake-up are verified. Idempotent retries re-establish
a missing wake-up or return a retryable scheduling error instead of silently
leaving a committed batch dormant. A child's runnable status is likewise
written last, after its prompt, Template, requester, intent, and batch
membership are durable.

Materialization verifies the supplied prompt against both the frozen prompt
and its SHA-256 digest before an already composed/profiled task may bypass live
prompt finalization. Version-3 tasks also require the current effective prompt
policy to match the frozen policy fingerprint; drift is rejected instead of
silently rewriting accepted work. Older version-2 tasks still require their
exact frozen prompt digest but cannot retroactively supply a policy snapshot.
For representative-media batches, the parent moves through
`batch_materializing`, `batch_activating`, and `batch_active`. WP-Cron creates
up to 20 non-runnable `staged` children per tick. Only after every task exists
does it promote up to 50 staged children to `queued` per tick, then continue
submitting and polling bounded numbers of jobs. A large Project batch may
therefore run for hours or days without one HTTP request remaining open.

Demonstration materialization is dependency-aware. Reference, still, and audio
children can be queued first; linked Sound Assets are represented without a
provider generation request. A moving-shot child is released only after the
completed media needed by its selected modality can be resolved. A missing or
failed optional video falls back to the Shot still in the assembly plan. A
missing linked or generated audio cue falls back to silence; dialogue or other
story text is retained as subtitle/title-card content when usable audio is
unavailable.
Stories without Shots can still be assembled from completed Scene or Project
stills. Optional work can be represented by a terminal skipped child so the
batch does not wait forever for an enhancement it cannot execute.

After all child work is terminal, the demonstration enters an assembly phase.
A separate WP-Cron hook claims one assembling batch independently of provider
submission and polling. `Rough_Cut_Assembler` checkpoints bounded phase/cursor
progress in `_worldgraph_rough_cut_state` between ticks, along with a verified
batch-specific work directory. An interrupted or long assembly can therefore
resume rather than intentionally holding the generation worker open. Each tick
initializes, attempts one segment/fallback, concatenates, handles subtitles,
mixes audio or silence, or imports the result. It normalizes usable clips and
still cards, orders them by the frozen editorial timeline, creates subtitle
graphics, mixes bounded linked or
generated audio cues where available, and imports an H.264 rough cut into the
Media Library. The worker heartbeat and stale-claim recovery prevent a live
assembly from being duplicated while allowing abandoned work to continue.
Pending status exposes the current stage and bounded progress; successful or
terminal-error cleanup removes private state and safe generated temporary
files. The status response exposes the assembly state, attachment URL on
success, and
bounded warnings or diagnostics on failure. This is an automatic demonstration
pass, not a claim that every provider result will be artistically acceptable.

Assembly requires an executable FFmpeg binary and PHP `proc_open`, a writable
WordPress temporary directory, and adequate storage/runtime limits. The binary
defaults to `ffmpeg` and can be configured with `WORLDGRAPH_FFMPEG_BINARY` or
the `worldgraph_ffmpeg_binary` filter. If FFmpeg is missing or cannot start,
the completed child media remains available and the batch reports an assembly
error and diagnostic instead of pretending a rough cut exists.

The delivered assembler executes FFmpeg in the WordPress PHP runtime. The
default Lando configuration therefore installs FFmpeg in `appserver`; the
separate `cli` service's binary is developer tooling and cannot be selected as
an automatic PHP fallback across the container boundary. A preferred ComfyUI
assembly backend still needs to be completed as a capability-gated Template
backed by a trusted, bounded assembly node. Ordinary ComfyUI HTTP does not
expose a generic FFmpeg command endpoint, and raw FFmpeg argument passthrough
must not be used as that contract.

Cancellation prevents remaining planned tasks from being activated, changes
already materialized `staged`, `queued`, or pre-dispatch `submitting` children
to `cancelled`, and reports that count in `stopped_queued` plus a human-readable
`cancel_note`. It also prevents a cancelled demonstration from starting a new
assembly, cooperatively stops a running FFmpeg stage, and removes a verified
between-tick assembly state. A job atomically enters `dispatching` immediately
before the provider call; dispatching, submitted, or terminal provider work
retains its actual lifecycle state and remains in the aggregate report.
Provider-side work that has already been dispatched may therefore finish even
after cancellation.

Generated files carry the same context outside WordPress through
`{project_slug|project-wp-slug}-{cpt-type}-{source-slug?}-{intent?}-job-{job_id}.{ext}`.
Media Library titles use the human-readable Project, CPT type, source, and
intent label, falling back to the media type when no intent exists.

## Security and operating boundaries

- Planning and batch operations use WordPress authentication, `upload_files`,
  and source-post edit capabilities.
- Starting a batch is the explicit budget-spending action; preview is read-only.
- Provider credentials remain on Connections or in environment references.
- Disabled Connections, output mismatches, and unresolved Template bindings
  remain hard blockers.
- An image-only installation can use Project, World, look-set, Scene, and
  Episode recipes. A representative Shot batch still requires its video
  output, while a demonstration can fall back from optional Shot motion and
  audio to its required stills, subtitles, and silence.
- World Graph Studio remains usable with no generation provider.

## Implementation references

- [Representative workflow registry](../../wordpress/wp-content/plugins/worldgraph/includes/utils/class-generation-workflows.php)
- [Assets metabox](../../wordpress/wp-content/plugins/worldgraph/includes/admin/asset-generator-metabox.php)
- [Assets REST controller](../../wordpress/wp-content/plugins/worldgraph/includes/rest-api/asset-generation-controller.php)
- [Asset generation service](../../wordpress/wp-content/plugins/worldgraph/includes/utils/class-asset-generator.php)
- [Template bindings](../../wordpress/wp-content/plugins/worldgraph/includes/utils/template_bindings.php)
- [Modality registry](../../wordpress/wp-content/plugins/worldgraph/includes/utils/generation-modality.php)
- [Generation Engine](GENERATION_ENGINE.md)
- [Rough-cut assembler](../../wordpress/wp-content/plugins/worldgraph/includes/utils/class-rough-cut-assembler.php)
- [Normalized prompt policy](../../wordpress/wp-content/plugins/worldgraph/includes/utils/class-generation-prompt-policy.php)
- [Prompt-policy compatibility filter](../../wordpress/wp-content/plugins/worldgraph/includes/utils/class-generation-prompt-profiles.php)
- [Generation run-default repository](../../wordpress/wp-content/plugins/worldgraph/includes/utils/class-generation-run-defaults.php)
