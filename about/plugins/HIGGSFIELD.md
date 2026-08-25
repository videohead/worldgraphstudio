# Higgsfield Connection

**Status:** Delivered within the REST-execution and MCP-discovery boundary
described below.

World Graph Studio represents Higgsfield as one hybrid `higgsfield`
Connection with two independent authentication domains:

| Surface | Endpoint | Credential | World Graph Studio use |
| --- | --- | --- | --- |
| Higgsfield REST API | `https://platform.higgsfield.ai` | `KEY_ID:KEY_SECRET` in `credential_reference`, preferably through `env://HIGGSFIELD_API_CREDENTIAL` | Executes three reviewed image/video operations, polls requests, uploads authorized reference media, and imports final outputs |
| Higgsfield hosted MCP | `https://mcp.higgsfield.ai/mcp` | Higgsfield account OAuth in `mcp_credential_reference` | Authenticates and performs bounded runtime `tools/list` discovery only |

The API key pair and MCP account authorization are not interchangeable. MCP
does not serve as a fallback for REST generation, and World Graph Studio does
not silently move a paid request between transports.

## Configure the Connection

Higgsfield is configured manually under **World Graph Studio > Connections**;
it is not a first-run Setup Wizard choice.

1. Create and publish a Connection with Provider Type **Higgsfield** and
   Environment **Production**.
2. Keep Endpoint URL at `https://platform.higgsfield.ai` and MCP Endpoint URL
   at `https://mcp.higgsfield.ai/mcp`. The adapter rejects other origins.
3. In **API Key / OAuth Reference**, enter the combined REST credential as
   `KEY_ID:KEY_SECRET`. For production, prefer
   `env://HIGGSFIELD_API_CREDENTIAL` and set that environment variable to the
   same combined value.
4. Save the published, enabled Connection.
5. In the provider controls, choose **Connect Higgsfield MCP**, sign in to the
   Higgsfield account, and approve `openid`, `email`, and `offline_access`.
6. Run **Check connection**. Readiness requires REST authentication, a
   non-empty valid MCP tool catalog, and successful provisioning of the
   selected reviewed REST Templates—three when `Model Access` is blank.

The MCP button uses the shared profile-based Connection OAuth broker. It runs
the public-client authorization-code flow with S256 PKCE, performs dynamic
client registration when the provider profile declares it, verifies one-time
state, stores access and refresh tokens encrypted at rest, and refreshes an
expiring token under a per-Connection lock. Disconnecting removes the local
MCP credential. It does not revoke provider-side sessions or delete data
already held by Higgsfield.

The callback URL is fixed to the site's administrator endpoint:

`/wp-admin/admin-post.php?action=worldgraph_connection_oauth_callback`

Production WordPress must use HTTPS for the callback; loopback HTTP is accepted
only for local development. See
[Adding Connections and Templates](../Adding_Connections_and_Templates.md#reusable-oauth-profiles)
for the provider-neutral profile contract.

## Reviewed REST Templates

World Graph Studio provisions only these fixed operation references:

| Template reference | Modality | Reviewed inputs |
| --- | --- | --- |
| `api:higgsfield-ai/soul/standard` | Text to image | Prompt, image count, resolution, aspect ratio |
| `api:higgsfield-ai/dop/standard` | Image plus text to video | Prompt, source image, optional end frame, seed, prompt enhancement |
| `api:kling-video/v2.1/pro/image-to-video` | Image plus text to video | Prompt, source image, duration, CFG scale, negative prompt |

`Model Access` may contain a JSON array of exact references from that table.
An empty value permits all three. Any other remote model or operation is not
executable through this adapter merely because Higgsfield publishes it.

Higgsfield describes model request schemas as provider-owned and subject to
change. The local Templates therefore use the reviewed Higgsfield OpenAPI
`2.0.0` document plus narrative guides as provenance, and the client forwards
only the small allowlisted field set above. Re-review code, fixtures, and this
document before expanding the operation list or adopting a changed schema.

## REST request lifecycle

The REST client authenticates with:

```http
Authorization: Key KEY_ID:KEY_SECRET
```

Testing performs a non-destructive status lookup for a guaranteed-invalid
request UUID. A `404` after authentication proves the credential without
submitting generation.

Generation is asynchronous. Submission returns a request ID, which Word Graph
Studio polls through `GET /requests/{request_id}/status`. Provider states map
as follows:

| Higgsfield state | World Graph Studio state |
| --- | --- |
| `queued`, `in_progress`, or another non-terminal state | `submitted` |
| `completed` | `completed`, after every supported output is imported |
| `failed`, `nsfw` | `failed` |
| `canceled` / `cancelled` | `cancelled` |

The provider documents `POST /requests/{request_id}/cancel`, but this adapter's
delivered generation boundary currently submits and polls; it does not claim a
provider-side cancellation action.

Higgsfield does not document an idempotency key for these submits. World Graph
Studio therefore does not automatically retry an ambiguous POST after a
timeout. Check the provider account before deliberately retrying, or duplicate
billable work may result.

Completed `images`, `video`, `audio`, and `audios` URLs are normalized as typed
outputs, de-duplicated, and imported into the WordPress Media Library before
the generation job completes. Higgsfield states that generated outputs remain
available for at least seven days; the WordPress import is still the durable
project copy and should not be postponed.

## Reference-media uploads

For the two image-to-video Templates, an input can be a validated public HTTPS
URL or a WordPress attachment that remains authorized for the queued job. A
local attachment uses Higgsfield's presigned flow:

1. request an upload URL from `POST /files/generate-upload-url`;
2. upload the bounded file to the returned URL without sending the Higgsfield
   API credential to the storage host; and
3. submit the returned public URL to the reviewed generation operation.

The adapter accepts JPG, PNG, WebP, GIF, WAV, and MP4 content types and limits
this path to 50 MB per attachment. Provider-returned upload headers and URLs
are validated before use.

## MCP boundary

Higgsfield's hosted MCP service uses account OAuth rather than the REST key
pair. World Graph Studio requests its catalog with current MCP per-request
metadata first and uses a bounded initialization-era fallback only when the
structured response explicitly requires it. Discovery validates JSON-RPC IDs,
SSE event framing, protocol/result metadata, pagination, schema sizes, and
tool identifiers.

The provider's public documentation does not publish stable tool names, input
schemas, or result schemas. Consequently:

- MCP readiness means authenticated `tools/list` returned a non-empty bounded
  catalog;
- discovered MCP tools are descriptive runtime data and do not become active
  Templates;
- the Higgsfield MCP client exposes no arbitrary `tools/call`; and
- all delivered media generation routes through the three reviewed REST
  operations.

This boundary prevents a newly advertised or changed remote tool from gaining
billable execution privileges without code review, an argument allowlist,
result fixtures, and media-import coverage.

## Data, accounts, and cost

REST generation sends the prompt, selected reviewed parameters, and any
supplied reference media or media URL to Higgsfield. Status checks send the
request ID, and WordPress downloads the returned output URLs. MCP connection
and health checks send OAuth scopes, MCP client/protocol metadata, and
`tools/list`; this adapter does not send generation prompts through MCP.

A Higgsfield developer API credential is required for REST. The hosted MCP
service uses a Higgsfield account and may require an eligible paid
subscription. API, subscription, storage, and model usage charges are set by
Higgsfield. Review its current terms, privacy practices, model-provider rules,
retention, and pricing before sending personal, confidential, voice, likeness,
or rights-restricted material.

## Troubleshooting

- **REST credential rejected:** confirm the saved or environment-managed value
  contains both parts in `KEY_ID:KEY_SECRET` form, with no `Key ` prefix.
- **OAuth button unavailable:** save the Connection as published and enabled,
  and confirm the WordPress admin URL is HTTPS (or a loopback development URL).
- **OAuth expired:** a locally stored envelope refreshes automatically when it
  has a usable refresh token. Reconnect when refresh is unavailable, fails, or
  the trusted profile binding changed. An `env://` credential must instead be
  rotated by its external secret manager.
- **MCP test fails with no tools:** reconnect the Higgsfield account and retry.
  Do not add guessed tool names to satisfy readiness.
- **No Templates appear:** clear a malformed `Model Access` value or set it to
  a JSON array containing one or more exact reviewed `api:` references.
- **A submit times out:** inspect the Higgsfield account for an existing
  request before retrying because submits have no documented idempotency key.

## Official references

- [Higgsfield API documentation](https://docs.higgsfield.ai/docs)
- [Official Higgsfield JavaScript SDK](https://github.com/higgsfield-ai/higgsfield-js)
- [Official Higgsfield Python client](https://github.com/higgsfield-ai/higgsfield-client)
- [Higgsfield MCP connection guide](https://higgsfield.ai/creator-hub/help-center/integrations/how-do-i-connect-higgsfield-to-ai-agent)
- [OAuth protected-resource metadata](https://mcp.higgsfield.ai/.well-known/oauth-protected-resource/mcp)
- [Higgsfield Terms of Use](https://higgsfield.ai/terms-of-use-agreement)
- [Higgsfield Privacy Policy](https://higgsfield.ai/privacy-policy)
