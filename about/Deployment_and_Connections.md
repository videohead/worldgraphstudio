# World Graph Studio Deployment and Connections

World Graph Studio keeps stories, Story Graph data, and specialist creative
advisors in WordPress. Generative media workflows can run through configured
tools including ComfyUI, fal, ElevenLabs, Suno, VideoDraft, and OpenRouter.
Neither a GPU nor a generation connection is required for writing, planning, continuity,
collaboration, asset tracking, JSON/FDX import, or Markdown export.
AI-assisted and generated-media features require the corresponding configured
service.

Delivered Connection implementations are complete within their documented
boundaries. Credentials, reachable services, models, quotas, and provider
accounts remain deployment concerns; experimental and scaffold integrations
are classified separately in [Delivery Status](Delivery_Status.md).

## Before You Start

Every World Graph Studio user needs:

1. A WordPress.org-capable host, WP Local, or a local Docker/Lando deployment.
2. Optionally, a local ComfyUI installation, Comfy Cloud account, fal account,
   ElevenLabs account, separate SunoAPI.org and AceData Cloud accounts, a
   VideoDraft account with a personal access token, an OpenRouter account with
   an API key, or another manually managed asset source.
3. Optionally, an API-connected LLM: a local OpenAI-compatible server such as
   llama.cpp, Ollama, vLLM, or LM Studio; or a hosted provider API such as
   OpenAI or Anthropic.

Browser-only subscriptions, including ChatGPT, Claude, and Claude Code subscriptions without an API credential, are not supported by the World Graph Studio server integration at this time. Hosted LLM providers require an API key; a local LLM must expose an OpenAI-compatible API endpoint and any credential it requires.

## Upgrading a renamed installation

Back up the WordPress database and uploads before upgrading from StoryOS. The
old plugin basename is `storyos/storyos.php`; the new basename is
`worldgraph/worldgraph.php`. Because WordPress cannot load a file after its
directory has moved, explicitly activate `worldgraph` after restoring the
database. Its activation path runs the serialization-aware compatibility
migration for supported post types, taxonomies, options, metadata,
relationships, SCF identities, capabilities, cron hooks, and plugin entries.

The Lando app name changed from `storyos` to `worldgraph`. Named database
volumes are scoped to the app identity and are not renamed automatically. With
the old Landofile still active, export first:

```bash
lando db-export scripts/pre-worldgraph-upgrade.sql.gz
```

After switching to the renamed checkout and starting the new app, import and
activate:

```bash
lando db-import scripts/pre-worldgraph-upgrade.sql.gz
lando wp plugin install secure-custom-fields --activate
lando wp plugin activate worldgraph
```

Do not use a raw SQL search-and-replace for this rename; WordPress options,
metadata, and SCF values can be serialized.

## Core Runtime

The standard deployment contains WordPress, MariaDB, and the World Graph Studio plugin. WordPress stores generation jobs and uses WP-Cron to process bounded batches.
A node-driven CLI container is also included for testing and development.
In the default Lando environment, FFmpeg is installed in the WordPress
`appserver` because the resumable rough-cut worker executes from PHP. The
separate CLI FFmpeg tooling cannot serve as an automatic cross-container
fallback.
The SCF plugin is also required in order to extend World Graph Studio capabilities.

## Connection Adapters

Provider implementations are registered in the World Graph Studio Connection adapter
manifest and loaded conditionally. An adapter loads when WordPress has a saved,
non-disabled Connection for its provider, or when an administrator explicitly
tests, saves, or otherwise invokes that provider. Changing an unsaved browser
selection does not load PHP. Merely installing World Graph Studio does not load
all provider API clients.

The Setup Wizard's **Preferred Connection** dropdown is generated from the same
manifest. It contains the small set of adapters that support guided setup;
additional provider types remain available on **World Graph Studio > Connections**. The
Adapters screen lists executable Connection adapters and their configured state,
but does not give them a second enable/disable control. Connection status is the
configured-startup and new-work authority; an explicit trusted code path can
still call the lazy loader to test or repair a Connection.

Third-party code can extend the manifest through
`worldgraph_conn_adapters`, provide a callable `loader`, and declare health,
post-save/admin, Template-provisioning, and generation-client capabilities.
The same manifest may declare guided setup choices with `setup_options`. The
`files` shorthand resolves paths inside the main World Graph Studio plugin and
is therefore intended for bundled implementations, not files owned by an
external plugin.

This adapter boundary is a core product capability: an integration can register
provider metadata, conditional loading, Connection testing, idempotent Template
provisioning, and generation dispatch without changing the Story Graph or
Connection schema. Endpoint URLs alone remain metadata: authentication,
protocol negotiation, allowed operations, job states, outputs, and safe media
handling must be implemented explicitly. See
[Adding Connections and Templates](Adding_Connections_and_Templates.md) for the
concise extension workflow.

For reliable production scheduling, invoke `wp-cron.php` from the host scheduler. Local Lando users can run due events with `lando wp-cron`.

After adopting a Lando configuration that newly installs FFmpeg in
`appserver`, run `lando rebuild -y`, then verify the PHP runtime with
`lando exec appserver -- ffmpeg -version`. A future ComfyUI-first assembly path
requires an explicitly installed, capability-checked World Graph assembly
node/Template; ComfyUI's normal HTTP API does not expose its internal FFmpeg
binary as a command service.

## ComfyUI MCP

Comfy Cloud uses its MCP execution path. A local ComfyUI deployment can use its
HTTP API for execution and a separate MCP server for template discovery and
model downloads.

### Reaching a local ComfyUI from Lando

WordPress runs inside the `appserver` container, so `localhost` in the ComfyUI
URL fields refers to that container, not your development host. Use Lando's
built-in host hostname instead:

- **ComfyUI API URL:** `http://host.lando.internal:8188`
- **LLM base URL** (Ollama, llama.cpp, LM Studio, vLLM): `http://host.lando.internal:11434/v1`

`host.lando.internal` (Lando ≥ 3.22) resolves to the Lando host machine from
every service in every running app, on every platform, without extra
Docker network or `extra_hosts` configuration. Prefer it over
`host.docker.internal`, which is Linux/Docker-Engine-version-specific and
only works when the app's Landofile explicitly adds
`extra_hosts: ["host.docker.internal:host-gateway"]`.

If ComfyUI runs in its own Docker Compose project rather than directly on the
host, publish its ports to the host (e.g. `"8188:8188"`) so `host.lando.internal`
can reach it — Lando does not automatically join unrelated Compose projects'
networks.

### Automatic Template discovery and model downloads

Setting **Local ComfyUI MCP URL** enables automatic Template discovery and
model installation, but ComfyUI's own HTTP API (port 8188) does not speak MCP —
appending `/mcp` to it will not work. That URL must point at a separate
streamable-HTTP MCP server process advertising at least a `download_models`
tool (and ideally `list_templates` / `get_template` for full auto-discovery).
Leaving it empty falls back to the built-in `Generation_Modality` catalog and
manual model installs.

## fal MCP

World Graph Studio can use fal as a hosted generation Connection through fal's Streamable
HTTP MCP endpoint at `https://mcp.fal.ai/mcp`. Configure the Connection with:

- Provider Type: `fal`
- Endpoint URL and MCP Endpoint URL: `https://mcp.fal.ai/mcp`
- Credential: a fal API key, or an `env://FAL_KEY` reference when the key is
  supplied to the WordPress runtime
- Model: an optional default fal endpoint ID
- Model Access: an optional JSON allowlist of endpoint IDs
- Environment: `production`

fal authenticates every MCP request with `Authorization: Bearer <FAL_KEY>`.
Testing the Connection performs MCP initialization and verifies that the server
advertises `submit_job` and `check_job`.

Each supported fal endpoint is represented by a World Graph Studio Template,
and World Graph Studio creates and updates these records automatically. Saving
a fal Connection schedules MCP catalog/schema discovery. Testing it performs
the same sync immediately. A Connection-level Model selects one endpoint;
Model Access is an authoritative JSON allowlist and provisions one Template per
endpoint. With neither configured, fal MCP supplies a current text-to-image
model. The built-in fal catalog maps discovered endpoints to text-to-image;
other fal modalities require an adapter extension.

The generated Template keeps runtime inputs separate from the full provider
schema in Configuration JSON:

```json
{
  "input": {
    "image_size": "landscape_16_9",
    "num_images": 1
  }
}
```

World Graph Studio supplies `prompt` and resolved Template input bindings at
runtime, submits the work with `submit_job`, polls with `check_job`, and imports
returned image URLs into the WordPress media library. A generation job is not
marked complete unless every returned media URL has been downloaded and stored
as a WordPress attachment.

## ElevenLabs API

World Graph Studio supports ElevenLabs as a conditionally loaded generative-audio Connection.
The guided Setup Wizard choice requires only an ElevenLabs API key; it creates a
Connection using `https://api.elevenlabs.io/v1`. A production deployment may
instead use `env://ELEVENLABS_API_KEY` as the Connection credential reference.

ElevenLabs authenticates with the `xi-api-key` request header. Saving or testing
the Connection reads `/v1/models` and `/v2/voices`, prefers
`eleven_multilingual_v2` when no Model is configured, and provisions active
Templates for:

- Text to speech (`POST /v1/text-to-speech/{voice_id}`), one Template per
  selected voice
- Text to dialogue (`POST /v1/text-to-dialogue`)
- Sound effects (`POST /v1/sound-generation`)
- Music (`POST /v1/music`)
- Voice design (`POST /v1/text-to-voice/design`)

Each Template stores that method's request defaults and provider schema under
Configuration JSON. To constrain speech voices, set Model Access to a JSON array
of voice IDs. Transformation and analysis APIs that require multipart source
media or asynchronous project lifecycles—such as Voice Changer, Audio Isolation,
Dubbing, and Speech to Text—are not treated as prompt-generation Templates.

Returned audio is written into the WordPress media library and linked to the
source Asset before generation is marked complete. Voice Design returns several
previews, so every preview is imported. Raw audio bytes are never persisted in
generation post meta.

## Suno API and MCP

World Graph Studio represents Suno generation with one `suno` Connection and
two separate third-party transports. SunoAPI.org REST uses
`https://api.sunoapi.org` and `credential_reference`; the AceData Cloud Suno
MCP server uses `https://suno.mcp.acedata.cloud/mcp` and
`mcp_credential_reference`. Each service issues its own bearer token. A key
from one service cannot authenticate the other, and neither is a browser-based
Suno subscription credential.

Saving or testing the combined Connection provisions separate REST and MCP
Templates for prompt music, custom music, and lyrics. REST tasks use the
provider's callback to wake the WordPress poller and are reconciled through the
record-info endpoints; MCP tasks are polled with `suno_get_task`. A music
request normally returns two tracks, and World Graph Studio imports every
final track into the media library before completing the generation record.

See [Suno Integration](plugins/SUNO.md) for credential setup, Template
contracts, callback-token behavior, polling, import, limits, and
troubleshooting.

## VideoDraft MCP and Project Sync

VideoDraft uses one `videodraft` Connection at
`https://app.videodraft.ai/api/mcp`. Configure a dedicated personal access
token in `credential_reference`, preferably as
`env://VIDEODRAFT_API_KEY`. WordPress calls the hosted JSON-RPC endpoint
directly; the VideoDraft Node CLI is a protocol reference and is not a runtime
dependency.

Saving or testing the Connection discovers its live MCP tool schemas and
provisions image, video, voiceover, audio, music, and sound-effect Templates.
Asynchronous image and video jobs are polled, while all completed media crosses
the WordPress Media Library boundary before job completion.

The bundled **VideoDraft Sync** plugin selects the same Connection. It provides
manual structural Project push and pull, dry-run import preview, checkpointed
remote updates, per-Connection mappings, and hash-based conflict detection.
See [VideoDraft Connection and Sync](plugins/VIDEODRAFT.md) for the mapped
subset and REST contract.

## Local ComfyUI HTTP API

World Graph Studio can reach a local ComfyUI server through its HTTP API. In the Setup
wizard, choose **Local ComfyUI HTTP API + MCP**, set the endpoint that is reachable
from WordPress, and use **Test ComfyUI** to check `/system_stats`. In a Lando
development environment where ComfyUI runs on the host, use
`http://host.lando.internal:8188`; `localhost` refers to the WordPress
container and will not reach the host service.


## LLM Connections

Configure the AI Editor in WordPress under **World Graph Studio > AI Settings**.

| Connection | Backend selection | Base URL | Credential |
| --- | --- | --- | --- |
| OpenAI | OpenAI API | Managed by World Graph Studio | OpenAI API key |
| Claude | Anthropic API | Managed by World Graph Studio | Anthropic API key |
| Ollama, vLLM, LM Studio | OpenAI-Compatible / Local LLM | The service's `/v1` endpoint | Optional or service-specific key |
| Hosted compatible API | OpenAI-Compatible / Local LLM | Provider's `/v1` endpoint | Provider API key |


## World Graph Studio Without ComfyUI

World Graph Studio remains useful without ComfyUI: creators can write, develop
story worlds, use configured specialist agents, plan production, manage
continuity, use delivered JSON import and Markdown export, build custom
editorial adapters on the EDL PHP format functions, and register or upload
assets from an external generator.

Web-based generation services such as Veo can be recorded as external asset
sources. Store their prompt, provider, model, source URL, usage rights, and
generated media as provenance. A provider requires an explicit WordPress
adapter before World Graph Studio can submit or poll it automatically. Veo,
Nova Reel, and similarly named provider choices are extension/configuration
surfaces, not built-in execution claims or scheduled delivery commitments.
