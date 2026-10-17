# Comfy API v2 Execution

World Graph Studio runs ComfyUI generations exclusively through the
[Comfy API v2](https://docs.comfy.org/api-reference/v2/overview): durable jobs
you submit, poll, and retrieve as content-addressed assets. The legacy ComfyUI
v1 HTTP endpoints (`/prompt`, `/history/{id}`, `/upload/image`, `/view`) are no
longer used for generation.

## One Adapter, Three Surfaces

The v2 contract is served identically by:

| Surface | Base URL | Credential |
| - | - | - |
| Self-hosted ComfyUI, via [comfy-api-proxy](https://github.com/Comfy-Org/comfy-api-proxy) | `https://videohead.duckdns.org/comfy` | Bearer proxy token (`COMFY_API_PROXY_TOKEN`, propagated to WordPress as `WORLDGRAPH_COMFY_API_KEY`) |
| Comfy Cloud | `https://cloud.comfy.org` | Cloud API key (`COMFY_CLOUD_API_KEY`) |
| Comfy API deployment | `https://{deployment}.run.comfy.app` | Cloud API key |

The plugin has a single v2 execution client (`Local_ComfyUI`). A Connection's
endpoint and credential select the surface; no client code changes between
local and Cloud.

## Local Deployment Topology

```
traefik (videohead.duckdns.org)
  ├─ /comfy/api/v2/*  → comfy-api-proxy :8189  (v2 API; bearer token)
  └─ /comfy/*         → ComfyUI :8188          (web UI; native UI endpoints)
comfyui-mcp :9000                                (MCP: discovery and downloads)
```

`comfy-api-proxy` runs as its own compose service (`/opt/ComfyUI`,
`api-proxy/Dockerfile`) next to ComfyUI. It wraps ComfyUI's native API
one-to-one: `POST /api/v2/jobs` → `/prompt`, `GET /api/v2/jobs/{id}` →
`/history/{id}` + `/queue`, uploads to a content-addressed asset store with
blake3 dedup, and SSE job events bridged from ComfyUI's websocket.

Notes learned the hard way:

- The proxy does not serve `/object_info` or `/system_stats`. Node-catalog
  discovery flows through the local MCP service instead, and the readiness
  checks probe the native ComfyUI surface (`/comfy/system_stats`) rather than
  the v2 surface.
- The proxy mints embedded `url` fields from its request origin, which drops
  the `/comfy` path prefix under traefik's strip-prefix middleware. The client
  therefore rebuilds output content URLs from the configured endpoint and the
  output's asset id instead of following embedded links.
- Comfy Cloud serves signed, short-lived URLs (~6 hours) on `Output.url`. The
  client ignores them for the same reason and re-resolves through
  `GET /api/v2/assets/{id}/content` per download.

## Execution Flow

1. Media inputs upload once via `POST /api/v2/assets` (multipart,
   content-addressed, deduped by blake3). The returned asset id is placed in
   the workflow as a `core/ASSET` reference; the proxy resolves it to the
   filename ComfyUI expects at submit time.
2. Submit the workflow graph via `POST /api/v2/jobs` with `{"workflow": ...}`.
   Validation is synchronous: unknown node classes, malformed graphs, and
   missing assets are rejected before queuing, with per-node error details in
   the `invalid_workflow` envelope.
3. Poll `GET /api/v2/jobs/{id}`. Statuses map to the batch processor as:
   `queued`/`running` → submitted, `succeeded` → completed,
   `failed`/`expired` → failed, `canceled`/`canceling` → cancelled.
4. Completed outputs carry asset ids; the client builds
   `/api/v2/assets/{id}/content` URLs and the batch downloads the bytes with
   the same bearer credential used to submit.

## Credentials

Resolution order for a v2 request:

1. The Connection's credential reference (literal or `env://NAME`).
2. `WORLDGRAPH_COMFY_API_KEY` (the proxy token for local connections).
3. `COMFY_CLOUD_API_KEY` (Cloud connections carry no local proxy token).

Both environment variables are wired into the WordPress container through
`/opt/worldgraphstudio/compose.yaml`.

## Discovery Stays On MCP

Template discovery, node catalogs, model downloads, and template-variable
rendering remain MCP operations: the local MCP service (`:9000`) for
self-hosted ComfyUI and `cloud.comfy.org/mcp` for Comfy Cloud. MCP never runs
generation jobs; the v2 API never does discovery.
