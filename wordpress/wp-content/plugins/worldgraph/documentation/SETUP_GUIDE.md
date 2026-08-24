# World Graph Studio Setup Guide

> The current release is complete. Setup determines which optional external
> services this installation can use; it does not unlock unfinished core
> features. See [Delivery Status](../../../../../about/Delivery_Status.md).

## Requirements

- WordPress 6.0 or later
- PHP 8.1 or later
- Secure Custom Fields (SCF), active before World Graph Studio
- A reliable WP-Cron trigger for asynchronous generation

WordPress 6.9 or later is required only for WordPress Abilities registration.
Provider accounts, API keys, ComfyUI, and an LLM are optional for core Story
Graph authoring.

## Install and activate

Place the plugin at:

`wp-content/plugins/worldgraph/worldgraph.php`

Activate it in WordPress Plugins or with the repository's Lando tooling:

```bash
lando wp plugin activate worldgraph
```

Activation checks SCF, registers the current `worldgraph` content contract,
schedules the generation worker, and redirects an administrator to:

`/wp-admin/admin.php?page=worldgraph-setup`

An installation upgraded from the old namespace must activate this renamed
plugin entry so the one-time compatibility migration can run. Back up the
database before an upgrade.

## First-run setup

The setup form has four functional areas:

1. WordPress runtime guidance.
2. An optional generation Connection.
3. A primary LLM Connection for AI advisors.
4. Instructions for manually generated external assets.

Submitting the form marks setup complete even when provider fields are empty.
That is valid: Projects, worlds, characters, locations, scenes, shots,
relationships, continuity, editorial planning, project interchange, and asset
management already present in the release do not require AI.

## Choose a generation Connection

The provider dropdown is supplied by registered Connection adapters.

### No generation connection yet

Choose this for Story Graph-only use or when generation happens in an external
web application. The wizard does not create or update the managed generation
Connection in this mode, and it does not delete Connections that already exist.

### Local ComfyUI HTTP API plus optional MCP

Use this when ComfyUI runs on the same workstation or a reachable private host.

For the repository's Lando environment, a ComfyUI process on the development
host is normally:

`http://host.lando.internal:8188`

Do not use `localhost` from WordPress; inside the appserver container it points
back to the container. Do not append `/mcp` to the ComfyUI URL. The optional
MCP field is for a separate MCP server process, for example:

`http://host.lando.internal:9000/mcp`

The wizard's test calls `GET /system_stats` on the entered HTTP endpoint.
Saving:

- stores the local HTTP and optional MCP URLs;
- creates or updates the managed `comfyui` Connection;
- creates the managed text-to-image Template; and
- exposes the ComfyUI readiness panel.

The readiness panel checks `/object_info` for the built-in text-to-image nodes
and an installed checkpoint. A bare HTTP-only ComfyUI works for the managed
local Template; model downloads remain manual unless a real MCP
`download_models` tool is configured.

For local video workflows such as LTX or WAN, the optional MCP service discovers
workflow JSON files from its configured template folder. Add or export the
workflow JSON there first, then sync the Connection catalog in WordPress. See
`about/how-to-text-to-video.md` for the step-by-step local ComfyUI sequence.

### Comfy Cloud MCP

The managed Connection uses:

`https://cloud.comfy.org/mcp`

Enter the provider API key as the generation credential. The wizard creates or
updates the managed `comfyui` Connection in the production environment. The
wizard does not issue a live Comfy Cloud test before saving; use the Connections
screen afterward to inspect the saved record and catalog/tool availability.

### fal MCP

The managed Connection uses:

`https://mcp.fal.ai/mcp`

Enter a fal API key. The wizard's test verifies that the MCP server advertises
the required asynchronous generation tools. Saving schedules Template
provisioning; testing the saved Connection also refreshes its endpoint schemas.

On the Connection record:

- `Model Access` can contain a JSON endpoint allowlist;
- `Model` selects a preferred endpoint; and
- with neither set, the adapter asks fal for a current text-to-image model.

For deployment-managed credentials, a manually edited Connection may use an
`env://FAL_KEY` reference.

### VideoDraft Cloud

The managed Connection uses:

`https://app.videodraft.ai/api/mcp`

Enter a VideoDraft personal access token. The wizard test calls `tools/list`
and verifies the generation and Project tools used by World Graph Studio.
Saving schedules live-schema Template provisioning for supported image, video,
audio, voiceover, music, and sound-effect tools.

A manually edited Connection may use `env://VIDEODRAFT_API_KEY`. Enable and
configure the separate **VideoDraft Sync** child plugin to push or pull the
shared structural Project subset. See [VideoDraft Connection and
Sync](../../../../../about/plugins/VIDEODRAFT.md).

### ElevenLabs

The managed Connection uses:

`https://api.elevenlabs.io/v1`

Enter an ElevenLabs API key. The wizard test reads the available voices and
text-to-speech models. Saving schedules catalog provisioning for active
Templates covering:

- text to speech;
- dialogue;
- sound effects;
- music; and
- voice design previews.

The Connection's `Model` selects the speech model. `Model Access` may contain
a JSON voice-ID allowlist. A manually edited Connection may use an
`env://ELEVENLABS_API_KEY` reference.

### Suno API plus AceData Cloud MCP

The managed `suno` Connection uses both:

- SunoAPI.org REST: `https://api.sunoapi.org`
- AceData Cloud Suno MCP: `https://suno.mcp.acedata.cloud/mcp`

These are separate third-party services. Enter the SunoAPI.org key in
**Generation Provider API Key** and the AceData Cloud token in **Generation
Provider MCP Token**. The credentials are different bearer tokens; neither a
Suno web subscription nor one of these tokens can authenticate the other
service.

The wizard test reads the SunoAPI.org credit balance, initializes MCP, and
verifies `suno_generate_music`, `suno_generate_custom_music`,
`suno_generate_lyrics`, and `suno_get_task`. Saving schedules provisioning of
six Templates: prompt music, custom music, and lyrics for REST, plus the same
three operations for MCP.

A manually edited production Connection may use `env://SUNO_API_KEY` in
`credential_reference` and `env://ACEDATACLOUD_API_TOKEN` in
`mcp_credential_reference`. See the [Suno Integration
guide](../../../../../about/plugins/SUNO.md) for transport-specific model IDs,
callbacks, polling, two-track imports, and limitations.

## Configure the primary LLM

An API-connected LLM enables the AI Editor and filmmaking advisors. The wizard
supports:

| Provider choice | URL behavior |
| --- | --- |
| OpenAI-compatible | Enter an OpenAI-compatible `/v1` base URL |
| OpenAI | Hosted API; URL may be blank |
| Anthropic | Hosted API; URL may be blank |
| Dual | Local primary with the configured cloud fallback settings |

For an LLM running on the Lando host, use a container-reachable URL such as:

`http://host.lando.internal:11434/v1`

Enter the model identifier, maximum response tokens, and temperature. **Test LLM
Connection** verifies the unsaved values and loads provider model names when the
endpoint reports them.

The PHP constant `WORLDGRAPH_AI_API_KEY` takes precedence over the wizard's
primary key field. Defining an environment variable alone is not enough;
`wp-config.php` must map it to the constant:

```php
define( 'WORLDGRAPH_AI_API_KEY', getenv( 'WORLDGRAPH_AI_API_KEY' ) ?: '' );
```

Fallback LLM options exist in the AI runtime, but the current setup form does
not expose separate fallback fields. Do not expect the wizard to save
`worldgraph_ai_fallback_*` options.

## What saving creates

**Save All Configurations**:

- stores the selected generation mode and local ComfyUI URLs as WordPress
  options;
- creates or updates one managed Connection with wizard slot `generation`
  when a provider is selected;
- stores the primary LLM options;
- creates or updates one managed Connection with wizard slot `llm`;
- schedules fal, ElevenLabs, Suno, or VideoDraft Template catalog work where
  applicable;
- provisions the managed local ComfyUI Template where applicable; and
- sets `worldgraph_setup_complete = true`.

The generation credential entered in this form is stored on the managed
Connection's `credential_reference` meta. For Suno, the separate AceData Cloud
token is stored in `mcp_credential_reference`. The LLM key is stored in
`worldgraph_ai_api_key` unless `WORLDGRAPH_AI_API_KEY` is defined. Use
deployment-managed secret references where the relevant adapter supports them,
protect database backups, and never commit credentials.

## Verify the installation

### Plugin and routes

```bash
lando wp plugin status worldgraph
lando wp option get worldgraph_version
```

The canonical REST base is:

`/wp-json/worldgraph/v1/`

### Connections

Open **Connections**, test the saved record, and confirm its status. A successful
test marks the Connection `verified`; a failed test marks it `error` and
stores the validation time.

Provider-specific behavior:

- local ComfyUI checks `/system_stats`;
- fal verifies MCP tools and synchronizes Templates;
- ElevenLabs verifies voices/models and synchronizes Templates;
- Suno verifies the SunoAPI.org credit endpoint and required AceData Cloud MCP
  tools, then synchronizes transport-specific Templates;
- VideoDraft verifies hosted MCP generation and Project tools, then
  synchronizes live-schema Templates;
- Comfy Cloud's Connection test currently verifies credential presence; catalog
  sync is the stronger MCP capability check; and
- LLM Connections run the configured LLM health test.

### Templates

Confirm at least one active Template is paired with the intended Connection.
For ComfyUI:

1. open the Connection and sync its catalog when using MCP discovery;
2. enable and materialize a provider entry when appropriate;
3. open the Template;
4. use **Check ComfyUI** for a local workflow; and
5. install any reported models/nodes, then recheck.

The **World Graph Studio Assets** metabox filters active Templates by the
selected action, available Connection, compatible modality, and resolvable
bindings. **Image** and **Video** expose direct outputs supported by the current
item. **Sequence** reviews and queues the item's multi-output recipe, or all
representative media for a Project. A Project also exposes **Demonstration**,
which plans the whole story and provides separate compatible Image, Video, and
generated-audio Template selectors. The audio selector appears only when the
frozen demonstration plan contains a Sound task that requires generation;
audio is not a direct item-output mode.

### WP-Cron

```bash
lando wp cron event list
lando wp cron event run worldgraph_process_generation_batch
```

In production, use the host scheduler to request `wp-cron.php` or run due
events with WP-CLI. A queued generation cannot progress if WP-Cron never runs.
Project Demonstration assembly also uses the separately scheduled
`worldgraph_process_rough_cut_assembly` event. The PHP runtime must allow
`proc_open`, have writable temporary/upload storage, and be able to execute
FFmpeg. The binary defaults to `ffmpeg`; define `WORLDGRAPH_FFMPEG_BINARY` when
an explicit safe path is required. Missing FFmpeg leaves the generated child
media available but reports an assembly error instead of a completed rough cut.

## Generate a test asset

1. Open a Project, Story World, Character, Location, Prop, Organization,
   Episode, Scene, Shot, Asset, or Editorial Artifact.
2. Find **World Graph Studio Assets**.
3. Choose **Image**, then select the exact image intent and active Template.
   A compatible item such as a Shot also offers **Video**; multi-output recipes
   offer **Sequence**. A Project additionally offers **Demonstration** with
   separate Image, Video, and, when required, generated-audio Template choices.
4. Review the generated prompt and leave the applicable featured-asset and
   linked-Asset choices enabled if desired.
5. Select the contextual create, queue, or demonstration-review action.
6. Confirm the queued job in the Generation Log, run WP-Cron if necessary, and
   reload the post after completion.

The result is imported into the WordPress media library before the job reaches
`completed`.

## External-generator workflow

No direct adapter is required to track externally generated work:

1. Generate media in the provider's own application.
2. Download the final file.
3. Retain the provider, model, prompt, source, and rights information.
4. Upload the file to WordPress.
5. Add it as featured media or to the World Graph Studio asset gallery and
   record provenance on the Asset.

Browser subscriptions and web-login sessions are not server API credentials.
Hosted providers may impose their own costs, quotas, licenses, or moderation.

## Reconfigure later

The setup page remains directly accessible:

`/wp-admin/admin.php?page=worldgraph-setup`

Resetting the completion flag is only necessary if you want World Graph Studio
admin screens to redirect back to setup:

```bash
lando wp option update worldgraph_setup_complete 0
```

Advanced provider fields, additional Connections, health tests, and ComfyUI
catalog controls live on the Connections screen.

## Troubleshooting

### World Graph Studio reports SCF is missing

Install and activate `secure-custom-fields/secure-custom-fields.php`, then
activate World Graph Studio again.

### WordPress cannot reach local ComfyUI

- Use `host.lando.internal` from the Lando appserver.
- Confirm ComfyUI listens on a host interface reachable by the container.
- Check the Connection's `endpoint_url`, not only the legacy option.
- Test `/system_stats` from the WordPress runtime.
- Keep any unauthenticated endpoint private.

### MCP catalog sync fails

- Confirm `mcp_endpoint_url` points to a real MCP server.
- Check whether `tools/list` advertises `list_templates`.
- A partial MCP server is reported as tier `b`.
- Leave the MCP URL blank for the HTTP-only local catalog and managed
  text-to-image path.

For Suno, also confirm that the MCP field contains an AceData Cloud token, not
the SunoAPI.org key. The combined Suno Connection test fails when either the
REST credit check or required MCP-tool check fails.

### No Template appears in the Assets metabox

- Confirm the Template is published with `status = active`.
- Confirm its modality and output type match the selected **Image**, **Video**,
  **Sequence**, or Project **Demonstration** task.
- Confirm its `connection_id` exists and is not disabled.
- Confirm Template and Connection have the same `provider_type`.
- Resolve required `input_bindings` from the source post, or confirm the
  demonstration plan supplies the required generated media reference.
- For generated audio, use a Project Demonstration containing a matching Sound
  task; audio Templates are not direct item actions.

### Jobs remain queued

- Verify the `worldgraph_process_generation_batch` cron event exists.
- Run it once with WP-CLI and inspect the Generation Log.
- Confirm the selected Connection has a supported adapter and credential.
- Confirm the Template has a provider template/endpoint ID or is the managed
  local ComfyUI Template.

### LLM test fails

- Use a URL reachable from the WordPress container.
- Include the correct OpenAI-compatible base path.
- Enter a model the endpoint actually exposes.
- Confirm `WORLDGRAPH_AI_API_KEY` is a PHP constant when using the
  environment override.

## Related documentation

- [Setup Wizard Guide](SETUP_WIZARD_GUIDE.md)
- [Plugin Architecture](ARCHITECTURE.md)
- [Generation Engine](../../../../../about/plugins/GENERATION_ENGINE.md)
- [ComfyUI Template Catalog](../../../../../about/plugins/COMFY_TEMPLATE_CATALOG.md)
- [Deployment and Connections](../../../../../about/Deployment_and_Connections.md)
- [Suno Integration](../../../../../about/plugins/SUNO.md)
