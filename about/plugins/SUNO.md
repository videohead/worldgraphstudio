# Suno Integration

## Scope and provider boundary

World Graph Studio can generate songs and lyrics through two related but
independent third-party services:

- [SunoAPI.org](https://docs.sunoapi.org/) provides the REST API at
  `https://api.sunoapi.org`.
- [AceData Cloud](https://docs.acedata.cloud/en/mcp/suno) provides the hosted
  Streamable HTTP MCP server at `https://suno.mcp.acedata.cloud/mcp`.

These services are not Suno web subscriptions and do not share credentials.
A SunoAPI.org bearer key cannot authenticate AceData Cloud MCP, and an AceData
Cloud token cannot authenticate SunoAPI.org. Provider pricing, credits,
moderation, retention, availability, and terms remain external deployment
conditions.

World Graph Studio represents both transports with one `suno` Connection so an
operator can manage the provider choice and its paired Templates in one place.
The Connection nevertheless keeps the two endpoints, bearer tokens, request
schemas, model identifiers, and status formats separate.

## Connection configuration

The Setup Wizard choice is **Suno API + MCP**. A manually created Connection
uses the same fields:

| Connection field | Value or purpose |
| --- | --- |
| Provider Type | `suno` |
| Environment | `production` |
| Endpoint URL | `https://api.sunoapi.org` |
| MCP Endpoint URL | `https://suno.mcp.acedata.cloud/mcp` |
| API Key / OAuth Reference | SunoAPI.org key, preferably `env://SUNO_API_KEY` |
| MCP API Key / OAuth Reference | AceData Cloud token, preferably `env://ACEDATACLOUD_API_TOKEN` |
| Model | Optional preferred REST model name, for example `V5_5` |
| Model Access | Optional JSON allowlist of permitted music models |

`credential_reference` belongs to the REST endpoint.
`mcp_credential_reference` belongs to the MCP endpoint. Never copy one value
into both fields merely to make the form complete. Both credentials are needed
for the combined Connection test and the complete transport-specific Template
set.

Literal tokens can be entered in the wizard, but environment references are
preferred for production. Map the referenced variables into the PHP/WordPress
runtime without committing them to this repository. Protect database backups
when literal credentials are stored through the wizard.

AceData Cloud publishes OAuth metadata for the hosted MCP endpoint, but the
current WordPress adapter does not initiate an authorization-code, dynamic
registration, or refresh-token flow. Configure a bearer token or `env://`
reference. The adapter uses Streamable HTTP; the server's legacy SSE routes are
not part of this Connection contract.

Testing the saved Connection performs both checks:

1. `GET /api/v1/generate/credit` verifies the SunoAPI.org bearer key.
2. MCP initialization and `tools/list` verify the AceData Cloud token and the
   required tools: `suno_generate_music`, `suno_generate_custom_music`,
   `suno_generate_lyrics`, and `suno_get_task`.

The Connection becomes `verified` only when both transports pass. A failed
test identifies the failing provider rather than trying the other provider's
credential.

Catalog provisioning is transport-aware: an explicitly configured REST
credential can provision the three REST Templates, and an explicitly
configured MCP credential can provision the three MCP Templates. Configure
both for the supported combined setup and six-Template catalog; a partial
Connection cannot pass the combined Connection test.

## Managed Templates

Saving or testing a Suno Connection provisions separate Templates for each
transport. Keeping REST and MCP in different Templates prevents one provider's
field names, model IDs, callback behavior, or result shape from leaking into
the other provider request.

| Operation | SunoAPI.org REST | AceData Cloud MCP | Output |
| --- | --- | --- | --- |
| Prompt music | `POST /api/v1/generate` with `customMode: false` | `suno_generate_music` | Audio |
| Custom music | `POST /api/v1/generate` with `customMode: true` | `suno_generate_custom_music` | Audio |
| Lyrics | `POST /api/v1/lyrics` | `suno_generate_lyrics` | Text |

The stable Template transport references are:

```text
api:generate
api:generate-custom
api:lyrics
mcp:suno_generate_music
mcp:suno_generate_custom_music
mcp:suno_generate_lyrics
```

The `api:` or `mcp:` prefix selects the adapter for submission and later
polling. Do not change a managed Template from one prefix to the other; select
the paired Template instead.

Prompt music accepts an idea and lets the provider construct the song. Custom
music treats the supplied lyric text, title, and style as deliberate inputs;
an instrumental custom request does not require lyric text. Lyrics Templates
generate structured lyric variants without importing audio.

The common Template configuration may include `instrumental`, `title`,
`style`, negative tags, vocal gender, and the provider's supported variation
controls. `duration` is valid only for a custom `V5_5` REST request and must be
between 10 and 360 seconds. The adapter validates required prompt, title, style,
and instrumental combinations before submission; provider-specific bounds
still apply to optional controls.

The Connection's `Model` is the preferred music model unless a Template or
request explicitly overrides it. REST model names are not sent verbatim to
MCP; World Graph Studio maps them inside the MCP adapter:

| Connection/REST model | MCP model |
| --- | --- |
| `V4` | `chirp-v4` |
| `V4_5` | `chirp-v4-5` |
| `V4_5PLUS` | `chirp-v4-5-plus` |
| `V4_5ALL` | `chirp-v4-5-plus` |
| `V5` | `chirp-v5` |
| `V5_5` | `chirp-v5-5` |

Do not place an MCP model identifier in the Connection's REST-oriented `Model`
field. `Model Access` is enforced at music-submission time and may contain REST
or MCP aliases; the adapters normalize both forms before comparing them. An
empty allowlist permits all adapter-supported music models, while malformed
JSON denies the request. It does not change which operation Templates are
provisioned. Lyrics generation has a separate MCP model choice: `default` or
`remi-v1`.

Suno music audio Templates can appear in the Project **Demonstration** selector
when the frozen story plan contains a generated Sound task whose modality they
can satisfy. They do not appear as direct item outputs. Lyrics Templates return
text rather than audio and therefore do not satisfy a demonstration Sound task.

## Asynchronous lifecycle

Both transports use the normal `worldgraph_gen` lifecycle:

```text
queued -> submitted -> completed
                    \-> failed
```

WP-Cron submits bounded batches and revisits submitted jobs. A production host
must invoke `wp-cron.php` reliably; saving a Connection or Template does not
create a separate queue service.

### SunoAPI.org REST

Music submission returns a `taskId`. World Graph Studio polls
`GET /api/v1/generate/record-info?taskId=...`; lyrics generation polls
`GET /api/v1/lyrics/record-info?taskId=...`. Intermediate music states such as
`PENDING`, `TEXT_SUCCESS`, and `FIRST_SUCCESS` remain `submitted`.
`SUCCESS` advances to result import. Documented terminal error states become a
failed generation with a sanitized provider message.

SunoAPI.org also requires a public `callBackUrl`. World Graph Studio supplies:

```text
/wp-json/worldgraph/v1/generation/suno-callback?connection_id=...&token=...
```

The token is a stable HMAC of the Connection ID derived from the site's
WordPress authentication salt. It is scoped to the Connection, not to an
individual generation. A valid callback returns `200 OK` and schedules
the canonical poller; its body is not trusted to update or complete a job.
Polling therefore remains the result and reconciliation path when a callback
is late, duplicated, forged, or unavailable.

SunoAPI.org does not document a signed webhook header. The callback token is
therefore an access-control measure, not proof that SunoAPI.org
cryptographically signed the payload. Never log the full callback URL or place
a Connection bearer token in it. Changing the WordPress authentication salts
invalidates existing callback URLs. Keep the route reachable from the public
internet and return promptly; the provider documents a 15-second callback
timeout and may retry a failed delivery.

### AceData Cloud MCP

The MCP adapter uses Streamable HTTP JSON-RPC with the AceData bearer token. It
initializes the session, discovers tools, calls the selected generation tool,
and retains the returned `task_id`. When no provider callback is requested, the
server returns an asynchronous submission and World Graph Studio polls
`suno_get_task`.

`pending` and `processing` remain submitted. A result is terminally successful
when the task response reports `success: true`, even if no separate `state` is
present. `failed`, `error`, `cancelled`, or `success: false` are terminal
failures. Preview audio URLs on a still-pending task are not treated as final
results.

The MCP tool list is discovered at runtime. AceData Cloud's public guide and
server implementation can expose different catalog sizes, so World Graph
Studio depends only on the required tools above and does not assume that every
optional Suno MCP tool is available.

## Result import

A music request normally returns two generated tracks. World Graph Studio
normalizes both REST and MCP response shapes, then downloads every final track
URL into the WordPress media library. Each successful download becomes a
separate attachment, and all attachment IDs remain associated with the
generation record. Provider success alone is not enough: the generation is
marked `completed` only after every returned final track has been imported.

Do not defer the import. SunoAPI.org documents generated-file retention of 15
days, and either provider may return temporary delivery URLs. A failed or
invalid download leaves the generation failed so an operator can diagnose the
missing asset rather than recording a false success.

Provider responses are inconsistent about field casing. The REST status API
has documented both `response.sunoData` with camel-case track fields and
`response.data` with snake-case fields; callbacks and MCP use additional
snake-case variants. The adapter normalizes these shapes before the generation
worker sees them.

## Limits and failure behavior

- SunoAPI.org documents two songs per music request and a generation limit of
  20 requests per 10 seconds.
- SunoAPI.org documents application codes separately from HTTP status. Notably,
  `429` can mean insufficient credits and `430` can mean excessive request
  frequency.
- A failed submission becomes a failed generation rather than being submitted
  again automatically. A transient polling error is logged and the submitted
  job is checked again on a later worker pass.
- AceData Cloud documents `401 invalid_token`, `429 too_many_requests`, and
  `500 api_error`, but does not publish a numeric MCP request limit.
- Provider-generated media, lyrics, styles, URLs, and error text are untrusted
  input. Credentials and authorization headers must never enter generation
  metadata or logs.

The integration currently covers prompt music, custom music, and lyric
generation. Extend, cover, upload, stems, personas, WAV, MIDI, MP4, and other
operations exposed by either provider are not provisioned as executable World
Graph Studio Templates in this integration.

## Operator checklist

1. Obtain a SunoAPI.org API key and a separate AceData Cloud API token.
2. Open the Setup Wizard and select **Suno API + MCP**, or create one `suno`
   Connection under **World Graph Studio > Connections**.
3. Configure both endpoints and their matching credential references.
4. Save, then test the Connection. Confirm both the REST credit check and MCP
   required-tool check pass.
5. Run due WP-Cron events if the Template sync has not completed.
6. Confirm the six transport-specific music/custom-music/lyrics Templates are
   active and bound to the Connection.
7. Queue a low-cost test, then verify that every returned audio track appears
   as a WordPress media attachment before the generation reaches `completed`.

For failures:

- a REST `401` usually means the SunoAPI.org key is missing or was placed in
  the MCP field;
- an MCP `401` usually means the AceData Cloud token is missing or was placed
  in the REST field;
- a job stuck at `queued` usually indicates WP-Cron is not running;
- a job stuck at `submitted` should be reconciled by polling even if the
  callback did not arrive; and
- provider success followed by a World Graph Studio failure usually indicates
  that one or more final media URLs could not be downloaded or validated.

## References

- [SunoAPI.org API documentation](https://docs.sunoapi.org/)
- [SunoAPI.org OpenAPI document](https://docs.sunoapi.org/suno-api/suno-api.json)
- [SunoAPI.org music generation](https://docs.sunoapi.org/suno-api/generate-music)
- [SunoAPI.org music callbacks](https://docs.sunoapi.org/suno-api/generate-music-callbacks)
- [AceData Cloud Suno MCP guide](https://docs.acedata.cloud/en/mcp/suno)
- [AceData Cloud Suno MCP server source](https://github.com/AceDataCloud/SunoMCP)
- [AceData Cloud MCP protected-resource metadata](https://suno.mcp.acedata.cloud/.well-known/oauth-protected-resource)
- [World Graph Studio Suno REST adapter](../../wordpress/wp-content/plugins/worldgraph/includes/utils/suno-api.php)
- [World Graph Studio Suno MCP adapter](../../wordpress/wp-content/plugins/worldgraph/includes/utils/suno-mcp.php)
- [World Graph Studio Suno Template catalog](../../wordpress/wp-content/plugins/worldgraph/includes/utils/suno-catalog.php)
- [World Graph Studio generation callback controller](../../wordpress/wp-content/plugins/worldgraph/includes/rest-api/generation-controller.php)
- [Generation Engine](GENERATION_ENGINE.md)
- [Deployment and Connections](../Deployment_and_Connections.md)
- [Plugin Setup Guide](../../wordpress/wp-content/plugins/worldgraph/documentation/SETUP_GUIDE.md)
