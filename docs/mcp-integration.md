# MCP Integration

Model Context Protocol (MCP) is a standard that lets software advertise tools
and structured operations. World Graph Studio uses MCP in specific Connections;
it is not a switch that gives every AI application unrestricted access to a
project.

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
