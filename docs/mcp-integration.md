# MCP Integration

Model Context Protocol (MCP) is a standard that lets software advertise tools,
resources, prompts, and structured operations. World Graph Studio supports MCP
in two directions: it supplies permission-checked WordPress Abilities for
external assistants, and it acts as a client of selected generation-provider
MCP services. Neither direction gives an AI application unrestricted access to
a project or arbitrary access to a remote provider.

## MCP in the current release

World Graph Studio can act as an MCP client for supported external providers.
An administrator creates a Connection to an MCP endpoint, supplies the required
credential, and tests the advertised tools. Reviewed and allowlisted operations
can then support Template provisioning, generation, or synchronization,
depending on the adapter.

Examples include hosted generation services and an optional separate MCP
service used alongside local ComfyUI. Ordinary ComfyUI on port 8188 exposes an
HTTP API, not MCP; an MCP endpoint must be a real, separately configured MCP
server.

## External assistants connecting to World Graph Studio

World Graph Studio registers public, schema-described WordPress Abilities for
the complete agent workflow. A compatible WordPress MCP Adapter can expose
them to Claude, Codex, and other MCP clients. The adapter owns MCP transport
and authentication; World Graph Studio owns the tools and enforces WordPress
capabilities and object-level permissions.

Create a dedicated WordPress user for each agent integration and authenticate
the MCP adapter with that user's Application Password (or another adapter-
supported WordPress authentication mechanism). Assign only the capabilities
the workflow needs. Do not expose these abilities anonymously or share one
administrator credential across clients.

### What an MCP client can do

The exposed surface is organized around deliberate, reviewable workflows:

| Area | MCP-facing abilities | What they offer |
| --- | --- | --- |
| Discover and read | `content-schema`, `list-entities`, `get-entity` | Inspect supported content types, current SCF field contracts, bounded entity lists, individual records, and relationships. |
| Create and revise | `create-entity`, `update-entity` | Create or update supported Story Graph records, Templates, and Connections through the existing REST authorization boundary. |
| Import a story | `decompose-story`, `decompose-story-upload`, `import-story` | Turn supplied text or an existing upload into canonical JSON for review, then explicitly import the approved document. |
| Review a project | `review-project`, `add-review-note` | Read graph, production, timeline, editorial, and review state, and attach an editorial note. |
| Generate a project | `plan-end-to-end-generation`, `run-end-to-end-generation`, `review-generation` | Preview blockers without spending credits, queue a confirmed idempotent demonstration batch, and inspect progress and imported assets. |
| Exchange an edit | `preview-edl-import`, `import-edl`, `export-edl` | Preview and confirm CMX 3600 or XML EDL imports and export a live Project or Episode timeline. |
| AI Editor and media | `chat`, `analyze`, `generate`, `continuity-check`, `template-requirements`, `generate-asset` | Use configured AI assistance, validate Template requirements, and queue story-aware image or Shot video generation. |
| Context and prompts | `post-context`, `character-context`, `scene-context`, `templates-manifest`, `story-review-prompt`, `continuity-prompt` | Read focused Story Graph context and Template manifests, or obtain structured review and continuity prompts. |

Ability identifiers use the `worldgraph/` namespace. Availability still depends
on installed optional features and configuration: story decomposition requires
Story Import & Export plus a manageable LLM Connection; EDL operations require
the EDL Format Tools plugin; AI and generation operations require suitable
Connections and Templates.

The principal workflow is:

1. `worldgraph/decompose-story-upload` previews an attachment uploaded by the
   existing form, or `worldgraph/decompose-story` accepts story text directly.
2. The agent and user review the canonical JSON, then explicitly call
   `worldgraph/import-story` to populate the database.
3. `content-schema`, `list-entities`, `get-entity`, `create-entity`, and
   `update-entity` expose all supported Story Graph posts, generation Templates,
   Connections, and writable SCF fields without bypassing REST authorization.
4. `review-project` and `add-review-note` expose production and editorial state.
5. `plan-end-to-end-generation` previews provider/template blockers before
   `run-end-to-end-generation` creates a durable, idempotent batch.
6. `review-generation` returns batch progress and generated asset records.
7. `preview-edl-import`, `import-edl`, and `export-edl` provide the existing
   CMX 3600/XML preview-confirm and timeline export workflow agentically.

Generation tools can spend provider credits, so clients should always show the
plan and obtain user confirmation before invoking the run ability. World Graph
Studio does not bundle the MCP transport adapter itself.

### Requirements and limits

- Use WordPress 7.1 or later so the WordPress Abilities API is available.
- Install and configure a compatible WordPress MCP Adapter separately. World
  Graph Studio does not create an MCP endpoint by itself.
- Authenticate as a WordPress user. Each ability applies its declared
  capability check, and record operations also enforce object-level access.
- Treat `decompose-story`, imports, record writes, review notes, generation,
  and EDL imports as write operations. A client should preview or confirm them
  with the user where the workflow provides that boundary.
- The registered specialist `.agent.md` profiles are not automatically exposed
  as separate MCP servers. They remain roles used by World Graph Studio's AI
  layer; MCP clients call the published abilities.

Developers extending this boundary should follow the authentication,
permissions, operation allowlist, and transport requirements in the
[Connection adapter specification](../about/Connection_Adapter_Development_Specification.md).

## Security expectations

Treat an MCP endpoint like any other external service:

- verify who operates it and what data its tools receive;
- use HTTPS for remote endpoints;
- store credentials in supported encrypted or environment-managed references;
- expose only reviewed operations and required models;
- test with non-destructive discovery before running a paid operation; and
- review provider retention, pricing, and cancellation behavior.

An advertised remote tool is not automatically safe to execute. World Graph
Studio adapters are expected to normalize and allowlist operations rather than
offer arbitrary remote tool execution.
