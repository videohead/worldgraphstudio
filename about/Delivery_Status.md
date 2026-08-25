# World Graph Studio Delivery Status

**Current core release status: complete.** The default-enabled Story Import &
Export feature plugin and Final Draft FDX import are delivered. The
additional-script roadmap area that was previously paused is closed for this
defined current-release scope; unaccepted formats remain extension
opportunities rather than unfinished release requirements. The inventory below
separates delivered workflows from bundled scaffolds that still need repair.

This document is the status source of truth. Feature specifications explain how
the delivered system works; they should not redefine a shipped feature as
pending merely because an optional provider, account, model, or deployment has
not been configured.

## Delivered

| Area | Current release |
| --- | --- |
| Story Graph | 15 content types, nine taxonomies, Structured Content Fields, canonical relationships, graph traversal, and REST exposure |
| Creative workspace | Project and world management, scenes, shots, sounds, storyboards, assets, editorial records, templates, and provider connections |
| AI assistance | Gutenberg AI Editor, Story Graph context, configured LLM access, WordPress Abilities, and 50+ specialist agents loaded from extensible profiles |
| Story intelligence | Search, optional semantic assistance, continuity checks, relationship analytics, summaries, and admin panels |
| Generation | Connection and template records, validation, queued generation jobs, WP-Cron processing, job state, cancellation, result import, and provenance |
| Provider adapters | A filterable, callback-driven Connection/Template/generation registry plus local ComfyUI HTTP workflows, Comfy Cloud MCP, fal MCP, ElevenLabs, SunoAPI.org REST, AceData Cloud Suno MCP, VideoDraft MCP, OpenRouter video generation REST, and manually managed external-generator workflows where configured |
| Project interchange | Default-enabled Story Import & Export plugin with canonical World Graph Studio JSON import/export, Markdown screenplay/storyboard export, and preview/confirm LLM decomposition of supported persisted story documents; Final Draft FDX import; optional VideoDraft structural Project push/pull |
| Synchronization | Optional bidirectional VideoDraft structural synchronization, with persistent remote-ID mappings |
| Editorial format code | CMX 3600 and SMPTE 436m XML parsing, timecode, and format-generation functions; the bundled admin workflow remains incomplete |
| Extension surfaces | Canonical import contract, bundled format and synchronization plugins, filterable Connection lifecycle/Template/generation adapters, profile-driven agents, REST APIs, and WordPress Abilities |
| Administration | Setup wizard, connection management, plugin toggles, dashboards, and permission-aware REST/admin actions |

“Delivered” describes code in the repository. Optional connections still need
valid credentials, a reachable service, and models or workflows compatible
with that service. Provider-specific availability is an operating condition,
not unfinished World Graph Studio implementation.

The delivered Suno path provisions prompt-music, custom-music, and
`text_to_lyrics` Templates for both REST and MCP. One `suno` Connection keeps
the SunoAPI.org REST credential distinct from the AceData Cloud MCP credential.
See [Suno Integration](plugins/SUNO.md) for the transport boundary and the
[Integration Catalog](Integration_Catalog.md) for the complete table view,
including source-only and experimental integrations.

The Story Import & Export feature is bundled at
`plugins/story-import-export/` and enabled by default. JSON import/export and
Markdown export are deterministic and need no model. For other supported story
sources, an administrator selects a manageable OpenAI-compatible, OpenAI, or
Anthropic Connection, reviews the generated canonical JSON preview, and must
explicitly confirm before any Story Graph records are written. Uploaded source
files remain in WordPress uploads.

## Closed additional-script roadmap item

The blanket “additional script import/export” hold is retired. The accepted
current-release paths are:

- Canonical World Graph Studio JSON import and export.
- Markdown screenplay and storyboard export.
- JSON, TXT, Markdown, Fountain, RTF, text-layer PDF, EPUB, DOCX, and ODT story
  uploads, with LLM-assisted decomposition and an explicit preview/confirm
  boundary for non-canonical sources.
- Final Draft FDX import.
- Bidirectional VideoDraft synchronization for its shared structural Project
  subset.
- As adjacent library code—not live project interchange—CMX 3600 and SMPTE
  436m XML parsing, timecode, and format generation from clip arrays.

Each path adapts to the canonical Story Graph instead of introducing a second
project model. The Story Import & Export feature plugin owns the canonical
importer and live-Project exporters; FDX and VideoDraft pull delegate
persistence to that importer.

The separate deterministic Fountain-to-FDX importer source is bundled but its
current browser bootstrap fails before exposing the shared FDX parser. This
does not prevent the Story Import & Export plugin from accepting `.fountain`
as an unstructured source for LLM decomposition. Celtx connector source has
response and Scene-call defects that block verified outbound sync. Neither
separate integration is counted as delivered until repaired and tested.

Fade In, Highland, Story Architect, additional professional script exporters,
format-specific merge workflows, and further synchronization providers are
possible future adapters. They are not shipped features or active delivery
commitments, and they do not reopen the closed roadmap category.

## Current boundaries

- World Graph Studio does not require an AI or generation connection for core
  Story Graph work.
- Built-in automation depends on the modalities and adapters exposed by a
  configured connection. World Graph Studio can store broader media types even
  when it does not provide a direct connector for the service that created
  them.
- Hosted services can impose their own prices, quotas, licenses, moderation,
  and availability. World Graph Studio itself does not sell usage credits.
- Self-hosting gives the operator control of deployment and data location; site
  visibility and access still depend on WordPress and hosting configuration.
- Story-source uploads are retained as WordPress Media Library attachments;
  cancelling or completing an import does not delete the original file.
- PDF ingestion requires an extractable text layer. Image-only or scanned PDFs
  return an OCR-required error and must be made searchable before retrying.
- The compatibility REST routes for validation, import, decomposition preview,
  and export are delivered, but the optional headless application still lacks
  authenticated creator UI and therefore does not have interchange parity.
- AAF, OMF, provider-specific NLE panels, and other possible integrations are
  extension points, not current-release commitments.
- The bundled EDL PHP code parses and generates supported formats through a
  delivered admin workflow: import confirmation persists as an Editorial
  Artifact post, Project/Episode export resolves the live Scene/Shot
  timeline, and unparsable ASCII lines are reported with line numbers instead
  of being silently dropped.
- The bundled deterministic Fountain source has a browser bootstrap defect,
  and the bundled Celtx source has response-handling and Scene-call defects.
  They remain listed in the catalog as integration scaffolds rather than
  delivered workflows.
- The bundled Google Web Stories directory is prototype extension source. It
  is not loaded by the current plugin and is not a supported release workflow.
- The bundled Descript exchange source is experimental. Canonical media lookup,
  callback handling, binary transcript handling, wizard classification, and
  runtime contract tests remain incomplete, so it is not presented as a
  delivered workflow.

## Naming contract

- Product name: **World Graph Studio**
- Machine namespace and text domain: `worldgraph`
- PHP namespace: `WorldGraph`
- Constants and environment-variable prefix: `WORLDGRAPH_`
- REST namespace: `worldgraph/v1`

Legacy `storyos` identifiers may remain inside the one-time compatibility
migration and its tests. They are migration inputs, not current public names.
