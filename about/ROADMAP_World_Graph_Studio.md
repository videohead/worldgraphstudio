# World Graph Studio Roadmap

> Your ideas. Your assets. No credits needed.

## Current release

**Status: complete.** Every capability presented as current in this repository
is delivered. Optional providers and integrations can still require
credentials, compatible services, models, workflows, or WordPress
configuration; those operating requirements do not make the implementation
incomplete.

[Delivery Status](Delivery_Status.md) is the authoritative status record. This
roadmap records the delivered release and distinguishes bundled capabilities
from possible extension points.

## Product direction

World Graph Studio is an open-source, self-hosted creative production platform
for worldbuilding, storytelling, AI-assisted analysis, asset generation, and
production planning. WordPress owns the canonical Story Graph and media.
Creators may connect local, open, or hosted services without moving their
project into a World Graph Studio cloud or buying World Graph Studio credits.

The product direction is guided by five rules:

- Keep ideas, assets, relationships, and production decisions connected.
- Keep the Story Graph useful when optional AI services are unavailable.
- Treat models and providers as replaceable connections.
- Give creators practical ways to bring data in and take deliverables out.
- Keep formats, Connection adapters, and specialist agent profiles easy to add
  without forking the Story Graph.

## Delivered milestones

### Story Graph foundation — complete

- Fifteen content types and nine taxonomies.
- Structured Content Fields and portable field definitions.
- Canonical relationships, graph traversal, and REST exposure.
- Projects, worlds, characters, locations, props, organizations, episodes,
  scenes, shots, sounds, storyboards, assets, editorial records, templates,
  and connections.
- Migration of stored legacy identifiers to the `worldgraph` namespace.

### Creative workspace and production planning — complete

- WordPress editing and administration surfaces.
- Storyboard, shot, sound, asset, production, and editorial workflows.
- Setup wizard, connection management, dashboards, plugin controls, and logs.
- WordPress Media Library linkage for uploaded, imported, and generated media.

### Story intelligence — complete

- Keyword and optional semantic assistance.
- Continuity checks and stored findings.
- Relationship traversal, summaries, and analytics.
- Admin, REST, and Ability integration points.

### AI Editor and specialist advisors — complete

- Gutenberg AI Editor sidebar and story-element workflow surfaces.
- Bounded, permission-aware Story Graph context.
- Chat, analysis, draft generation, continuity, agent, settings, and health
  REST routes.
- Local and hosted LLM connection modes.
- WordPress tools, resources, and prompts through the Abilities API.
- More than 50 specialist creative advisor profiles.
- Runtime discovery of `.agent.md` profiles, allowing another focused role to
  become directly selectable with the same context, permissions, and LLM
  layer; automatic routing remains optional configuration.

### Generation and provenance — complete

- Connection and template records with validation and capability discovery.
- A filterable Connection adapter manifest with provider-owned loading and
  guided setup definitions.
- Provider-neutral input binding and request preparation.
- Queued generation records and WP-Cron processing.
- Status polling, cancellation, failure reporting, and generation logs.
- Returned-media import, source linkage, and provenance.
- Comfy Cloud MCP, local ComfyUI HTTP, fal MCP, ElevenLabs, SunoAPI.org REST,
  AceData Cloud Suno MCP, Seedance 2.5 through CyberBara REST, VideoDraft hosted
  MCP, OpenRouter video generation REST, and manually managed external
  generator workflows where configured.
- Suno prompt-music, custom-music, and `text_to_lyrics` Templates, with
  distinct REST and MCP credentials.
- Seedance 2.5 text-to-video and image-to-video Templates through the
  third-party CyberBara REST intermediary.
- VideoDraft image, video, and audio Templates discovered from live tool
  schemas, with asynchronous media import.

### Interchange and publishing — core complete; scaffolds classified

- World Graph Studio JSON import.
- Final Draft FDX screenplay import.
- Markdown screenplay and storyboard export.
- Optional bidirectional VideoDraft structural Project sync with preview,
  checkpointed push, conflict checks, and persistent mapping.
- Fountain importer source; its browser bootstrap must be corrected before the
  workflow is classified as delivered.
- Celtx outbound connector source; response handling and Scene calls require
  repair before the workflow is classified as delivered.
- Experimental Descript exchange source; canonical relationship resolution,
  callback handling, and runtime verification remain extension work.
- CMX 3600 and SMPTE 436m XML parsing, timecode, and format-generation code.
  The bundled admin workflow remains incomplete.
- Google Web Stories connector source is retained as a prototype and is not a
  current-release workflow.

The delivered JSON, FDX, Markdown, and VideoDraft surfaces complete the defined
current-release interchange scope. Other bundled rows remain cataloged by
their actual readiness.

## Closed additional-script roadmap item

The blanket hold on additional script formats is retired. Final Draft FDX was
accepted and delivered for the current release; formats outside that scope are
extension opportunities rather than unfinished release requirements. Format
adapters reuse the canonical Story Graph import contract, delivered sync keeps
explicit remote mappings, and exports remain projections of live WordPress
data.

Fade In, Highland, Story Architect, additional professional screenplay
exporters, format-specific merge workflows, and further synchronization
providers remain possible extensions. They are not shipped capabilities or
active roadmap commitments, but the current architecture gives them a defined
place to integrate when they have approved scope and acceptance criteria.

## Extension points

The architecture can support additional work without treating it as a current
commitment. Examples include:

- AAF, OMF, asset-reference exchange, and provider-specific NLE panels.
- Additional generation or LLM providers.
- Interactive graph visualization and large-graph indexing strategies.
- Specialized scheduling, call-sheet, and production-planning plugins.
- Reusable template, integration, or community distribution services.
- A production-ready Google Web Stories connector built from the bundled
  prototype source.

An extension becomes roadmap work only when it has an approved scope,
acceptance criteria, and release target. Until then, these are documented
possibilities rather than pending deliverables.

## Maintenance priorities

Ongoing maintenance does not change the complete status of the current
release. Changes should:

- Preserve the Story Graph as the canonical source of truth.
- Keep permissions, sanitization, provenance, and compatibility contracts
  intact.
- Maintain graceful behavior when optional services are unavailable.
- Keep code, schema tests, API specifications, and user documentation aligned.
- Avoid turning provider configuration or account setup into a product
  dependency.

## Release status policy

- **Delivered** means the implementation is present in the repository and its
  documented interface is part of the current release.
- **Configured** describes whether a particular installation has supplied the
  optional credentials, endpoint, model, or integration needed to run it.
- **Extension point** identifies a compatible direction without promising
  delivery.

## Related documents

- [Marketing Overview](marketing/overview.md)
- [Delivery Status](Delivery_Status.md)
- [Product Requirements](World_Graph_Studio_PRD.md)
- [Architecture](World_Graph_Studio_Architecture.md)
- [AI Editor](AI_Editor.md)
- [Script and Editorial Interchange](Script_EDL_Integration.md)
- [Suno Integration](plugins/SUNO.md)
- [VideoDraft Connection and Sync](plugins/VIDEODRAFT.md)
