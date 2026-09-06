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
| Provider adapters | A filterable, callback-driven Connection/Template/generation registry plus local ComfyUI HTTP workflows, Comfy Cloud MCP, fal MCP, ElevenLabs, SunoAPI.org REST, AceData Cloud Suno MCP, midjourney-api.com REST, Ace Data Cloud MidJourney MCP, Higgsfield reviewed REST generation with OAuth MCP catalog discovery, Seedance 2.5 generation through CyberBara REST, VideoDraft MCP, OpenRouter video generation REST, and manually managed external-generator workflows where configured |
| Project interchange | Default-enabled Story Import & Export plugin with canonical World Graph Studio JSON import/export, Markdown screenplay/storyboard export, and resumable preview/confirm LLM decomposition of supported persisted story documents through structural PHP plans, two model passes, and JavaScript-driven checkpoints; Final Draft FDX import; optional VideoDraft structural Project push/pull |
| Synchronization | Optional bidirectional VideoDraft structural synchronization, with persistent remote-ID mappings |
| Editorial format code | CMX 3600 and SMPTE 436m XML parsing, timecode, and format-generation functions; the bundled admin workflow remains incomplete |
| Extension surfaces | Canonical import contract, bundled format and synchronization plugins, filterable Connection lifecycle/Template/generation adapters, reusable manifest-profile public-client OAuth with PKCE/token refresh, profile-driven agents, REST APIs, and WordPress Abilities |
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

The delivered MidJourney boundary can provision one text-to-image Imagine
Template for midjourney-api.com REST and one for Ace Data Cloud MCP, according
to the configured credentials and optional Model Access allowlist. One
`midjourney` Connection keeps the two intermediary credentials and auth headers
distinct. Only `midjourney_imagine` and `midjourney_get_task` are
allowlisted on MCP; other advertised image-editing, transformation, reference,
description, seed, and video tools are not executable in this scope. Both
asynchronous paths import every final image before completion. See
[MidJourney Connection](plugins/MIDJOURNEY.md).

The delivered Higgsfield boundary provisions three reviewed REST Templates:
Soul standard text-to-image, Higgsfield DoP standard image-to-video, and Kling
Video 2.1 Pro image-to-video. It uses the REST API for all generation and keeps
the `KEY_ID:KEY_SECRET` credential separate from Higgsfield account OAuth for
the hosted MCP service. MCP support is authenticated, bounded runtime
`tools/list` discovery only; discovered remote tools do not become executable
Templates and the adapter exposes no arbitrary `tools/call`. See
[Higgsfield Connection](plugins/HIGGSFIELD.md).

The delivered Seedance boundary is one manually configured `seedance_25`
Connection to the CyberBara REST intermediary. It provisions only the fixed
`seedance-2.5:text-to-video` and `seedance-2.5:image-to-video` Templates,
reauthorizes and bounds local reference-image uploads, polls asynchronous
tasks, validates the full bounded output list, and imports every distinct final
video. It is not a direct
ByteDance, BytePlus, Volcengine, or Dreamina integration. CyberBara does not
document a submission idempotency key, provider-side cancellation, or a
callback contract for this path, so ambiguous paid submissions are not retried
automatically and completion is reconciled by polling. See
[Seedance 2.5 via CyberBara](plugins/SEEDANCE.md).

The Story Import & Export feature is bundled at
`plugins/story-import-export/` and enabled by default. JSON import/export and
Markdown export are deterministic and need no model. For other supported story
sources, PHP produces an ordered chapter/section/Scene/paragraph/sentence-aware
plan with context kept separate from each primary span. The configured
manageable OpenAI-compatible, OpenAI, or Anthropic Connection first extracts
evidence, then synthesizes each span against bounded related observations and a
compact evolving graph. The default related-evidence retrieval is private and
lexical, with hooks for a bounded private index. The wp-admin JavaScript
advances the user-scoped transient job one checkpoint at a time and can resume
it after an interrupted request. Prepared text and intermediates are stored as
verified, bounded transient shards rather than one monolithic job value; plans
for the 500,000-character extraction ceiling may contain up to 1,000 spans.
Only the final normalized, dry-run-validated version 1.2 candidate is
reviewable or importable, and the administrator must explicitly confirm before
any Story Graph records are written. Uploaded source files remain in WordPress
uploads.

The bundled `plugins/story-rag-decomposer/` bridge is an optional,
disabled-by-default enhancement to that evidence retrieval. WPVDB is a hard
requirement for this enhancement only: operators must separately install and
activate it as the top-level `wp-content/plugins/wpvdb` plugin and configure an
active embedding provider and model before enabling Story RAG Decomposer.
World Graph Studio does not install or contain WPVDB, and the base decomposer
continues to work with lexical retrieval without it.

The bridge sends bounded inputs to the configured embedding provider, but
stores at most 128 uniformly sampled numeric vectors and opaque identifiers in
private, user/run-scoped transients whose expiration cannot exceed the owning
run's fixed deadline. Resumable runs begin with the 806,760-second active-job
deadline; synchronous runs receive a separate bounded scope and request
terminal cleanup. The bridge never inserts private text or vectors into
WPVDB's embeddings table. WPVDB Core briefly uses its shared object cache while
servicing a call; the bridge HMAC-scopes the input and best-effort evicts the
exact entry before and after the call. Terminal jobs request per-run cleanup
after their durable checkpoint commits, with transient expiration as backstop.
Missing configuration and runtime errors fall back to the built-in lexical
retriever and do not block story decomposition.

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
- Higgsfield REST submits have no documented idempotency key and its operation
  schemas are provider-owned and volatile. World Graph Studio executes only
  three reviewed allowlisted operations, does not retry ambiguous submits, and
  treats MCP as discovery rather than generation.
- The delivered MidJourney transports are third-party intermediaries, not an
  official Midjourney public API. Neither reviewed submit operation documents
  an idempotency key or cancellation contract, so ambiguous paid submits are
  not automatically retried and provider-side cancellation is not claimed.
- Self-hosting gives the operator control of deployment and data location; site
  visibility and access still depend on WordPress and hosting configuration.
- Story-source uploads are retained as WordPress Media Library attachments;
  cancelling or completing an import does not delete the original file.
- PDF ingestion requires an extractable text layer. Image-only or scanned PDFs
  return an OCR-required error and must be made searchable before retrying.
- The compatibility REST routes for validation, import, persisted-attachment
  decomposition-job creation/status/step/cancellation, bounded synchronous
  preview, and export are delivered. The synchronous path accepts canonical
  JSON or a non-canonical source of at most 1,400 UTF-8 characters whose initial
  plan is exactly one span; other non-canonical sources use the resumable
  collection route. The optional headless application still lacks
  authenticated creator UI and therefore does not have interchange parity.
- Story text is model input data, never conversational history: decomposition
  uses an untrusted JSON story envelope under server-owned system instructions.
  Optional provider reasoning controls are server-owned, and model
  chain-of-thought is not requested as output, checkpointed, or exposed.
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
