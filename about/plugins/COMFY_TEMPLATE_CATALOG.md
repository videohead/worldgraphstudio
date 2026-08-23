# ComfyUI Template Catalog

> **Delivery status:** catalog discovery, curation, materialization, requirement
> checks, and provider-side download requests are implemented for the current
> release. This document describes the shipped behavior, not a future epic.
> See [Delivery Status](../Delivery_Status.md).

## What the catalog does

The catalog lets an administrator discover what a specific ComfyUI Connection
advertises without turning every provider example into a WordPress Template.
It keeps three records distinct:

| Record | Purpose |
| --- | --- |
| Connection | A `worldgraph_conn` post containing the endpoint, environment, credential, and provider policy |
| Catalog entry | A cached provider or built-in descriptor of something the Connection could offer |
| Template | A curated `worldgraph_template` post that generation requests can select |

A catalog entry is inert. Enabling it records an allowlist choice; materializing
it creates or updates the corresponding World Graph Studio Template.

## Capability tiers

`Comfy_Cloud_MCP::capability_tier()` probes each Connection independently:

| Tier | Meaning | Catalog source | Download behavior |
| --- | --- | --- | --- |
| `a` | MCP exposes `list_templates`, `get_template`, and `download_models` | Provider MCP catalog | Provider-side download requests available |
| `b` | MCP is reachable but exposes only part of the template toolset | Advertised tools only | Available only when `download_models` is advertised |
| `c` | No MCP endpoint is configured | Registered modalities plus local `/object_info` | Manual installation through ComfyUI |
| `unreachable` | Configured MCP endpoint could not be probed | Local synthesized catalog when possible | MCP action reports the connection error |

Comfy Cloud normally uses an MCP tier. A local ComfyUI can be:

- HTTP-only, using its normal API at the Connection's `endpoint_url`; or
- paired with a separate MCP service in `mcp_endpoint_url`.

ComfyUI's normal port does not provide MCP. An operator must not create a fake
MCP URL by appending `/mcp` to port `8188`.

## Discovery

Catalog sync is an explicit administrator action on a ComfyUI Connection. It is
also safe to repeat: the catalog snapshot is replaced while the enabled
allowlist remains separate.

### MCP-backed discovery

When `list_templates` is available, World Graph Studio calls it once without a
task filter and once for each task type in the current modality registry. The
results are normalized and merged by provider template ID.

The current task types are:

- `txt2img`;
- `img2img`;
- `image-text-to-image`;
- `text-to-video`;
- `image-to-video`;
- `video-to-video`;
- `video-with-audio`;
- `text-to-speech`;
- `text-to-dialogue`;
- `text-to-sound-effects`;
- `text-to-music`;
- `text-to-voice`; and
- `text-to-lyrics`.

`text-to-lyrics` is registered for the delivered Suno adapter. A Comfy provider
that does not advertise a matching task leaves that catalog entry unmappable;
registry membership alone does not make it a ComfyUI workflow.

Each stored entry can include its provider ID and name, task type, mapped
World Graph Studio modality, required nodes, models, model URLs, default
parameters, inferred model family, and workflow hash. The full workflow is not
kept in the catalog snapshot; it is fetched again when materialized.

An entry whose task type does not map to a registered modality remains visible
as `unmappable` and cannot be enabled automatically.

For the bundled local ComfyUI MCP helper, `list_templates` is file-backed: it
lists `.json` workflows from the MCP service's configured template directory.
It can report both ComfyUI UI workflow JSON and API-format workflow JSON, but
ComfyUI's documented `/prompt` execution path expects API-format workflow JSON.
The helper does not create new workflows from installed nodes or upload
workflows into ComfyUI. Local operators add or export workflow JSON first, then
sync the World Graph Studio catalog. See [Text to Video with Local ComfyUI MCP](../how-to-text-to-video.md)
for the user-facing sequence.

### Local HTTP discovery

An HTTP-only local ComfyUI has no provider template list. World Graph Studio
synthesizes one entry per registered modality and inspects `GET /object_info`
when the endpoint is available. This identifies missing node classes for the
built-in workflow shapes.

The managed local text-to-image Template created by setup is the executable
zero-MCP path. Synthesized catalog entries primarily communicate what the local
instance can support; installing models and custom nodes remains an operator
task unless a real MCP download service is added.

## Persistence and status

The Connection owns two JSON meta values:

- `comfy_template_catalog` — the latest snapshot, including `synced_at`,
  tier, probe result, message, and entries;
- `enabled_templates` — the stable allowlist, including catalog entry ID,
  modality, enabled time, and linked Template ID.

The UI decorates entries with one of these coarse statuses:

| Status | Meaning |
| --- | --- |
| `ready` | The catalog descriptor has no known missing node/model blocker |
| `needs_nodes` | Local `/object_info` lacks a required node class |
| `needs_models` | The entry names models but provides no download URLs |
| `unmappable` | Provider task type has no registered World Graph Studio modality |
| `withdrawn` | Still enabled locally but absent from the latest provider snapshot |

This status is catalog guidance. A materialized Template's requirement manifest
is the authoritative local readiness check.

## Administrator workflow

Open the relevant Connection and use its provider configurator:

1. **Sync Catalog** probes capabilities and refreshes the cached entries.
2. **Enable** records that an entry is allowed for this Connection.
3. **Materialize Template** creates or updates the paired
   `worldgraph_template`, then enables and links the entry.
4. **Download Requirements** asks the provider MCP service to fetch advertised
   model URLs when both the URLs and `download_models` are available.
5. Open the resulting Template and use **Check ComfyUI** to validate its
   workflow requirements against the configured local instance.

Disabling an entry removes it from the allowlist. It does not delete its linked
Template or generation history.

These controls use capability-checked WordPress admin AJAX actions protected by
the `worldgraph_conn_configurator` nonce. They are not public catalog REST
routes.

## Template materialization

Materialization reuses a Template with the same Connection and provider
template ID or creates a new published Template. It writes the current fields
that the runtime consumes:

| Template field | Catalog/provider source |
| --- | --- |
| `template_name` | Entry display name |
| `provider_type` | `comfyui` |
| `status` | `active` |
| `modality` | Mapped registered modality |
| `generation_structure` | Modality output type |
| `connection_id` | Owning Connection |
| `provider_template_id` | Provider entry ID |
| `model_family` | Inferred node family |
| `workflow_json` | Re-fetched provider workflow, when returned |
| `configuration_json` | Provider parameters |
| `model_requirements` | Models paired with advertised URLs |
| `checkpoint` | First checkpoint model, when identified |

Generation still validates that an active Template and its Connection use the
same provider. Catalog curation cannot bypass that runtime check.

## Requirement manifests

`Comfy_Manifest::for_template()` builds a provider-neutral report containing:

- the Template identity, modality, output, and input slots;
- whether the workflow is built-in or custom;
- every workflow `class_type` node;
- recognized model-loader filenames and target folders; and
- administrator-declared download URLs.

For local validation, `Comfy_Manifest::validate()` compares that report with
the cached `/object_info` response. The cache lasts five minutes and can be
flushed before rechecking.

Recognized model fields include checkpoints, diffusion models, VAEs, text
encoders, LoRAs, ControlNet, style models, CLIP Vision, GLIGEN, and upscalers.
An input that does not expose an installed-file enum is reported as unverified
rather than guessed.

The same manifest is available at:

`GET /wp-json/worldgraph/v1/generation/templates/{id}/requirements`

Pass `validate=false` to return the manifest without contacting local
ComfyUI.

## Download and manual-install boundary

There are two delivered download paths:

- a catalog entry can re-fetch its provider definition and send advertised
  model URLs to `download_models`; and
- a materialized Template can send URLs declared in its Model Requirements
  JSON for models the local validation report says are missing.

Both operations are explicit administrator actions and execute on the MCP
provider side. WordPress does not shell into ComfyUI, write directly to its
model directories, invent missing URLs, or install custom-node code.

When no source URL or download tool exists, install the named file under the
reported `models/{folder}` path in ComfyUI and rerun the requirement check.

## Current boundaries and extension seams

The current release intentionally does not promise:

- automatic custom-node installation;
- a persistent, cancellable model-download job queue;
- checksums, license adjudication, or disk-space forecasting;
- bulk catalog filtering and preference selection; or
- a public catalog-management REST API.

Those are possible extension seams, not active delivery commitments. The
current catalog, admin controls, Template records, generation queue, result
import, and provenance flow are delivered.

## Security rules

- Only users allowed to edit the Connection may mutate its catalog.
- Template editing remains available under the normal post capabilities, but
  Connection-backed checks, discovery, imports, smoke tests, and model-download
  requests require `manage_options` and permission to edit the selected
  Connection.
- Provider catalog data and URLs are untrusted input.
- Credentials are resolved by the provider adapter and must not be copied into
  catalog snapshots, Template workflow JSON, generation records, or logs.
- Downloads require an explicit administrator action and provider support.
- Local ComfyUI should remain behind the deployment network boundary.

## Implementation map

- [Catalog cache and curation](../../wordpress/wp-content/plugins/worldgraph/includes/utils/comfy-catalog.php)
- [MCP capability probe](../../wordpress/wp-content/plugins/worldgraph/includes/utils/comfy-cloud-mcp.php)
- [Requirement manifests](../../wordpress/wp-content/plugins/worldgraph/includes/utils/comfy-manifest.php)
- [Connection configurator](../../wordpress/wp-content/plugins/worldgraph/includes/cpts/connection.php)
- [Local readiness panel](../../wordpress/wp-content/plugins/worldgraph/includes/admin/comfy-readiness.php)
- [Generation Engine](GENERATION_ENGINE.md)
