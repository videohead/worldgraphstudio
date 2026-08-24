# Generate Preferences and Generation Intents

> **Delivery status:** the provider-neutral representative-media registry,
> Template-resolution preferences, detailed Story Graph prompts, durable item,
> Project, and whole-story demonstration batches, dependency-aware fallback
> planning, FFmpeg rough-cut assembly, Template-conditional per-run controls,
> and their REST operations are delivered. Provider execution and final assembly
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
  motion and audio enhancements, then requests a rough-cut assembly.

Modes that are not defined for the current CPT are visibly unavailable. Each
available mode has its own output selector, contextual explanation, relevant
Template controls, and primary action; image, Sequence, and video operations
are not mixed in one ambiguous output menu.

The editable textarea contains only optional **Additional instructions for
this run**. The complete automatically composed provider prompt is available
in a collapsed read-only preview. For a Sequence, that preview lists the
planned outputs because each job receives a separately composed prompt.
Template selections are retained per selected image, video, or Sequence and
survive the read-only planning refresh before confirmation.
Selecting a Template also reveals only the per-run controls that Template
declares or that its provider schema/workflow exposes safely. Changing the
Template replaces that control set and resets values that no longer belong to
the selected Template.

Planning reports the number of image, video, and audio jobs, every source and creative
intent, prompt fingerprints, Templates runnable across the plan, resolved
defaults, and the latest batch. It performs no writes and spends no provider
budget.
Starting a batch revalidates permissions, Templates, Connections, and bindings
before any child job is queued.

The original endpoints remain compatible:

```http
GET  /wp-json/worldgraph/v1/assets/generate/prompt?post_id={id}
POST /wp-json/worldgraph/v1/assets/generate
```

## Detailed prompt contract

Project, Story World, Character, Prop, Location, Shot, Scene, and Episode expose
an optional SCF textarea named `generation_prompt`. It contains additional
media-generation instructions such as a house style, wardrobe or material
constraint, camera movement, or "no watermark." It is not a negative-prompt
transport field and does not replace the entity's authorial description.

The default composer reads this type-specific Story Graph context:

| Content type | Detailed fields included when populated |
| --- | --- |
| Project | description, genre, target medium, aspect ratio |
| Story World | synopsis, timeline, rules, themes, geography, references |
| Character | biography, age, appearance, personality, motivation, backstory |
| Prop | description, purpose, notes |
| Location | description, environment type, geography, mood |
| Shot | number, type, camera angle, lens, duration, description, editorial notes |
| Scene | number, summary, script content, dialogue, location, time of day, emotional tone, production notes |
| Episode | number, synopsis |

When no author-edited base prompt is supplied, the base composition order is:

1. for video, a generic motion-first direction and the intent's creative
   objective;
2. content type and title, followed by bounded inherited ancestor context;
3. non-duplicate excerpt and post content;
4. labeled detailed fields;
5. dependent Scene-shot or Episode-bookend context where applicable;
6. for non-video outputs, the intent's creative objective;
7. `generation_prompt`;
8. optional `base_prompt` text labeled **Additional request instructions**; and
9. output-specific continuity, detail, and no-watermark constraints.

The composer removes markup, renders select values and relationships readably,
deduplicates repeated core/SCF text, and applies one global 2,400-word bound.
`base_prompt` adds instructions without removing the assembled Story Graph
context or saved `generation_prompt`; in Project scope it applies to every
planned source. The
`worldgraph_generate_asset_prompt` filter runs last with the prompt, source
post, and intent.

After the server resolves the video Template, it prepends one idempotent
Template-family profile to this positive prompt. Wan receives an explicit
motion-first opening with subject action, camera movement, temporal progression,
cinematic lighting, and stable identity. LTX receives a one-shot chronological
action opening covering subject/environment motion, camera, lighting, and color
through the final frame. The LTX guidance includes dialogue, ambience, music,
or sound cues only for a workflow that generates audio. Negative suggestions
remain guidance for a distinct negative-conditioning input; they are never
silently appended to the positive prompt. These profiles change prompt text,
not sampler topology, model files, VAE, text encoder, resolution, frame rate,
steps, CFG, denoise, or motion controls stored in the Template workflow.

Inherited context uses a smaller visual map than the source record. In
particular, a Shot inherits its Scene summary, location, time of day, and
emotional tone, not the Scene's complete script or dialogue transcript.

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

Controls are conditional, not a universal form:

- `negative_prompt` is separate from the additive Story Graph prompt and is
  offered only when the Template has a negative-conditioning input;
- a fixed-seed integer appears when supported; leaving it unset preserves the
  Template/provider's existing randomization behavior, while an explicit `0`
  remains a fixed seed;
- sampling steps are bounded integers;
- classic diffusion CFG and FLUX-style guidance are separate concepts, and
  only the control appropriate to the discovered or declared workflow is
  shown;
- sampler and scheduler choices are restricted to values advertised or
  declared for the Template;
- width and height, and video duration or frames per second, appear only when
  the selected Template exposes those inputs; and
- additional text-conditioning channels, including dual-CLIP channels, appear
  only when their distinct inputs are discovered or explicitly declared. They
  are not inferred merely from a model-family label.

In the Assets metabox these fields stay inside the collapsed **Run controls
(optional)** disclosure. Supported output framing is prefilled from the owning
Project (`frame_width`, `frame_height`, `aspect_ratio`, and `frame_rate`), while
sampling and negative-conditioning defaults come from the selected Template.
An explicit edit wins over both. Untouched controls—including selects and
booleans with no declared default—are omitted from the request and continue to
inherit the Template/provider behavior.

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

For a direct run the client may send a `run_values` object keyed by the
selected Template's advertised fields. For a Sequence,
`image_run_values` and `video_run_values` may accompany the corresponding
explicit image or video Template override and apply to every task of that
output type. WordPress re-derives the v1 contract from the selected Template at
submission time, rejects unknown, nested, wrongly typed, out-of-range, or
non-allowlisted values, and passes only normalized scalar values to generation.
A non-empty batch values object without its matching Template ID is invalid.
An omitted or empty values object uses the Project output defaults where the
Template declares compatible controls, then retains the Template/provider
sampling and conditioning defaults.

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

## Template resolution and preferences

A Template must be published, have `status = active`, produce the required
output type, belong to an available Connection, and resolve all required media
bindings for the task. Representative generation resolves each task through:

1. an explicit `image_template_id` or `video_template_id` in the request;
2. per-post `_worldgraph_generation_template_{intent}` metadata;
3. a site preference for the source CPT and intent;
4. a site preference for the `image` or `video` output type;
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
    "video": 202
  }
}
```

Values are `worldgraph_template` post IDs. Missing, partial, stale, or
incompatible mappings fall through to the next candidate. The
`worldgraph_generation_default_template_id` filter can alter a resolved
candidate. Filter implementations must return a Template suitable for the
task.

## Plans and durable batches

The representative REST contract is:

```http
GET  /wp-json/worldgraph/v1/assets/generate/plan?post_id={id}&scope={item|project}
POST /wp-json/worldgraph/v1/assets/generate/batches
GET  /wp-json/worldgraph/v1/assets/generate/batches/{id}
POST /wp-json/worldgraph/v1/assets/generate/batches/{id}/cancel
```

`scope=item` expands the selected post's recipe. `scope=project` requires a
Project and walks canonical `contains` and `belongs_to` ownership edges to
include the Project and each supported descendant once. A plan returns:

- `workflow`, `sources`, `total_jobs`, and image/video `counts`;
- `tasks` with source identity, workflow, intent, label, type, featured flag,
  and `prompt_hash`, while omitting long provider prompts;
- `ready` and any Template `blockers`;
- `image_templates` and `video_templates` runnable across that plan, including
  each Template's sanitized `run_controls` contract;
- resolved `default_template_ids`; and
- `latest_batch`, when one exists for the same root and scope.

The start payload accepts `post_id`, `scope`, optional additive `base_prompt`,
optional `image_template_id` and `video_template_id`, optional
`image_run_values` and `video_run_values` objects, and the required non-empty
`idempotency_key`. The server refuses to start unless the requester can edit
every source, every image/video task resolves a runnable Template, and every
submitted run value validates against its explicitly selected per-type
Template.
Plans are limited to 5,000 jobs by default;
`worldgraph_generation_batch_max_tasks` may change that bound.

The idempotency key is scoped to the requester and root batch request. Repeating
it returns the existing batch. The server atomically reserves the key and stores
a request fingerprint covering scope, additive instructions, Template
overrides, and normalized image/video run values. This protects concurrent
starts and client retries from duplicate provider spending after a timeout or
lost response, while rejecting reuse for different settings.

## Batch storage, status, and cancellation

A representative batch is a parent `worldgraph_gen` record with:

- `_worldgraph_gen_batch_kind = representative_media`;
- `_worldgraph_gen_batch_scope`;
- `_worldgraph_gen_batch_plan`, a versioned frozen task list containing source,
  step, workflow, intent, output type, Template, prompt, prompt hash, and
  featured behavior, plus the normalized run values for that task;
- `_worldgraph_gen_batch_cursor`, which tracks bounded materialization;
- `_worldgraph_gen_workflow_version = 1`;
- `_worldgraph_gen_idempotency_key`;
- `_worldgraph_gen_request_hash`;
- requester, creation time, planned total, and aggregate status.

Each child remains an ordinary generation job and adds
`_worldgraph_gen_batch_id`, `_worldgraph_gen_batch_step`, and
`_worldgraph_gen_intent`. Status responses report the root and scope, aggregate
status, planned total, materialized/remaining/active/completed/failed/cancelled
counts, progress percentage, per-state counts, creation time, and batch error.
Up to 200 child details are returned inline with source, intent, type, status,
attachment, and error; `jobs_truncated` marks a larger batch.

The coordinator-visible parent status is written only after the complete
frozen plan and a worker wake-up are verified. Idempotent retries re-establish
a missing wake-up or return a retryable scheduling error instead of silently
leaving a committed batch dormant. A child's runnable status is likewise
written last, after its prompt, Template, requester, intent, and batch
membership are durable.
After start freezes the plan, the parent moves through
`batch_materializing`, `batch_activating`, and `batch_active`. WP-Cron creates
up to 20 non-runnable `staged` children per tick. Only after every task exists
does it promote up to 50 staged children to `queued` per tick, then continue
submitting and polling bounded numbers of jobs. A large Project batch may
therefore run for hours or days without one HTTP request remaining open.
Cancellation prevents remaining planned tasks from being activated, changes
already materialized `staged`, `queued`, or pre-dispatch `submitting` children
to `cancelled`, and reports that count in `stopped_queued` plus a human-readable
`cancel_note`. A job atomically enters `dispatching` immediately before the
provider call; dispatching, submitted, or terminal provider work retains its
actual lifecycle state and remains in the aggregate report.

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
  Episode recipes, but a Shot batch cannot start until its required video
  output also has a runnable Template.
- World Graph Studio remains usable with no generation provider.

## Implementation references

- [Representative workflow registry](../../wordpress/wp-content/plugins/worldgraph/includes/utils/class-generation-workflows.php)
- [Assets metabox](../../wordpress/wp-content/plugins/worldgraph/includes/admin/asset-generator-metabox.php)
- [Assets REST controller](../../wordpress/wp-content/plugins/worldgraph/includes/rest-api/asset-generation-controller.php)
- [Asset generation service](../../wordpress/wp-content/plugins/worldgraph/includes/utils/class-asset-generator.php)
- [Template bindings](../../wordpress/wp-content/plugins/worldgraph/includes/utils/template_bindings.php)
- [Modality registry](../../wordpress/wp-content/plugins/worldgraph/includes/utils/generation-modality.php)
- [Generation Engine](GENERATION_ENGINE.md)
