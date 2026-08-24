# World Graph Studio Plugin Architecture

> **Current release status: complete.** This document describes the code in the
> repository. Optional providers still require credentials, compatible models,
> and reachable services. The repository-wide status authority is
> [Delivery Status](../../../../../about/Delivery_Status.md).

## System boundary

World Graph Studio is a WordPress application. WordPress owns the Story Graph,
Structured Content Fields, provider configuration, generation jobs, media,
provenance, admin UI, permissions, and REST API.

External LLM and media services execute only the work delegated to their
adapters. There is no separate World Graph Studio Python orchestrator, queue
service, or provider database.

```text
WordPress
├── Story Graph content and relationships
├── SCF runtime schema and Local JSON archive
├── AI Editor and filmmaking advisors
├── Connections and Templates
├── WP-Cron generation queue
├── WordPress media and Asset provenance
└── /wp-json/worldgraph/v1
        │
        ├── local or hosted LLM
        ├── local ComfyUI HTTP API
        ├── Comfy MCP
        ├── fal MCP
        ├── ElevenLabs REST API
        ├── SunoAPI.org REST
        └── AceData Cloud Suno MCP
```

## Bootstrap and naming

The plugin entry point is [worldgraph.php](../worldgraph.php). Its public naming
contract is:

- product name: **World Graph Studio**;
- plugin directory and text domain: `worldgraph`;
- PHP namespace: `WorldGraph`;
- constant prefix: `WORLDGRAPH_`;
- content/meta prefix: `worldgraph_`; and
- REST namespace: `worldgraph/v1`.

The bootstrap checks the Secure Custom Fields dependency, loads core utilities,
registers content types and taxonomies, reconciles the persisted SCF schema,
registers REST/admin modules, initializes the AI Editor, and loads configured
provider adapters.

## Story Graph data model

The current application registers 15 content/configuration types:

| Post type | Role |
| --- | --- |
| `worldgraph_project` | Top-level creative project |
| `worldgraph_world` | Story world or setting system |
| `worldgraph_character` | Character record |
| `worldgraph_location` | Location record |
| `worldgraph_prop` | Story or production object |
| `worldgraph_org` | Organization or faction |
| `worldgraph_episode` | Episode or chapter structure |
| `worldgraph_scene` | Scene content and continuity |
| `worldgraph_shot` | Shot planning and coverage |
| `worldgraph_sound` | Narration, dialogue, effects, ambience, Foley, music, or silence cue |
| `worldgraph_asset` | Managed media and provenance |
| `worldgraph_editorial` | Editorial artifact |
| `worldgraph_template` | Reusable generation/provider configuration |
| `worldgraph_conn` | Provider control-plane record |

Generation jobs are stored as internal `worldgraph_gen` posts by the
generation controllers and batch worker. They are operational records, not an
authoring content type in the Story Graph registry.

Nine taxonomies cover genre, asset type, production status, character
relations, character roles, scene tags, editorial sequence, sound type, and
Template category.

Canonical relationships are stored in namespaced post meta and exposed through
the graph utilities and REST controller. Global graph traversal, relationship
analytics, continuity checks, and keyword/optional semantic search operate on
WordPress data.

## Structured Content Fields

Secure Custom Fields is required. The committed [acf-json](../acf-json)
directory is a portable seed/archive; editable database field groups are the
runtime authority.

On privileged admin or WP-CLI requests, World Graph Studio validates and merges
changed archive groups into the database while preserving SCF-managed
presentation settings and site extension fields. Saving an owned group refreshes
its archive when the directory is writable. Database edits continue with a
warning when the archive cannot be updated.

Stable group and field keys use the current CPT key, for example
`group_worldgraph_scene` and `field_worldgraph_scene_scene_number`.

## AI Editor and advisors

The AI Editor lives under [includes/ai-editor](../includes/ai-editor). It
provides:

- LLM clients for OpenAI-compatible, OpenAI, Anthropic, and dual/fallback
  configuration;
- Story Graph context assembly;
- chat, analysis, generation-assistance, continuity, settings, and health REST
  routes;
- a Gutenberg sidebar and classic AI Workflow metabox; and
- 51 Markdown-defined filmmaking advisors.

Advisor requests include WordPress-owned context and return suggestions. The
current LLM client uses `tool_choice: none`; advisors do not autonomously call
generation, catalog, or download actions.

On WordPress versions that expose `wp_register_ability`, the plugin also
registers schema-described WordPress Abilities. A compatible WordPress MCP
adapter can expose public abilities to external MCP clients. This is distinct
from the built-in advisor execution path.

## Generation architecture

### Registered modalities

The canonical [Generation_Modality](../includes/utils/generation-modality.php)
registry currently contains:

- image: `text_to_image`, `image_to_image`, and `image_text_to_image`;
- video: `text_to_video`, `text_image_to_video`, `video_to_video`, and
  `video_with_audio`; and
- audio/text: `text_to_speech`, `text_to_dialogue`,
  `text_to_sound_effect`, `text_to_music`, `text_to_voice`, and
  `text_to_lyrics`.

Adapters and Templates decide which registered shapes are executable for a
specific Connection. The media-import layer stores validated image, video, and
audio results; lyrics remain normalized text output.

### Connections and Templates

`worldgraph_conn` records select a provider, environment, HTTP/MCP endpoints,
credential values or references, model selection/allowlist, status, and
optional limits. Suno keeps the SunoAPI.org REST credential in
`credential_reference` and the distinct AceData Cloud MCP token in
`mcp_credential_reference`. Adapter implementations load only for configured,
non-disabled Connections or while the provider is being configured.

`worldgraph_template` records select the Connection, modality, provider
template or endpoint, optional ComfyUI API workflow, default configuration,
input bindings, and model requirements.

The shipped executable adapters are:

- local ComfyUI HTTP;
- Comfy MCP, including Comfy Cloud;
- fal MCP;
- ElevenLabs;
- SunoAPI.org REST; and
- AceData Cloud Suno MCP.

Other provider names in the Connection schema are manually managed extension
points unless an adapter registers executable behavior.

### Catalog and readiness

ComfyUI Connections support a per-Connection catalog. MCP Connections discover
provider templates; HTTP-only local Connections synthesize entries from the
registered modality list and inspect `/object_info`. Administrators can enable
entries, materialize Templates, request provider-side model downloads when the
MCP tool exists, and validate Template requirements.

Local setup provisions a managed text-to-image Template and exposes a readiness
panel for the required nodes and checkpoint.

### Asset generation

The **World Graph Studio Assets** metabox:

1. builds intent-specific prompts from the source Story Graph context;
2. offers direct **Image** or **Video**, multi-output **Sequence**, and a
   Project-only whole-story **Demonstration**;
3. filters active Templates by output, preferred modality, available
   Connection, bindings, and generated-reference slots;
4. validates and freezes per-type Template/run-control selections before
   queueing; and
5. optionally sets imported images as featured media and creates linked Assets.

The representative workflow/intent registry defines default output recipes,
and Generate Preferences can select Templates by intent or output type.
Demonstration planning adds character/location/Shot stills, optional generated
Sound and Shot video tasks, dependency-aware image-to-video or first/last-frame
inputs, and a frozen editorial assembly timeline.

### WP-Cron jobs

[Generation_Batch](../includes/utils/generation-batch.php) owns the
`worldgraph_process_generation_batch` hook. It locks concurrent runs, polls
submitted work, submits queued work, and reschedules itself after 60 seconds
while jobs remain. `Generation_Workflows` shares that hook at an earlier
priority to materialize dependency-aware parent batches before child polling.

Representative-media and demonstration runs use durable parent
`worldgraph_gen` records. Their batch kinds are `representative_media` and
`demonstration_video`; children retain the parent ID and frozen task step.
Demonstration parents additionally retain the frozen assembly plan and final
assembly DTO. Optional missing enhancements become terminal skipped children,
so the editorial plan and aggregate status remain auditable.

```text
POST generation request
        ↓
worldgraph_gen: queued
        ↓
WP-Cron selects Connection adapter
        ↓
provider completes synchronously or returns remote job ID
        ↓
worldgraph_gen: submitted (when polling is required)
        ↓
validate and import provider media
        ↓
attachments + optional Asset + provenance
        ↓
completed | failed | cancelled
```

After demonstration children become terminal, the separate
`worldgraph_process_rough_cut_assembly` hook advances signed, resumable FFmpeg
state through normalization, concatenation, subtitles, generated-audio or
silence, and Media Library import. A distinct lease plus per-batch worker token,
heartbeat, attempts, and progress metadata permit stale-worker recovery without
blocking provider polling. Cancellation is checked between stages. Completion
is published only after the rough-cut video attachment, batch provenance, and
Project parent are verified; missing FFmpeg or failed assembly is reported
without discarding completed child media.

Completed image, video, and audio files pass through WordPress validation and
media attachment creation. Text-output jobs retain their normalized provider
result without creating a media attachment. Generation records retain source,
Template, Connection, provider, prompt, parameters, remote job, output IDs,
status, and sanitized result metadata.

## REST API

The base is `/wp-json/worldgraph/v1/`. Controllers use instance-based
`register_routes()` methods and WordPress permission callbacks.

Primary route groups are:

| Group | Representative routes |
| --- | --- |
| Story content | `/projects`, `/storyworlds`, `/characters`, `/locations`, `/props`, `/organizations`, `/episodes`, `/scenes`, `/shots`, `/sounds`, `/assets`, `/editorial-artifacts` |
| Graph | `/graph/{id}`, `/graph/entities`, `/graph/relationships` |
| Generation | `/assets/generate`, `/assets/generate/prompt`, `/assets/generate/plan`, `/assets/generate/batches`, `/assets/generate/batches/{id}`, `/assets/generate/batches/{id}/cancel`, `/generation`, `/generation/{id}`, `/generation/{id}/cancel`, `/generation/asset/{id}/history`, `/generation/templates/{id}/requirements` |
| Connections | `/connections`, `/connections/{id}`, `/connections/{id}/resolve`, `/connections/{id}/test`, `/connections/sync` |
| AI Editor | `/ai/agents`, `/ai/chat`, `/ai/analyze`, `/ai/context`, `/ai/continuity`, `/ai/generate`, `/ai/health`, `/ai/settings` |
| Production/editorial | `/production/{project_id}/*`, `/editorial/{project_id}/*`, `/sequences/*` |
| Project interchange | `/import`, `/import/validate` |

Catalog curation uses nonce-protected administrator actions rather than public
catalog REST routes.

## Administration

The delivered admin surfaces include:

- Dashboard and grouped Story Elements, Editorial, and Story Analysis menus;
- Setup & Settings;
- Connections and provider configurators;
- Templates and ComfyUI requirement controls;
- World Graph Studio Assets and AI Workflow metaboxes;
- Generation Log;
- continuity, summaries, dramaturgy, analytics, editorial cut, import, and
  export tools; and
- integration status/toggles for packaged optional plugins.

## Setup and configuration

Activation redirects administrators to
`/wp-admin/admin.php?page=worldgraph-setup` until the form has been submitted.
The wizard can create a managed generation Connection, a primary LLM Connection,
and their backing options. Media generation is optional; an API-connected LLM
is required only for AI advisor features.

The guided generation choices are:

- Comfy Cloud MCP;
- local ComfyUI HTTP plus optional separate MCP;
- fal MCP;
- ElevenLabs generative audio;
- Suno API + MCP, using separate credentials; or
- no generation Connection.

The wizard also configures the primary LLM provider, URL, model, API key, max
tokens, and temperature. It can be submitted with provider fields empty so
core Story Graph work remains available.

See [Setup Guide](SETUP_GUIDE.md) and
[Setup Wizard Guide](SETUP_WIZARD_GUIDE.md). See
[Suno Integration](../../../../../about/plugins/SUNO.md) for the paired
transport contract.

## Dependencies and runtime ownership

Required:

- WordPress 6.0 or later;
- PHP 8.1 or later; and
- Secure Custom Fields.

WordPress 6.9 or later is needed for the conditional WordPress Abilities
registration. Provider accounts and services are optional.

In the repository's Lando environment:

- `appserver` owns PHP, WordPress, and WP-CLI;
- `cli` owns Node.js and JavaScript checks; and
- `database` owns MariaDB.

PHP changes do not require restarting WordPress.

## Namespace migration

The one-time compatibility migration runs after current CPT registration. It
migrates legacy post types, taxonomies, namespaced options/meta, serialized
references, SCF identifiers, and scheduled hooks to the `worldgraph` naming
contract. Legacy identifiers are migration inputs only.

Back up the database before upgrading an existing installation and activate the
renamed `worldgraph/worldgraph.php` plugin so the migration can run.

## Verification

From the repository root:

```bash
./vendor/bin/phpunit \
  -c wordpress/wp-content/plugins/worldgraph/tests/phpunit.xml \
  --testsuite "World Graph Studio" \
  --do-not-cache-result

find wordpress/wp-content/plugins/worldgraph -type f -name '*.php' \
  -exec php -l {} \;

lando exec cli -- /bin/sh -lc \
  'find /app/wordpress/wp-content/plugins/worldgraph/assets -type f -name "*.js" -exec node --check {} \;'
```

For an activated local site:

```bash
lando wp plugin status worldgraph
lando wp cron event list
```

Use [Delivery Status](../../../../../about/Delivery_Status.md) rather than old
roadmap checklists to determine whether a repository capability is delivered.
