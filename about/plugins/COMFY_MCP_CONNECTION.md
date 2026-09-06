# ComfyUI MCP Connection

> **Scope:** Local ComfyUI connectivity in World Graph Studio, including the
> optional separate MCP service.
>
> **Delivery status:** Local HTTP execution, optional MCP discovery, capability
> probing, readiness checks, and local HTTP fallback are implemented for the
> current release.

## Connection model

World Graph Studio treats local ComfyUI as two related but separate services:

1. **ComfyUI HTTP API** executes workflows and returns generated media.
2. **ComfyUI MCP service** optionally provides template discovery and model
   download operations.

The standard ComfyUI HTTP server on port `8188` does not speak MCP. Do not
construct an MCP URL by appending `/mcp` to the normal ComfyUI URL:

```text
http://host.docker.internal:8188/mcp
```

That URL is valid only if a separate MCP server is actually listening there.

## Local URLs

For the repository's Docker Compose environment, the usual configuration is:

```text
ComfyUI API URL:  http://host.docker.internal:8188
MCP URL:          optional, for example http://host.docker.internal:9000/mcp
```

The API URL must be reachable from the WordPress `wordpress` container:

- `localhost` refers to the WordPress container, not the development host.
- `host.docker.internal` resolves from the Compose containers to the host
  machine through the configured host-gateway mapping.
- If ComfyUI runs in another Docker Compose project, publish its port to the
  host, for example `8188:8188`.
- If ComfyUI runs as a service on the same Docker network, its service hostname
  can be used instead.

The local API URL is required for local generation. The MCP URL is optional and
must identify a separate Streamable HTTP MCP server. Leaving the MCP field
empty gives a functional HTTP-only local configuration.

## External ComfyUI Docker project

The World Graph Studio repository does not contain the ComfyUI Docker Compose
project. Run that project separately and publish the endpoints needed by the
World Graph Studio stack. The local setup checks ComfyUI on port `8188` only;
it does not install, start, or health-check an MCP server.

If the ComfyUI Docker project is in another folder, add the MCP process there.
The MCP process may run in its own container or alongside ComfyUI in the same
container, depending on the MCP server's deployment requirements. It must:

1. expose a Streamable HTTP endpoint, such as `/mcp`;
2. listen on a port separate from ComfyUI's HTTP port, such as `9000`;
3. bind to `0.0.0.0` inside its container when the endpoint is published;
4. be able to reach the ComfyUI service and any workflow/model paths it needs;
5. be included in the ComfyUI project's Compose startup; and
6. be reachable from the WordPress `wordpress` container.

For example, the external project might publish these host ports:

```yaml
services:
  comfyui:
    ports:
      - "8188:8188"

  comfyui-mcp:
    ports:
      - "9000:9000"
```

The exact image, command, environment variables, MCP implementation, and
internal service port are owned by the external ComfyUI project. The example
above is a port-mapping shape only; it does not install an MCP server by
itself.

When both services publish ports on the development host, configure the
WordPress setup wizard with:

```text
Local ComfyUI API URL:  http://host.docker.internal:8188
Local ComfyUI MCP URL:  http://host.docker.internal:9000/mcp
```

If the MCP service uses another host port or route, enter that actual URL. If
the MCP service is reachable through a shared Docker network instead, use its
Docker service hostname only when that hostname is resolvable from the
`wordpress` container. Publishing the port to the host and using
`host.docker.internal` is the straightforward arrangement for a separate
Compose project.

The external project's startup should verify both endpoints before reporting
success. The HTTP service can be checked with `GET /system_stats`; the MCP
service should be checked with an MCP `initialize` request followed by
`tools/list`. World Graph Studio's setup button checks only `/system_stats`, so
an independent MCP health check is needed to catch a missing or misconfigured
second service during Docker startup.

The Connection also provides an optional **MCP Configuration (JSON)** field for
non-secret deployment metadata. For example:

```json
{
  "transport": "streamable-http",
  "host": "host.docker.internal",
  "port": 9000,
  "path": "/mcp",
  "docker_service": "comfyui-mcp",
  "health_check": "initialize/tools-list"
}
```

This field records the deployment contract; it does not start a process,
publish a Docker port, or replace `mcp_endpoint_url`. Keep API keys, bearer
tokens, and other secrets in the MCP credential reference or the deployment's
secret manager.

### External-project checklist

Before entering the MCP URL in WordPress, confirm:

- the ComfyUI container answers `http://localhost:8188/system_stats` on the
  host;
- the MCP container or process is running in the external project;
- the host's MCP port is published, for example `9000:9000`;
- the MCP route is real, for example `/mcp`, rather than an appended route on
  ComfyUI's `8188` server;
- the MCP server can reach the ComfyUI service; and
- the WordPress service can reach both services through
  `host.docker.internal`.

Do not add a second MCP service to the World Graph Studio Compose project
unless the deployment intentionally moves ownership of the MCP runtime here.
The normal ownership boundary is the external ComfyUI project: it runs ComfyUI
and its optional MCP service, while World Graph Studio stores the endpoint and
connects to it.

## Setup Wizard

In the Generation Connection section, select **Local ComfyUI HTTP API + MCP**.
Enter the local API URL and, if available, the separate MCP URL.

The **Test Generation Connection** action tests only the ComfyUI HTTP API by
requesting:

```text
GET /system_stats
```

It does not perform an MCP handshake. MCP availability is checked when the
Connection is probed for catalog or MCP operations.

When the setup form is saved, World Graph Studio:

- stores `worldgraph_comfy_local_url`;
- stores `worldgraph_comfy_local_mcp_url`;
- creates or updates the managed `comfyui` Connection;
- stores the API URL as `endpoint_url`;
- stores the optional MCP URL as `mcp_endpoint_url`; and
- provisions the managed local text-to-image Template.

The setup wizard does not require an API key for local ComfyUI. Any
authentication required by a local reverse proxy or MCP service is an
infrastructure concern and must be supported by that service.

## Local HTTP API

The local adapter communicates directly with the ComfyUI API using:

```text
POST /prompt
GET  /history/{prompt_id}
POST /upload/image
GET  /object_info
GET  /view
```

The readiness panel also checks:

```text
GET /system_stats
GET /object_info
```

These checks verify that ComfyUI is running and that the exact nodes and models
needed by the managed registry-backed text-to-image workflow are installed. A
bare HTTP-only ComfyUI can therefore run the converted managed Template without
falling back to a legacy checkpoint graph.

## MCP protocol flow

When `mcp_endpoint_url` is configured, World Graph Studio uses Streamable HTTP
JSON-RPC:

1. Send `initialize` with protocol version `2025-03-26`.
2. Read the `Mcp-Session-Id` response header.
3. Call `tools/list` to discover server capabilities.
4. Call supported operations with `tools/call`.

Requests use these headers:

```http
Accept: application/json, text/event-stream
Content-Type: application/json
```

The local MCP path does not add the hosted Comfy Cloud `X-API-Key` header. A
local MCP service should therefore be protected by its own network boundary,
reverse proxy, or authentication mechanism where required.

## MCP tools

World Graph Studio recognizes these template-system tools:

```text
list_templates
get_template
download_models
```

Their roles are:

- `list_templates`: discover provider workflow templates;
- `get_template`: retrieve a selected workflow and its requirements; and
- `download_models`: request provider-side model installation.

A local MCP service may expose additional tools. World Graph Studio records the
advertised tool names and only offers operations supported by that server.

## Capability tiers

Each ComfyUI Connection is classified independently:

| Tier | Meaning | Catalog source | Model installation |
| --- | --- | --- | --- |
| `a` | MCP exposes all three template tools | Provider MCP catalog | Provider-side downloads available |
| `b` | MCP is reachable but exposes only part of the toolset | Advertised tools | Manual work may be required |
| `c` | No MCP endpoint is configured | Built-in modalities and local `/object_info` | Manual installation through ComfyUI |
| `unreachable` | Configured MCP endpoint could not be probed | Local synthesized catalog where possible | MCP action reports the connection error |

An HTTP-only local ComfyUI has no provider template list. World Graph Studio
synthesizes catalog entries from its registered modalities and inspects
`/object_info` when available. These entries describe possible support; they do
not install custom nodes or models.

## Generation and fallback

The managed local text-to-image Template is the executable zero-MCP path. Local
HTTP execution submits a workflow to `/prompt`, polls `/history/{prompt_id}`,
and retrieves outputs through `/view`.

When an MCP-backed submission is selected for a local Connection and the MCP
request fails, the generation batch retries through the direct local ComfyUI
HTTP adapter. This means a working local API can continue to generate even if
the optional MCP service is temporarily unavailable, provided the selected
workflow and its requirements are installed locally.

## Troubleshooting

### WordPress cannot reach ComfyUI

From the WordPress container, verify that the configured API URL resolves to
ComfyUI and that `GET /system_stats` returns a successful response. Replace
`localhost` with `host.docker.internal` when ComfyUI is running on the Docker
host.

### `/mcp` returns an error on port `8188`

This is expected for ordinary ComfyUI. Start or expose a separate MCP server
and enter that server's URL in **Local ComfyUI MCP URL**.

### Readiness reports missing nodes or checkpoint

The HTTP connection is working, but the local ComfyUI installation does not
contain everything required by the managed workflow. Install the required
custom nodes or checkpoint in ComfyUI, then run the readiness check again.

### MCP is reachable but discovery is incomplete

Inspect the advertised tools. Full automatic template provisioning requires
`list_templates`, `get_template`, and `download_models`. A server exposing only
some of these tools receives tier `b` behavior.

## Related documentation

- [Setup Guide](../../wordpress/wp-content/plugins/worldgraph/documentation/SETUP_GUIDE.md)
- [Setup Wizard Guide](../../wordpress/wp-content/plugins/worldgraph/documentation/SETUP_WIZARD_GUIDE.md)
- [Generation Engine](../plugins/GENERATION_ENGINE.md)
- [ComfyUI Template Catalog](COMFY_TEMPLATE_CATALOG.md)
- [Deployment and Connections](../Deployment_and_Connections.md)
