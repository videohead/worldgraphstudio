# MidJourney Connection

> Text-to-image generation through reviewed third-party REST and MCP
> transports.

## Delivery boundary

World Graph Studio registers one `midjourney` Connection with two independent
services:

| Transport | Endpoint | Credential field | Authentication |
| --- | --- | --- | --- |
| REST | `https://api.midjourney-api.com` | `credential_reference` | `API-KEY` header issued by midjourney-api.com |
| MCP | `https://midjourney.mcp.acedata.cloud/mcp` | `mcp_credential_reference` | Bearer token issued by Ace Data Cloud |

These credentials are not interchangeable. Midjourney does not publish an
official public API; midjourney-api.com describes itself as a third-party
bridge. Ace Data Cloud is a second third-party service that operates the hosted
MCP endpoint and its upstream API access. Neither transport is authenticated by
a Midjourney web subscription, login, session, or cookie.

The delivered scope is deliberately narrow:

- submit a text prompt through the documented Imagine operation;
- poll the returned task through the matching transport;
- normalize every final HTTPS image URL; and
- import every final image into the WordPress Media Library before completing
  the generation job.

The adapter does not expose arbitrary MCP tools. Transform, blend, reference
image, edit, describe, translation, seed, and video tools advertised by Ace
Data Cloud remain outside this initial reviewed execution allowlist.

## Configure the Connection

MidJourney is configured from **World Graph Studio > Connections** and is not a
first-run Setup Wizard choice.

Create a published, enabled Connection for either or both transports with:

- Provider Type: `midjourney`
- Endpoint URL: `https://api.midjourney-api.com`
- MCP Endpoint URL: `https://midjourney.mcp.acedata.cloud/mcp`
- API Key / OAuth Reference: the midjourney-api.com key when REST is enabled,
  preferably
  `env://MIDJOURNEY_API_KEY`
- MCP API Key / OAuth Reference: the Ace Data Cloud Midjourney service token
  when MCP is enabled, preferably `env://ACEDATACLOUD_API_TOKEN`
- Environment: `production`

Obtain the REST key from the
[midjourney-api.com console](https://midjourney-api.com/dashboard). Obtain a
service-scoped Ace Data Cloud token from the
[Ace Data Cloud platform](https://platform.acedata.cloud) after acquiring its
Midjourney service. Do not use an Ace Data Cloud platform-management token for
the MCP field.

Literal credentials are write-only and encrypted when the host supports the
plugin's credential store. An `env://` reference must name an uppercase
environment variable matching `^[A-Z_][A-Z0-9_]*$`.

## Provisioned Templates

Saving or successfully testing the Connection idempotently creates or updates
the text-to-image Template for each configured credential enabled by Model
Access. With Model Access empty, configuring both credentials creates both
Templates:

| Template reference | Transport | Defaults |
| --- | --- | --- |
| `api:imagine` | midjourney-api.com REST | `mode=fast`, `timeout=600` |
| `mcp:midjourney_imagine` | Ace Data Cloud MCP | `mode=fast`, `translation=false`, `split_images=true`, `timeout=480` |

The spelling of the slower mode differs by provider and is intentional:

- REST accepts `fast` or `relaxed`;
- MCP accepts `fast`, `relax`, or `turbo`.

The Template reference selects a trusted loaded client. A persisted
`midjourney_api` or `midjourney_mcp` adapter marker keeps later WP-Cron polling
on the same transport. Runtime parameters are allowlisted and revalidated by
the selected client; arbitrary Template JSON cannot add a callback URL, MCP
tool, endpoint, or request field.

Leave **Model Access** empty to enable every transport with a configured
credential. To narrow it, use an exact JSON array containing `api:imagine`,
`mcp:midjourney_imagine`, or both. Unknown entries and an empty array fail
closed; the allowlist controls both Template provisioning and runtime dispatch.

## REST lifecycle

The REST Template calls:

1. `POST /midjourney/v1/submit-jobs` with `prompt`, optional reviewed `mode`,
   and optional timeout from 300 through 1200 seconds. World Graph Studio omits
   `hookUrl` and uses polling.
2. `POST /midjourney/v1/job-status` with the one returned task ID.

The provider's documented task states are normalized as follows:

| Provider status | World Graph Studio status |
| --- | --- |
| `0` | `submitted` |
| `1` | `completed` after final images are present |
| `2` | `failed` |

Provider envelope status `0` means the API request was accepted. Documented
envelope codes `1001` and `1002` report insufficient quota and concurrency
limits. A transport failure after a billable submit begins is ambiguous and is
not automatically resubmitted; check the provider console before retrying.

The Connection check performs a non-generating status lookup with a fixed
invalid task ID. It never submits an Imagine job merely to test credentials.

## MCP lifecycle

The hosted MCP server documents initialization-based Streamable HTTP at MCP
revision `2025-03-26`. World Graph Studio:

1. sends `initialize` and validates the negotiated version and `tools`
   capability;
2. preserves an optional `Mcp-Session-Id`;
3. sends the required `notifications/initialized` notification;
4. follows bounded `tools/list` pagination and requires
   `midjourney_imagine` plus `midjourney_get_task`;
5. permits `tools/call` only for those two names;
6. correlates JSON or fully framed SSE responses by JSON-RPC request ID; and
7. closes an issued session and performs one bounded reinitialization if that
   session expires.

`midjourney_imagine` receives only the reviewed prompt, mode, translation,
split-image, and timeout values. `midjourney_get_task` receives only the
provider task ID. Tool descriptions, schemas, and content are untrusted remote
data and are bounded before storage or decoding.

The hosted endpoint also advertises an OAuth authorization service. This
adapter uses the provider-documented service token path instead of registering
a browser OAuth profile; administrators place that Bearer token in the
separate MCP credential field.

## Output and failure behavior

Both clients normalize pending, complete, failed, and cancelled states before
they reach the shared generation worker. A successful task must provide at
least one valid HTTPS image URL. Duplicate URLs, unsafe schemes, malformed
results, tool-level `isError`, missing required tools, unsupported protocol
versions, and completed tasks without media fail closed.

The shared generation importer downloads every normalized output and attaches
it through the existing authorized WordPress media boundary. Raw image bytes
are never stored in generation post metadata. Provider URLs are not treated as
the final project copy.

Neither reviewed transport documents cancellation or a submit idempotency key
for this scope. World Graph Studio therefore does not claim provider-side
cancellation and does not retry an ambiguous submission.

## Connection check and workflow refresh

**Check connection** requires at least one credential whose reference is
enabled by Model Access and validates each enabled transport:

1. when REST is credentialed and enabled, the status lookup must authenticate
   and return a valid bounded JSON envelope;
2. when MCP is credentialed and enabled, MCP must complete initialization and
   expose both required tools; and
3. every enabled transport-specific Template must provision successfully.

Core persists the Connection status, validation timestamp, bounded health
data, and `midjourney_catalog_*` workflow-refresh metadata. Disabling the
Connection prevents new work and automatic adapter loading.

## Troubleshooting

### REST check fails

- Confirm Endpoint URL is exactly `https://api.midjourney-api.com`.
- Put the midjourney-api.com key—not the Ace Data Cloud token—in the primary
  credential field.
- Check quota and concurrency in the provider console.

### MCP check fails

- Confirm MCP Endpoint URL is
  `https://midjourney.mcp.acedata.cloud/mcp`.
- Put an Ace Data Cloud Midjourney business/service token in the MCP credential
  field; do not use the REST key or a `platform-` management token.
- Confirm live discovery still advertises `midjourney_imagine` and
  `midjourney_get_task`.

### A task never completes

- Ensure a reliable host scheduler invokes `wp-cron.php`.
- Inspect the Generation Job and Connection activity history.
- Query the matching provider with the recorded remote task ID before
  submitting again after an ambiguous failure.

## Provider references

- [midjourney-api.com introduction](https://docs.midjourney-api.com/api-reference/introduction)
- [midjourney-api.com quickstart](https://docs.midjourney-api.com/quickstart)
- [Imagine submit endpoint](https://docs.midjourney-api.com/api-reference/endpoint/create)
- [Job status endpoint](https://docs.midjourney-api.com/api-reference/endpoint/jobstatus)
- [Ace Data Cloud MidJourney MCP guide](https://docs.acedata.cloud/en/mcp/midjourney)
- [Ace Data Cloud authentication](https://docs.acedata.cloud/en/authentication)
- [AceDataCloud/MidjourneyMCP](https://github.com/AceDataCloud/MidjourneyMCP)
- [Ace Data Cloud terms](https://docs.acedata.cloud/en/resources/terms)
- [Ace Data Cloud privacy](https://docs.acedata.cloud/en/resources/privacy)
