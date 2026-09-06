# World Graph Studio Setup Wizard

## Purpose

The setup wizard creates the managed Connections used by media generation and
the AI Editor. It is a first-run convenience, not a requirement for core Story
Graph authoring.

Current release status is defined in
[Delivery Status](../../../../../about/Delivery_Status.md). An optional service
being unconfigured does not make its World Graph Studio integration unfinished.

## Access and permissions

Activation sets a one-time redirect for administrators when
`worldgraph_setup_complete` is false. The page is always available at:

`/wp-admin/admin.php?page=worldgraph-setup`

Only users with `manage_options` may view, test, or save setup. Until the form
is submitted, other World Graph Studio admin pages redirect administrators back
to setup; unrelated WordPress admin screens remain accessible.

The form may be submitted with all optional service fields blank.

## Third-party services, accounts, and billing

Connections and Templates are configuration records stored on the WordPress
site. World Graph Studio and installed adapters may create or update those
records, but the records do not include an external service, provider account,
provider-owned workflow, API or model access, usage credits, compute, model
license, availability, or provider support. World Graph Studio does not own,
operate, maintain, or provide the third-party services, provider-owned
workflows, or paid models those records reference, and it does not collect
provider fees. Administrators must independently obtain and connect each
external service they choose to use.

For a hosted Connection that requires an API key or token, the administrator
is expected to:

1. Visit the selected provider's official developer or API portal and create
   an account directly with that provider.
2. Enable API access and provider billing. The provider may require a plan,
   prepaid credits, or acceptance of usage-based charges and bills the
   administrator directly; World Graph Studio does not handle those payments.
3. Generate a provider-issued API key or token and confirm that the account
   can access the intended model, workflow, or tool.
4. Enter that credential in the matching wizard field, test the Connection,
   and save the wizard.

Access to a model through a provider's web chat or consumer application is not
API access. A ChatGPT, Claude, Suno, or other browser subscription, login,
session, or cookie cannot replace a provider-issued API credential, and API
access or billing may be a separate product. Review the provider's current
pricing, terms, data practices, and model availability before connecting it.

For a local or self-hosted Connection, the administrator is responsible for
installing and maintaining the service and models, supplying the required
hardware, and complying with their licenses.

## Wizard sections

### 1. WordPress Runtime

This section confirms that WordPress is the application runtime and reminds the
operator to configure a reliable WP-Cron trigger. It is informational; the
wizard does not install a host scheduler.

### 2. Generation Connection (optional)

The preferred-Connection list is built from installed adapter metadata:

| Choice | Provider record | Fields and behavior |
| --- | --- | --- |
| Comfy Cloud MCP | `provider_type = comfyui`, production | Hosted provider credential; fixed MCP endpoint |
| Local ComfyUI HTTP API + MCP | `provider_type = comfyui`, local | Local HTTP URL plus optional separate MCP URL |
| fal MCP | `provider_type = fal`, production | fal API key; fixed MCP endpoint |
| ElevenLabs Generative Audio | `provider_type = elevenlabs`, production | ElevenLabs API key; fixed REST endpoint |
| Suno API + MCP | `provider_type = suno`, production | SunoAPI.org key plus a separate AceData Cloud MCP token; fixed REST and MCP endpoints |
| VideoDraft Cloud | `provider_type = videodraft`, production | VideoDraft PAT; fixed hosted MCP endpoint; generation plus optional Project sync |
| No generation connection yet | none | Does not create, update, or delete a managed generation Connection |

The entered hosted-provider credential is written to the managed Connection's
`credential_reference` field. The field is hidden for local ComfyUI and when
no provider is selected. Suno also displays a separate MCP-token field and
writes it to `mcp_credential_reference`; the two Suno providers do not share
bearer tokens.

#### Local ComfyUI fields

**Local ComfyUI API URL** is the normal ComfyUI HTTP endpoint. In the local
Docker Compose environment, use:

`http://host.docker.internal:8188`

**Local ComfyUI MCP URL** is optional and must point to a separate MCP server.
ComfyUI's HTTP API does not become MCP by adding `/mcp`.

Saving local setup ensures a managed text-to-image Template exists. After the
form is saved, the readiness panel reports missing nodes or checkpoints and
offers a recheck action.

#### Provider tests

**Test Generation Connection** uses the unsaved form values:

- local ComfyUI requests `/system_stats`;
- fal initializes MCP and checks the required generation tools;
- ElevenLabs reads the voice/model catalog;
- Suno checks the SunoAPI.org credit endpoint and the required AceData Cloud
  MCP tools with their separate credentials; and
- VideoDraft lists hosted MCP tools and verifies the generation and Project
  operations used by the integration; and
- Comfy Cloud is saved first and managed from the Connections screen.

Tests do not store the unsaved values. Saving is a separate action.

#### Catalog side effects

Saving:

- creates the managed local text-to-image Template for local ComfyUI;
- schedules fal Template provisioning;
- schedules ElevenLabs voice/model Template provisioning; or
- schedules six transport-specific Suno music, custom-music, and lyrics
  Templates; or
- schedules VideoDraft image, video, audio, voiceover, music, and sound-effect
  Templates from the live tool schemas.

ComfyUI provider-catalog sync and manual materialization remain available on
the saved Connection.

#### Generation selectors after setup

The wizard provisions or connects Templates; it does not choose a Project's
creative outputs. In a Story Graph editor, **Image** and **Video** show
compatible direct item actions, while **Sequence** reviews a multi-output item
recipe or a Project-wide representative-media queue. Project editors also show
**Demonstration** for the frozen whole-story plan. Its review step provides
separate compatible Image, Video, and generated-audio Template selectors; the
audio selector is shown only when a Sound task needs generation. Audio remains
unavailable as a direct item-output mode.

All queued work requires a reliable WP-Cron runner. Demonstration stitching
uses the separate `worldgraph_process_rough_cut_assembly` event and additionally
requires PHP `proc_open`, writable temporary/upload storage, and an executable
FFmpeg binary in the PHP runtime. FFmpeg defaults to `ffmpeg` and may be set
with `WORLDGRAPH_FFMPEG_BINARY`. These deployment prerequisites are not
installed or configured by the setup wizard.

### 3. External Generator Workflow

This section explains how to bring media from a provider's web application into
WordPress. It stores no provider credentials and creates no Connection.

### 4. LLM Connection

An LLM is required for the AI Editor and filmmaking advisors, not for core
World Graph Studio content.

The form stores:

- provider: `openai_compatible`, `openai`, `anthropic`, or `dual`;
- base URL;
- model identifier;
- API key/token;
- maximum response tokens; and
- temperature.

For a local service running on the Docker host, use a container-reachable URL,
for example `http://host.docker.internal:11434/v1`.

**Test LLM Connection** evaluates the current unsaved values. For a compatible
endpoint it can populate the model datalist from the provider response.

If the PHP constant `WORLDGRAPH_AI_API_KEY` is defined, the primary key field
is disabled and the constant is used for wizard testing. The wizard does not
expose separate cloud-fallback fields in the current form.

## Save flow

Selecting **Save All Configurations** performs a nonce and capability check,
then:

1. validates the generation choice against the adapter registry;
2. saves generation mode and local ComfyUI URL options;
3. creates or updates the managed `generation` Connection when selected;
4. schedules or performs the provider-specific Template bootstrap;
5. saves primary LLM and advanced response settings;
6. creates or updates the managed `llm` Connection;
7. sets `worldgraph_setup_complete` to true; and
8. redirects back with a success notice.

Managed records are identified by `worldgraph_wizard_slot`, so rerunning setup
updates them rather than creating another wizard-owned Connection.

## Stored state

### WordPress options

| Option | Purpose | Default/fallback |
| --- | --- | --- |
| `worldgraph_gen_connection_mode` | Current generation choice | `none` |
| `worldgraph_comfy_connection_mode` | Compatibility mirror of the choice | `none` |
| `worldgraph_comfy_local_url` | Local ComfyUI HTTP URL | form suggests `http://host.docker.internal:8188` |
| `worldgraph_comfy_local_mcp_url` | Optional separate local MCP URL | empty |
| `worldgraph_ai_backend` | Primary LLM backend | `openai_compatible` |
| `worldgraph_ai_url` | Primary LLM base URL | empty from a newly submitted blank form |
| `worldgraph_ai_model` | Primary model | empty |
| `worldgraph_ai_api_key` | Primary LLM key when no constant is defined | empty |
| `worldgraph_ai_max_tokens` | Response limit | `2048` in wizard |
| `worldgraph_ai_temperature` | Sampling temperature | `0.7` |
| `worldgraph_setup_complete` | First-run gate | false until saved |

The compatibility `worldgraph_comfy_connection_mode` option is written
alongside the current generation option. New code should use
`worldgraph_gen_connection_mode`.

### Managed Connection fields

The generation record stores the selected provider type, environment, endpoint,
MCP endpoint where applicable, credential, and `unverified` status. A Suno
record also stores its distinct AceData Cloud token in
`mcp_credential_reference`.

The LLM record stores the backend as provider type, endpoint, credential, model,
max tokens, and temperature. It uses the `llm` wizard slot.

## Credentials

### Primary LLM environment override

Map the deployment environment into a PHP constant in `wp-config.php`:

```php
define( 'WORLDGRAPH_AI_API_KEY', getenv( 'WORLDGRAPH_AI_API_KEY' ) ?: '' );
```

The environment variable by itself does not define a PHP constant.

### Generation credentials

The wizard accepts a provider credential and stores it on the managed
Connection. The fal, ElevenLabs, and VideoDraft adapters also resolve manually
configured `env://FAL_KEY`, `env://ELEVENLABS_API_KEY`, and
`env://VIDEODRAFT_API_KEY` references.

Suno requires two credentials. `credential_reference` accepts the SunoAPI.org
key or `env://SUNO_API_KEY`; `mcp_credential_reference` accepts the AceData
Cloud token or `env://ACEDATACLOUD_API_TOKEN`. A Suno website subscription,
browser session, or key from the other service is not a substitute.

Do not place secrets in tracked `.env` files, screenshots, logs, Template JSON,
or REST examples. Protect database backups because wizard-entered credentials
are persisted.

## Reopen or reset

You can revisit the page without resetting anything:

`/wp-admin/admin.php?page=worldgraph-setup`

To restore first-run redirect behavior:

```bash
docker compose exec wordpress wp option update worldgraph_setup_complete 0
```

Submitting the wizard sets it to true again.

## Verify saved configuration

```bash
docker compose exec wordpress wp option get worldgraph_gen_connection_mode
docker compose exec wordpress wp option get worldgraph_ai_backend
docker compose exec wordpress wp option get worldgraph_ai_model
docker compose exec wordpress wp option get worldgraph_setup_complete
```

Do not print credential options in a shared terminal log. Use the Connections
screen to test providers and inspect non-secret status fields.

## Troubleshooting

### The activation redirect did not appear

Open the setup URL directly. Redirects are intentionally skipped for AJAX,
cron, bulk activation, and users without `manage_options`.

### World Graph Studio pages keep returning to setup

Submit the form once, or verify:

```bash
docker compose exec wordpress wp option get worldgraph_setup_complete
```

### Local ComfyUI test cannot connect

- Use `host.docker.internal`, not `localhost`, for a service on the Docker
  host.
- Confirm the URL is the ComfyUI HTTP base, not an MCP URL.
- Confirm the host firewall and bind address allow the `wordpress` container.

### fal, ElevenLabs, Suno, or VideoDraft test succeeds but Templates are not visible

Saving schedules a single WP-Cron catalog event. Run due events and inspect the
Connection's provider configuration:

```bash
docker compose exec wordpress wp cron event run --due-now
```

Then review the Connection's last catalog sync/error fields.

For Suno, verify that six REST/MCP Templates were provisioned. Music audio
Templates can appear in a Project **Demonstration** review when its frozen plan
contains a compatible generated Sound task. They do not appear as direct item
outputs, and lyrics Templates do not supply an audio task.

### LLM test cannot find models

- Confirm the URL is reachable from WordPress.
- Use the correct OpenAI-compatible `/v1` base.
- Check the model endpoint's authentication requirement.
- Select or enter a model returned by the endpoint.

## Related documentation

- [Full Setup Guide](SETUP_GUIDE.md)
- [Plugin Architecture](ARCHITECTURE.md)
- [Generation Engine](../../../../../about/plugins/GENERATION_ENGINE.md)
- [Deployment and Connections](../../../../../about/Deployment_and_Connections.md)
- [Suno Integration](../../../../../about/plugins/SUNO.md)
- [VideoDraft Connection and Sync](../../../../../about/plugins/VIDEODRAFT.md)
