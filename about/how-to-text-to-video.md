# Text to Video with Local ComfyUI

Use this sequence to make local WAN 2.2 and LTX 2.5 workflows available through
a World Graph Studio ComfyUI Connection and to keep their model and run settings
intact.

The local integration has two distinct paths:

- the **ComfyUI HTTP API** executes the saved workflow;
- an optional, separate **ComfyUI MCP service** discovers workflow JSON files
  and can advertise model downloads; and
- World Graph Studio turns a discovered workflow into a generation Template and
  submits a per-job copy of it to ComfyUI.

The MCP service is deployment-specific and is not part of the standard ComfyUI
HTTP server. Local generation is submitted directly to ComfyUI's `POST /prompt`
endpoint; MCP is not a second generation transport for a local Connection.

MCP discovery does not invent a workflow from installed nodes, crawl every
ComfyUI screen, or save a new workflow into ComfyUI. A workflow must already
exist as a `.json` file in the MCP service's configured template folder. After
that, catalog discovery and Template creation can be automatic.

## What can be automatic

Once a workflow JSON file is visible to the MCP service, World Graph Studio can
refresh the catalog, enable mappable entries, materialize Templates, check local
requirements, and ask MCP to download advertised model URLs.

The first workflow-authoring step is not automatic. Start with a workflow that
matches the exact model variant and task, configure it in ComfyUI, and export it
in API format. ComfyUI's documented execution path submits that existing graph
to `POST /prompt`; World Graph Studio does not choose a WAN or LTX graph, install
custom nodes, or replace model-loader selections on your behalf.

## 1. Configure local endpoints

In WordPress Admin, open **World Graph Studio > Setup & Settings** and select
**Local ComfyUI HTTP API + MCP**.

- Local ComfyUI API URL: `http://host.lando.internal:8188`
- Local ComfyUI MCP URL: `http://host.lando.internal:9000/mcp`

If your MCP service runs on a different host, port, or path, use that real
value. Do not append `/mcp` to port `8188` unless a separate MCP server is
actually listening there.

## 2. Save and open the managed Connection

Saving setup creates or updates the managed local ComfyUI Connection with both:

- `endpoint_url` for the ComfyUI HTTP API; and
- `mcp_endpoint_url` for the optional, separate MCP service.

## 3. Start from the matching official workflow

Update ComfyUI before loading a recent video template. In ComfyUI, open
**Workflow > Browse Workflow Templates** and choose the workflow for the exact
task: text-to-video, image-to-video, or first-frame-to-last-frame. If the WAN
templates or required core nodes are absent, update ComfyUI and restart it.

Do not change a workflow merely because another variant has a similarly named
model. The workflow's loader nodes, conditioning graph, sampler chain, and
high/low-noise routing form one compatible unit.

### WAN 2.2 5B hybrid

The official **Wan2.2 5B** TI2V workflow uses one hybrid diffusion model for
text-to-video and image-to-video and is the smaller starting point. The official
ComfyUI workflow currently selects:

| Loader | File | ComfyUI folder |
| --- | --- | --- |
| Diffusion model | `wan2.2_ti2v_5B_fp16.safetensors` | `models/diffusion_models` |
| Text encoder | `umt5_xxl_fp8_e4m3fn_scaled.safetensors` | `models/text_encoders` |
| VAE | `wan2.2_vae.safetensors` | `models/vae` |

Use a compatible FP16 UMT5 encoder only when the platform cannot run the FP8
encoder, select that installed file in the workflow's loader, test it locally,
and export the resulting workflow. A filename mentioned in a prompt does not
change the loader.

### WAN 2.2 14B expert workflows

WAN 2.2 14B uses separate high-noise and low-noise diffusion models. Choose the
official workflow and model pair for the task:

| Workflow | Diffusion models in `models/diffusion_models` | Shared encoder and VAE |
| --- | --- | --- |
| **Wan2.2 14B T2V** | `wan2.2_t2v_high_noise_14B_fp8_scaled.safetensors` and `wan2.2_t2v_low_noise_14B_fp8_scaled.safetensors` | `umt5_xxl_fp8_e4m3fn_scaled.safetensors` in `models/text_encoders`; `wan_2.1_vae.safetensors` in `models/vae` |
| **Wan2.2 14B I2V** | `wan2.2_i2v_high_noise_14B_fp16.safetensors` and `wan2.2_i2v_low_noise_14B_fp16.safetensors` | `umt5_xxl_fp8_e4m3fn_scaled.safetensors` in `models/text_encoders`; `wan_2.1_vae.safetensors` in `models/vae` |
| **Wan2.2 14B FLF2V** | Use the I2V model locations selected by the official first/last-frame workflow | Use the encoder and VAE selected by that workflow |

The 14B workflows intentionally use the compatible `wan_2.1_vae.safetensors`.
Do not replace it with the 5B workflow's `wan2.2_vae.safetensors` as a blanket
quality tweak. Also preserve the high- and low-noise branches; one generic run
control must not collapse intentionally different stage values.

See the [official ComfyUI WAN 2.2 guide](https://docs.comfy.org/tutorials/video/wan/wan2_2)
for current templates, downloads, and task-specific instructions.

### LTX 2.5 distilled workflows

Use the official LTX 2.5 workflow for the intended input shape. The current
official text-to-video graph is a subgraph-based workflow with its own distilled
sampling and dual-CFG design. Its model inputs currently include:

| Loader | File | ComfyUI folder |
| --- | --- | --- |
| Diffusion model | `ltx-2.5-22b-distilled-transformer-comfy-int8-convrot.safetensors` | `models/diffusion_models` |
| Text encoder | `gemma4-12b-with-proj-ltx-2.5-comfy-int8-convrot.safetensors` | `models/text_encoders` |
| Video VAE | `ltx-2.5-video-vae-bf16.safetensors` | `models/vae` |
| Audio VAE | `ltx-2.5-audio-vae-bf16.safetensors` | `models/vae` |
| Latent spatial upscaler | `ltx-2.5-latent-spatial-upscaler-x2-bf16-1.0.safetensors` | `models/latent_upscale_models` |
| Optional prompt enhancer | `gemma4_e2b_it_int8_convrot.safetensors` | `models/text_encoders` |

The first/last-frame and other LTX variants may expose a different subset of
optional loaders. Treat the selected workflow as authoritative rather than
adding every file in this table to every graph. Current source workflows are
available as [LTX 2.5 text-to-video](https://github.com/Comfy-Org/workflow_templates/blob/main/templates/video_ltx2_5_t2v.json)
and [LTX 2.5 first/last-frame](https://github.com/Comfy-Org/workflow_templates/blob/main/templates/video_ltx2_5_flf2v.json)
templates.

LTX raw latent dimensions must be divisible by 32, and a literal frame count
must satisfy `8n + 1` (for example, 121 or 249). Some official subgraphs expose
friendly output sizes or duration controls and normalize the internal values;
use those selectors instead of forcing an incompatible literal. When duration
and frame rate imply a different count, expect the workflow to round to a valid
frame count.

### Tuning starting points, not replacement workflows

For a compatible WAN workflow that exposes the corresponding scalar inputs, a
useful quality baseline is 1280x720, 24 fps, 20-25 steps, and CFG 5-7. Try
`dpmpp_2m` with `karras` for a quality pass or `euler_ancestral` for a faster
iteration. Use `dpmpp_3m_sde` only when that sampler is available in the local
ComfyUI graph. Start with a random seed while exploring, then fix the seed when
comparing one setting at a time. These are tuning starting points, not a reason
to overwrite a workflow's custom sampler or expert-stage defaults.

The following LTX values are third-party/operator starting points, not official
LTX 2.5 defaults. Use them only if the imported workflow exposes equivalent
controls and a test confirms that they reach the intended nodes:

| Preset | Sampler/scheduler | Steps | CFG | Denoise | Motion | Requested output | FPS |
| --- | --- | ---: | ---: | ---: | ---: | --- | ---: |
| Balanced | DPM++ 2M / Karras | 24 | 4.5 | 0.60 | 0.55 | 1920x1080 | 50 |
| Hero | Keep the workflow's selected sampler | 36-40 | 4.2 | 0.55 | 0.45 | 2560x1440 | 50 |
| Preview | UniPC | 16 | 4.0 | 0.65 | 0.50 | 768x432 | 24 |

Do not apply these presets over the official distilled/custom sampler graph by
default. `motion` is not a generic World Graph Studio workflow control; it has
an effect only when a particular graph exposes and maps such an input. Denoise,
CFG, steps, sampler, and scheduler are likewise available only when the graph
has safely mutable equivalents.

The requested 1920x1080 and 768x432 heights are not divisible by 32. Select a
workflow-provided output preset that performs compatible internal rounding, or
choose supported raw dimensions near the requested aspect and verify the
actual result. The Hero size, 2560x1440, is divisible by 32 but can require much
more memory. At 50 fps, also make sure the resulting frame count remains
`8n + 1`; do not assume `seconds * fps` is already valid.

## 4. Write prompts for motion and temporal coherence

Keep the prompt literal, continuous, and internally consistent. Repeating a
small style anchor such as `cinematic lighting, natural motion, coherent color`
is usually more useful than stacking unrelated style keywords.

### WAN 2.2: motion first

Put camera and subject motion before static appearance details. Name temporal
changes explicitly:

```text
Camera [pans/dollies/tracks] [direction and speed] as [subject] [main action].
Then [next action or change] while [secondary motion]. [Setting and essential
appearance details]. Cinematic lighting, natural motion, coherent color and
production design, stable subject identity, high detail.
```

Example:

```text
Camera tracks slowly forward as a red-cloaked girl walks along the forest path,
her basket swinging naturally. Then she pauses and turns toward a sound while
leaves stir behind her. Dawn mist, warm rim light, cinematic lighting, natural
motion, coherent color and production design, stable subject identity.
```

Keep a separate negative prompt when the workflow has a negative-conditioning
branch. A general starting point is `flicker, temporal jitter, warped anatomy,
duplicate limbs, watermark, logo, subtitles`. Append `static, frozen,
motionless` only when a shot that should move keeps becoming unnaturally still.
Omit those terms for a deliberately locked-off, still, held, or pose-driven
shot; they are not universal quality negatives.

### LTX 2.5: action first and chronological

LTX prompting benefits from one flowing paragraph that starts directly with
the main action, uses active verbs, and describes events in order with terms
such as `as`, `then`, and `while`. Add camera movement only when it is intended.
For an audio-producing workflow, place sounds alongside the actions that cause
them instead of collecting audio directions at the end.

Text-to-video template:

```text
[Subject] is [main action]. As [next event], [specific movement and gesture];
then [change or reaction] while [background action]. [Requested camera angle or
movement]. [Precise setting and appearance]. [Lighting, color, and style].
[Sound synchronized with the relevant action, when the workflow produces audio].
```

Image-to-video template:

```text
[Subject in the source image] begins [the first visible change]. As [motion
continues], [secondary change]; then [final state]. [Requested camera movement].
[Chronological sound cues, when applicable].
```

For image-conditioned generation, describe changes from the source image rather
than restating every visible detail; contradictory descriptions can cause a
visual reset or cut. The optional LTX prompt enhancer can expand a short
positive prompt, but it does not replace careful input intent or alter a
negative prompt. Lightricks' current guidance is available in its
[text-to-video prompt enhancer instructions](https://github.com/Lightricks/LTX-2/blob/main/packages/ltx-core/src/ltx_core/text_encoders/gemma/encoders/prompts/gemma_t2v_system_prompt.txt).

## 5. Understand workflow-owned and per-run selections

World Graph Studio does not infer model loaders from prompt keywords. There are
two different kinds of selection:

| Selection kind | Examples | Where it is set |
| --- | --- | --- |
| Workflow-owned | diffusion model/UNET, text encoder, VAE, LoRA, latent upscaler, high/low-noise routing, custom sampler chain, conditioning topology, video encoder/codec | Configure in ComfyUI, test, then export the workflow. The choice is baked into the saved JSON. |
| Safe scalar run control | positive/negative prompt, seed, steps, CFG or guidance, sampler, scheduler, denoise, width, height, aspect ratio, length/duration, fps | World Graph Studio exposes it only when the stored workflow contains a safely mutable target. A submitted value changes the per-job copy sent to `POST /prompt`. |

A per-run selection does not modify the workflow file in the MCP template
folder or update the graph currently open on the ComfyUI canvas. Conversely,
changing a model name in ComfyUI after export does not update the World Graph
Studio Template; export and materialize the changed workflow again.

If a desired scalar does not appear as a run control:

1. Open the source workflow in ComfyUI.
2. Confirm that the executable node has a literal scalar input with a recognized
   name such as `steps`, `cfg`, `sampler_name`, `scheduler`, `width`, `height`,
   `length`, or `frame_rate`.
3. If the value is linked, expose it through a clearly titled primitive or
   subgraph input such as **Steps**, **CFG**, **FPS**, **Width**, or **Height**.
4. Keep intentionally different multi-stage values separate. World Graph Studio
   will not advertise one control when applying it would erase that distinction.
5. Run the graph successfully in ComfyUI, export API format again, and refresh
   and materialize the catalog entry again.

Custom names such as `motion_strength` are not automatically equivalent to a
generic motion control. They require explicit provider-adapter support and a
workflow contract that maps the value to the intended node; adding the name to
a prompt or schema is not sufficient.

### Features that must be designed into the workflow

Per-region CFG, chunked generation, multiple reference images, blended
text/image/motion conditioning, first/last-frame interpolation, latent
upscaling, and H.264 export are graph-level capabilities. Keywords alone cannot
add their nodes or wiring. Build and test those features in ComfyUI first, then
export that graph. For a long clip, use a workflow designed for chunking or
generate coherent segments and assemble them downstream; increasing a duration
field does not automatically prevent temporal artifacts. To deliver 720p H.264,
configure and test the workflow's resize and video-save/encode nodes before
export; World Graph Studio imports the workflow output rather than silently
transcoding it into that delivery format.

## 6. Export API-format JSON and add it to MCP

ComfyUI's API documentation recommends API-format workflows for programmatic
execution. Use API format whenever possible.

| Format | How to create it | Best use |
| --- | --- | --- |
| API workflow JSON | **File > Export Workflow (API)** | Recommended for World Graph Studio generation; this is the graph shape accepted by ComfyUI's `/prompt` API. |
| UI workflow JSON | **File > Save** or `Ctrl+S` | Useful for reopening and editing in ComfyUI. Export API format before relying on it for generation. |

API workflow JSON has top-level numeric node IDs and `class_type` values. UI
workflow JSON has a top-level `nodes` array plus layout information. A recent
official template may contain subgraphs in its UI form, so load and test it in
an updated ComfyUI before exporting the executable API form.

The MCP server discovers templates by reading JSON files from the template
folder configured for that service. The path is deployment-specific. For
example, one repository development setup may point the separate service at:

`/home/videohead/www/ComfyUI/user/default/workflows`

Do not assume that path exists on another machine. Confirm the MCP service's
configuration, then save one workflow per file, for example:

- `wan-2-2-5b-text-to-video.json`
- `wan-2-2-14b-text-to-video.json`
- `ltx-2-5-text-to-video.json`

To add a workflow:

1. Load the official workflow in ComfyUI and select the installed model files.
2. Enter recognizable test prompts and run it successfully.
3. Choose **File > Export Workflow (API)**.
4. Place the exported `.json` file directly in the configured MCP template
   folder.
5. Restart the MCP service if it caches its file list.

If you accidentally use **File > Save**, reopen that UI-format file in ComfyUI
and export it again with **File > Export Workflow (API)**.

## 7. Prepare provider Templates

From the Connection configurator:

1. Select **Refresh Available Workflows**.
2. Select **Add All Ready Workflows**.

This performs the guided catalog flow: refresh, enable, then materialize every
mappable entry. For this to be fully automatic, the MCP server should advertise:

- `list_templates`;
- `get_template`; and
- `download_models` when provider-managed model downloads are expected.

If the new workflow does not appear, check that:

- the file is directly inside the MCP template folder rather than a nested
  folder;
- the filename ends in `.json`;
- the JSON is valid API-format workflow data;
- the MCP service is using the same template folder you edited; and
- the MCP service was restarted if it caches its file list.

## 8. Validate requirements and verify submitted values

Open each generated Template and select **Check ComfyUI**.

- If download URLs are available, use **Download Requirements**.
- If URLs are not advertised, install missing files manually in the reported
  ComfyUI model folder and re-check.
- If the report names missing nodes, install or update the matching node package,
  restart ComfyUI, and check again. World Graph Studio does not install custom
  nodes automatically.

The requirement report checks the node classes and recognized model filenames
stored in the workflow. Follow that report instead of guessing from the
workflow name. In ComfyUI, also open every model loader before export and verify
that it names the intended 5B, 14B high/low, or LTX 2.5 files.

To verify scalar selections end to end:

1. Queue a short test with a fixed seed, an unmistakable prompt, and visibly
   different safe values such as steps or fps.
2. If job details or logs show the returned ComfyUI prompt ID, note it;
   otherwise identify the test by its submission time and prompt.
3. Inspect that job in ComfyUI's Queue/History view, or use its prompt ID with
   `GET /history/{prompt_id}` on the configured local ComfyUI server.
4. Confirm the executed prompt graph contains the requested values on the
   intended nodes and still contains the expected model-loader filenames.
5. Repeat with only one setting changed when comparing output quality.

If ComfyUI receives the prompt but a value is absent, return to the workflow and
expose a mutable input as described above. If ComfyUI receives the value but the
node ignores it, that is a workflow/node contract issue rather than a prompt
keyword issue.

## 9. Headless workflow (optional)

The catalog flow is also available through REST for headless UIs:

- `POST /wp-json/worldgraph/v1/connections/{id}/catalog/sync`
- `POST /wp-json/worldgraph/v1/connections/{id}/catalog/prepare`
- `POST /wp-json/worldgraph/v1/connections/{id}/catalog/entries/{entry_id}/materialize`

The REST flow still depends on the workflow JSON already being visible to the
MCP service. It automates WordPress catalog refresh and Template creation; it
does not upload a new ComfyUI workflow JSON into the MCP template folder.

## ComfyUI documentation references

ComfyUI documents the same execution boundary in its API workflow guide: use
**File > Export Workflow (API)** when a workflow will be submitted through an
API. Its server examples load that JSON and send it to `POST /prompt` as the
`prompt` payload.

- [Workflow API format](https://docs.comfy.org/development/api-development/workflow-api-format)
- [Server API examples](https://docs.comfy.org/development/comfyui-server/api-examples)
- [ComfyUI server routes](https://docs.comfy.org/development/comfyui-server/comms_routes)
- [Official WAN 2.2 workflow guide](https://docs.comfy.org/tutorials/video/wan/wan2_2)
- [Official ComfyUI workflow templates](https://github.com/Comfy-Org/workflow_templates)
- [LTX 2.5 model card and constraints](https://huggingface.co/Lightricks/LTX-2.5)

## Node and npm note

Use container-managed Node/npm for project commands (Lando `cli` or `headless`
services). Avoid installing or changing host Node versions unless you are
intentionally running the headless app outside Lando.
