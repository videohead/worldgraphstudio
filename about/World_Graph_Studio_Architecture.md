# World Graph Studio Architecture

> Build worlds. Connect ideas. Generate anything.

**Architecture status: delivered.** This document describes the current
repository, not a proposed replacement system. Product status is maintained in
[Delivery Status](Delivery_Status.md).

## System purpose

World Graph Studio is a self-hosted creative production application built on
WordPress. Its canonical data model is the Story Graph: structured creative
records connected by explicit relationships and enriched with Structured
Content Fields (SCF).

WordPress is both the application and the control plane. It owns users,
permissions, project records, relationships, REST routes, background jobs,
media, and administration. LLMs, generation providers, Celtx, VideoDraft,
editors, and publishing tools are optional connections around that core.

## System context

```text
Creators and API clients
          |
          v
WordPress + World Graph Studio
  Admin UI | Gutenberg | REST | Abilities
          |
          v
Story Graph + SCF + Media Library
    |             |              |
    v             v              v
AI Editor    Generation Engine   Interchange
    |             |              |
    v             v              v
Configured     Comfy / fal /        Story Import & Export /
LLM endpoint   ElevenLabs / Suno /  FDX / VideoDraft
               CyberBara Seedance /  + cataloged scaffolds
               VideoDraft / OpenRouter
```

Core Story Graph work has no AI dependency. An unavailable connection should
degrade the feature that uses it without making project data unavailable.

## Runtime topology

The standard deployment contains:

| Runtime | Responsibility |
| --- | --- |
| WordPress and PHP | Application logic, admin UI, REST API, Abilities, permissions, import/export, and provider coordination |
| MariaDB | WordPress content, SCF values, relationships, settings, job state, and integration mappings |
| WordPress Media Library | Uploaded, imported, and generated media |
| WP-Cron | Generation queue submission, polling, and scheduled provider work |
| Optional external services | LLM inference, generative workflows, VideoDraft synchronization, and other configured delivered integrations |

The local development environment uses the repository-root Docker Compose
stack. Its default services are WordPress, MariaDB, and Node.js tooling;
PHPUnit and phpMyAdmin are profile-gated tools. Docker Compose is not a
production dependency. No separate Python API, router, queue server, or
orchestration service is required by the application.

## Repository architecture

The main plugin is
`wordpress/wp-content/plugins/worldgraph/worldgraph.php`. Its implementation
is divided by responsibility:

| Surface | Path | Responsibility |
| --- | --- | --- |
| Content model | `includes/cpts/`, `includes/taxonomies/`, `acf-json/` | Content types, taxonomies, SCF field groups, and REST-visible schemas |
| Story services | `includes/utils/` | Relationships, graph traversal, search, continuity, generation, provider adapters, and compatibility migration |
| REST API | `includes/rest-api/` | Permission-aware Story Graph, generation, production, editorial, connection, and agent routes |
| AI Editor | `includes/ai-editor/`, `assets/ai-editor/` | Context assembly, LLM access, specialist advisors, Abilities, and Gutenberg UI |
| Administration | `includes/admin/`, `assets/` | Setup, dashboards, metaboxes, connections, plugin management, intelligence panels, and logs |
| Story interchange feature plugin | `plugins/story-import-export/` | Default-enabled canonical JSON import/export, Markdown screenplay/storyboard export, bounded story-source extraction, selected-Connection LLM decomposition, and import/export REST/admin surfaces |
| Other bundled integrations | `plugins/` | Delivered FDX import and VideoDraft sync plus Fountain, Celtx, Descript, EDL, and Web Stories scaffolds/prototypes |
| Verification | `tests/` | Unit, schema-contract, migration, and behavior coverage |

SCF is a plugin dependency. Local JSON archives seed portable field groups;
the WordPress database copy is runtime-authoritative.

## Canonical data model

The release registers 15 content types:

| Domain | Content types |
| --- | --- |
| Project structure | Project, Story World, Episode |
| World entities | Character, Location, Prop, Organization |
| Narrative and production | Scene, Shot, Sound |
| Media and editorial | Asset, Editorial Artifact |
| Generation configuration | Template, Connection |

Nine taxonomies classify assets, character relationships, character roles,
genres, production status, scenes, sequences, sounds, and templates.

Relationships connect records by stable WordPress IDs. SCF and post metadata
store registered entity attributes; taxonomies hold controlled
classifications. WordPress posts and attachments provide revision,
publication, permission, and media behavior.

The detailed contract is defined by the
[Content Model Specification](Content_Model_Specification.md),
[CPT and SCF Schema](CPT_and_SCF_Schema.md), and
[Story Graph Specification](Story_Graph_Specification.md).

## Application layers

### Editing and administration

WordPress admin screens and Gutenberg are the primary human interfaces. The
plugin adds navigation, setup, connection and template management, asset
generation, import/export, continuity, analytics, editorial, summary, and
generation-log surfaces.

Every state-changing action remains subject to WordPress capabilities, nonce
verification where applicable, input sanitization, and output escaping.

### REST API

The established REST compatibility API uses the `worldgraph/v1` namespace.
Controllers expose Story Graph entities, relationships, agents, generation,
connections, production, editorial workflows, and the feature plugin's JSON
import, story-decomposition preview, and project-export operations. The
namespace remains supported for existing clients, but it is not the canonical
product model or the automatic contract for new headless work. Child
integrations use their own namespaces when they own the external contract.

REST is an application boundary, not a second data model. Controllers call the
same WordPress services used by admin and Ability surfaces. See the
[REST API Specification](REST_API_Specification.md).

### AI Editor and Abilities

The AI Editor follows this path:

```text
Gutenberg sidebar or authorized client
          |
          v
AI REST route or WordPress Ability
          |
          v
Permission check + bounded Story Graph context
          |
          v
Specialist advisor + configured LLM client
          |
          v
Structured suggestion returned for human review
```

The context builder reads only records the current user may access and excludes
credentials and unrelated content. The server owns system instructions and
context construction. Browser chat history is bounded and stays in the
editing session.

OpenAI, Anthropic, and OpenAI-compatible local or hosted endpoints are
supported through configuration. WordPress Abilities expose typed tools,
resources, and prompts when the host WordPress version provides the Abilities
API. An MCP adapter may expose those registered abilities to compatible
clients; World Graph Studio does not duplicate them in another execution
service.

See [AI Editor](AI_Editor.md) and
[Agent Architecture](Agent_Architecture.md).

Specialist agents are filesystem-discovered `.agent.md` profiles owned by the
plugin. The current bundle contains more than 50, and another focused role is
directly selectable with the same context, permissions, and configured LLM
layer without a new runtime or Story Graph entity type. Automatic routing is
optional and requires matching router keywords.

### Story Graph intelligence

Search, continuity, and relationship analytics operate on the canonical
WordPress records and relationships. Keyword search and deterministic checks
provide useful behavior without an LLM. Optional semantic or narrative
assistance may enrich results, but it does not replace stored Story Graph
facts.

See [Story Graph Intelligence](Story_Graph_Intelligence.md).

### Generation engine

The generation lifecycle is:

1. A creator or authorized client selects a source entity, active template,
   and compatible connection.
2. World Graph Studio validates the request and binds provider-neutral inputs.
3. A generation record stores the source, template, connection, parameters,
   requested modality, status, and provenance inputs.
4. WP-Cron submits or polls queued work through the selected adapter.
5. The adapter normalizes provider status and results.
6. Supported returned media is imported into the WordPress Media Library and
   linked back to its Story Graph source and Asset record; normalized text
   results remain on the generation record.

The delivered adapters cover Comfy Cloud MCP, local ComfyUI HTTP workflows,
fal MCP, ElevenLabs, SunoAPI.org REST, AceData Cloud Suno MCP, Seedance 2.5
through CyberBara REST, VideoDraft MCP, OpenRouter video generation REST, and
manually managed external-generator workflows where configured. Suno uses one
Connection with distinct REST and MCP credential references; its managed
Templates cover prompt music, custom music, and `text_to_lyrics`. Job state,
cancellation, validation failures, and generation logs remain in WordPress.

A connection represents endpoint, environment, credentials, and capability
configuration. A template represents reusable, provider-aware generation
inputs. Neither is a story entity, and neither replaces the source entity or
resulting WordPress asset.

Provider implementations register in a filterable Connection adapter manifest.
An extension can contribute provider metadata, conditional loading, and guided
setup choices while keeping the Connection record stable. The integration must
supply and wire its own provider-specific testing, discovery, Template,
execution, and polling behavior; the manifest alone does not add those stages
to the shared generation lifecycle.

See [Generation Engine](plugins/GENERATION_ENGINE.md),
[Comfy Template Catalog](plugins/COMFY_TEMPLATE_CATALOG.md), and
[Deployment and Connections](Deployment_and_Connections.md). The two-provider
Suno contract is detailed in [Suno Integration](plugins/SUNO.md), and the
third-party Seedance transport boundary is detailed in [Seedance 2.5 via
CyberBara](plugins/SEEDANCE.md).

### Interchange

Interchange is deliberately adapter-based:

| Workflow | Owner | Status / behavior |
| --- | --- | --- |
| Project JSON | Story Import & Export feature plugin | Validate/import canonical JSON and export a deterministic canonical version 1.2 document from one live Project |
| Story document | Story Import & Export feature plugin | Persist JSON, TXT, Markdown, Fountain, RTF, text-layer PDF, EPUB, DOCX, or ODT in WordPress uploads; canonical JSON validates directly, while other sources use a selected compatible LLM Connection to produce a validated preview before confirmation |
| Markdown | Story Import & Export feature plugin | Export screenplay and storyboard views from live project data |
| Final Draft FDX | Bundled import integration | Parse supported screenplay structure in the browser, normalize it to World Graph Studio JSON, and delegate to the feature plugin importer |
| Deterministic Fountain-to-FDX | Bundled import source | Intended to convert supported Fountain structure through FDX normalization; browser bootstrap currently blocks this separate workflow |
| Celtx | Bundled connector source | Intended outbound synchronization and persistent mapping; response and Scene-call repair required |
| VideoDraft | Optional child integration | Bidirectional structural Project sync, preview, checkpointed push, conflict hashes, and per-Connection mapping |
| Descript | Experimental source | Intended transcript import and bound-media export; relationship, callback, binary-format, wizard, and runtime work remains |
| EDL | Bundled PHP library and admin scaffold | CMX 3600 and SMPTE 436m XML parsing, timecode, and format helpers are implemented; the admin workflow is incomplete |
| Google Web Stories | Prototype source | Bundled design/implementation scaffold; not loaded or supported as a current workflow |

This closes the former blanket roadmap hold for a defined scope: the feature
plugin accepts the listed text-bearing story documents for LLM-assisted
decomposition, and FDX remains the delivered deterministic screenplay-file
adapter. Application-specific lossless adapters remain extensions or
explicitly classified scaffolds. See
[Story Import & Export](plugins/STORY_IMPORT_EXPORT.md) and
[Script and Editorial Interchange](Script_EDL_Integration.md).

## Trust boundaries

### Browser to WordPress

- Use authenticated WordPress sessions and nonces.
- Require capabilities appropriate to the source post and requested action.
- Treat generated text and provider responses as untrusted input.

### WordPress to external services

- Keep credentials in protected options or deployment constants.
- Use allowlisted, validated endpoints and bounded timeouts.
- Do not send the entire Story Graph when a bounded context is sufficient.
- Normalize provider errors before displaying or recording them.
- Treat service availability, pricing, quotas, content policies, and model
  licenses as properties of that service.

### Imported data and media

- Validate document shape before creating records.
- Sanitize text, identifiers, relationships, and filenames.
- Enforce file type and size constraints on returned media.
- Retain uploaded story sources in the WordPress uploads tree so the operator
  can audit or remove them through normal Media Library controls.
- Send extracted story text only to the explicitly selected LLM Connection;
  canonical JSON import/export and Markdown export do not require an LLM.
- Accept PDFs only when a usable text layer can be extracted. Scanned or
  image-only PDFs return an OCR-required error.
- Retain source and provenance links without persisting secrets or raw
  authorization data, and never serialize Connection credentials or raw source
  manuscripts in REST metadata or error responses.

## Availability and failure behavior

- WordPress editing and Story Graph traversal remain available without AI or
  generation connections.
- Keyword and deterministic intelligence paths remain available when semantic
  services are absent.
- Generation jobs retain explicit queued, submitted, completed, cancelled, or
  failed state.
- Optional child integrations fail within their own boundary and do not become
  a dependency of Story Core.
- Disabling the default-enabled Story Import & Export feature removes its
  admin and REST surfaces without making stored Story Graph data unavailable.
- An unavailable LLM Connection prevents unstructured story decomposition but
  does not prevent canonical JSON import/export or Markdown export.
- Health and connection checks report actionable status without exposing
  credentials.

## Naming and compatibility

The current naming contract is:

- Product: **World Graph Studio**
- Machine namespace and text domain: `worldgraph`
- PHP namespace: `WorldGraph`
- Constants and deployment prefix: `WORLDGRAPH_`
- REST namespace: `worldgraph/v1`

A one-time compatibility migration recognizes legacy identifiers and moves
stored content, taxonomy, metadata, option, and scheduled-event references to
the current names. Legacy names belong only in that migration and its tests.

## Extension model

New providers, formats, editorial tools, visualizations, and community
packages should extend registered WordPress contracts rather than introduce a
parallel source of truth. A compatible extension should:

- Reuse Story Graph IDs, relationships, permissions, and REST conventions.
- Declare capabilities and schemas explicitly.
- Keep credentials out of content and exported project documents.
- Preserve provenance for imported or generated assets.
- Remain optional when its external service is unavailable.

Possible AAF, OMF, NLE-panel, graph-visualization, marketplace, and additional
provider work is an extension surface, not a current roadmap commitment.

## Related documents

- [Product Requirements](World_Graph_Studio_PRD.md)
- [Delivery Status](Delivery_Status.md)
- [Roadmap](ROADMAP_World_Graph_Studio.md)
- [Deployment and Connections](Deployment_and_Connections.md)
- [Example Workflow User Guide](example-workflow/USER_GUIDE.md)
- [Story Import & Export](plugins/STORY_IMPORT_EXPORT.md)
