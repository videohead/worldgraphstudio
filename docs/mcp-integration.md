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

The repository contains WordPress Abilities declarations intended to support
future AI capability exposure, but the complete inbound MCP registration and
client workflow is not currently a supported release feature. Do not assume
that Claude, Cursor, VS Code, Windsurf, or another MCP client can connect
directly to a World Graph Studio site merely because outbound MCP Connections
are available.

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
