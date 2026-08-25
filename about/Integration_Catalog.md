# World Graph Studio Integration Catalog

> Formats, providers, and specialist agents around one canonical Story Graph.

This catalog is the table view of the integration surfaces present in the
current repository, including delivered workflows, optional integrations,
scaffolds, and prototypes. [Delivery Status](Delivery_Status.md) remains the
source of truth for release status, while the linked guides define each
integration's exact contract.

## Bundled integration plugins

| Integration | Category | Direction | Current scope | Availability / setup |
| --- | --- | --- | --- | --- |
| [Story Import & Export](plugins/STORY_IMPORT_EXPORT.md) | Canonical project interchange and story decomposition | JSON ↔ Story Graph; supported text-bearing story file → validated JSON preview → Story Graph; Story Graph → Markdown | Owns canonical JSON import/export and Markdown screenplay/storyboard export; persists JSON/TXT/Markdown/Fountain/RTF/text-layer PDF/EPUB/DOCX/ODT uploads and uses a selected LLM Connection for preview/confirm decomposition when the source is not already canonical JSON | Bundled and enabled by default; an LLM Connection is required only for non-canonical sources |
| Final Draft FDX Import | Screenplay importer | FDX → Story Graph | Parses supported FDX screenplay structure in the browser, normalizes it to World Graph Studio JSON, and delegates validation and persistence to the Story Import & Export importer | Bundled; no external account |
| Deterministic Fountain Import | Screenplay importer | Intended: Fountain → FDX → Story Graph | Parser and importer source are bundled, but the shared FDX script currently dereferences the absent FDX form before exposing its parser on the Fountain page; this is separate from accepting `.fountain` as an LLM story source above | Bootstrap-blocked scaffold; not currently delivered |
| [Celtx Connector](plugins/CELTX.md) | Project synchronization | Intended: Story Graph → Celtx | Bundled mapping and REST source targets Projects, Characters, Locations, Scenes, and Shots, but response handling and Scene-call defects currently block a verified outbound sync | Runtime repair required; not currently delivered |
| [VideoDraft Sync](plugins/VIDEODRAFT.md) | Project synchronization | Story Graph ↔ VideoDraft | Pushes and pulls the shared structural Project subset with dry-run preview, checkpoints, conflict hashes, and per-Connection mappings | Optional; enabled `videodraft` Connection and PAT |
| [Descript Exchange](plugins/DESCRIPT.md) | Transcript and media exchange | Intended: Descript transcript → Story Graph; bound media → Descript | Source maps one composition transcript to a Project/World/transcript Scene and prepares asynchronous media-import jobs; canonical media lookup, callback handling, binary formats, wizard classification, and runtime verification remain incomplete | Experimental scaffold; not a delivered workflow |
| [EDL Format Tools](plugins/EDL_IMPORT_AND_EXPORT.md) | Editorial format library and admin workflow | CMX/XML ↔ normalized clip arrays | PHP parsing, timecode, and format-generation functions with a preview/confirm admin workflow; imports persist as Editorial Artifact posts, exports resolve a live Project or Episode Scene/Shot timeline, and unparsable ASCII lines are reported with line numbers | Delivered format code and admin workflow |
| [Google Web Stories](plugins/WEB_STORIES.md) | Publishing connector source | No supported runtime direction | Retained prototype code and design surface; not loaded by the main plugin and not a current release workflow | Prototype only |

## Story Import & Export surfaces

| Surface | Direction | Current scope | Access |
| --- | --- | --- | --- |
| World Graph Studio JSON import | JSON → Story Graph | Dry-run validation, entity creation or update, taxonomy assignment, relationship construction, and import reporting | WordPress Import screen and `POST worldgraph/v1/import/validate` / `POST worldgraph/v1/import` |
| Story document decomposition | Persisted story upload → canonical JSON preview → Story Graph | Extracts bounded text from JSON/TXT/Markdown/Fountain/RTF/PDF/EPUB/DOCX/ODT; canonical JSON skips the LLM, while other sources use the selected manageable LLM Connection and require preview/confirm before commit; PDF requires a text layer | WordPress Import screen and `POST worldgraph/v1/import/decompose`; source attachment remains in uploads |
| World Graph Studio JSON export | Story Graph → canonical JSON | Projects the selected live Project and its supported graph into a deterministic version 1.2 document suitable for validation and later import | WordPress Export screen and `GET worldgraph/v1/export/{project_id}?format=json` |
| Markdown screenplay export | Story Graph → Markdown | Derives a screenplay-style view from the live Project, ordered Scenes, Scene summary/script content, linked Character names, and Shot headings; structured dialogue appears only when already represented in Scene script content | WordPress Export screen and `GET worldgraph/v1/export/{project_id}?format=screenplay` |
| Markdown storyboard export | Story Graph → Markdown | Derives a storyboard view from live Scenes and Shots, including framing, lens, duration, and editorial notes | WordPress Export screen and `GET worldgraph/v1/export/{project_id}?format=storyboard` |
| Manual external asset intake | External media → WordPress | Stores uploaded or registered media with source, prompt, model, rights, and provenance context | Media Library and Asset workflows |

See [Script and Editorial Interchange](Script_EDL_Integration.md) for field
mapping and format-level boundaries.

## Executable generation Connection adapters

| Connection adapter | Transport | Delivered behavior | Required setup |
| --- | --- | --- | --- |
| ComfyUI | Local HTTP API; optional local MCP; Comfy Cloud MCP | Runs compatible Template-backed workflows, discovers supported capabilities where available, polls jobs, and imports completed media | Reachable local endpoint or Comfy Cloud credential |
| fal | Streamable HTTP MCP | Discovers model schemas, provisions text-to-image Templates, submits and polls image jobs, and imports results | fal API key |
| ElevenLabs | REST API | Provisions speech, dialogue, sound-effect, music, and voice-design Templates and imports returned audio or previews | ElevenLabs API key |
| Suno | SunoAPI.org REST plus AceData Cloud MCP | Provisions prompt-music, custom-music, and lyrics Templates, polls tasks, imports final songs, and retains normalized lyric results | Separate REST and MCP credentials |
| [MidJourney](plugins/MIDJOURNEY.md) | midjourney-api.com REST plus Ace Data Cloud MCP | Provisions the text-to-image Imagine Template for each configured transport, polls each transport through its reviewed task operation, and imports every final image; other advertised MCP tools remain outside the execution allowlist | Corresponding midjourney-api.com and/or separate Ace Data Cloud service credential |
| [Higgsfield](plugins/HIGGSFIELD.md) | Higgsfield REST plus hosted OAuth MCP | Executes three reviewed REST image/video operations, polls requests, uploads authorized reference media, imports every supported output, and performs bounded runtime MCP `tools/list` discovery; it does not execute discovered MCP tools | REST `KEY_ID:KEY_SECRET` plus separate Higgsfield-account OAuth for MCP |
| VideoDraft | Hosted JSON-RPC MCP | Discovers live image, video, and audio tools, provisions Templates, polls asynchronous work, uploads bound local references, and imports completed media | VideoDraft PAT |
| OpenRouter | REST API | Submits text-to-video (and image-to-video/reference-to-video) jobs to any OpenRouter video model, polls asynchronous jobs, and imports completed video | OpenRouter API key |

Connection status is the configured-startup and new-work authority for these
adapters; explicit trusted diagnostics can still load one on demand. Provider
model availability, pricing, quotas, regions, and terms remain external
operating conditions. See
[Deployment and Connections](Deployment_and_Connections.md) and
[Web GenAI Platform Support](WEB_GENAI.md).

## AI Editor backends

These are delivered model-access paths for the AI Editor and specialist agents;
they are not media-generation adapters. Story decomposition can select the
OpenAI-compatible, OpenAI, or Anthropic Connection types; it does not use the
legacy option-backed Dual fallback.

| Backend | Current scope | Required setup |
| --- | --- | --- |
| OpenAI-compatible | Local or hosted OpenAI-compatible chat endpoint | Compatible base URL, model, and optional service credential |
| OpenAI | Hosted OpenAI chat API | API key and model |
| Anthropic | Hosted Anthropic messages API | API key and model |
| Dual | Primary local/OpenAI-compatible path with configured cloud fallback | Both selected backend configurations |

## Registered extension placeholders

| Provider type | Current status |
| --- | --- |
| Google Gemini | Metadata-only Connection type; no bundled executable adapter |
| Veo | Metadata-only Connection type; no bundled executable adapter |
| Nova Reel | Metadata-only Connection type; no bundled executable adapter |

A provider name in a Connection record does not make that provider executable.
A supported adapter must implement authentication, validation, submission,
lifecycle handling, result retrieval, and WordPress asset ingestion.

## How the catalog grows

| Extension unit | Addition contract | Stable services it reuses |
| --- | --- | --- |
| Format integration | Normalize input to the World Graph Studio document contract or project output from live Story Graph records | Validation, identity mapping, relationships, permissions, and persistence |
| Connection integration | Register metadata, conditional loading, health/lifecycle callbacks, optional named public-client OAuth profiles, Template provisioning, generation dispatch, and optional guided setup through `worldgraph_conn_adapters`; implement explicit transport and output contracts | Stable Connection records, shared authorization-code + PKCE/token-refresh broker, common Template scheduling/upserts, generation jobs, media import, and provenance |
| Specialist agent | Add a focused `.agent.md` profile; router keywords are optional | Story Graph context, permission checks, bounded chat, editor selection, and configured LLM access |

The current bundle contains 51 specialist agent profiles. See
[Agent Architecture](Agent_Architecture.md) for the profile and routing
contract.
