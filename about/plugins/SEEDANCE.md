# Seedance 2.5 via CyberBara Connection

**Status:** Delivered within the fixed CyberBara REST boundary described below.

World Graph Studio represents this integration as one `seedance_25` Connection
to CyberBara, a third-party API intermediary:

| Surface | Endpoint | Credential | World Graph Studio use |
| --- | --- | --- | --- |
| CyberBara REST API | `https://cyberbara.com` | CyberBara API key in `credential_reference`, preferably through `env://CYBERBARA_API_KEY` | Checks the reviewed model and scenes, provisions two fixed video Templates, uploads an authorized reference image when needed, submits and polls tasks, and imports final videos |

This is not a direct ByteDance, BytePlus, Volcengine, or Dreamina connection.
Credentials or subscriptions issued by those services, or by a consumer
Seedance interface, do not authenticate CyberBara. Provider ownership,
availability, pricing, moderation, and data handling remain CyberBara operating
conditions.

## Provider identity and the official API boundary

The upstream repository named in this integration request calls itself an
official API document, but its executable client is configured for
`https://cyberbara.com` and uses CyberBara routes and credentials. World Graph
Studio therefore identifies the Connection by the service it actually calls
instead of presenting it as a ByteDance-operated API.

These access paths are different and their credentials and request contracts
are not interchangeable:

| Access path | Current documented model path | Request contract | World Graph Studio status |
| --- | --- | --- | --- |
| ByteDance Seed / BytePlus ModelArk | `dreamina-seedance-2-5-260628` | BytePlus's direct contract uses `/api/v1/contents/generations/tasks`, a multimodal `content` array, `lsd-...` task IDs, and its own task states | Official access path; no direct adapter yet |
| CyberBara | `seedance-2.5` | `/api/v1/models`, `/api/v1/uploads/images`, `/api/v1/videos/generations`, and `/api/v1/tasks/{taskId}` | This delivered `seedance_25` adapter |
| SeedanceAPI.org | `seedance-2.0`, `seedance-2.0-fast`, and `seedance-2-mini` on its current v2 documentation | `/v2/generate` and `/v2/status?task_id=...`; its documentation describes an edge-router and relay layer | Separate intermediary; not supported by this Connection |

SeedanceAPI.org's page titled “Seedance 2.5 API” describes a planning path and
directs callers to its current Seedance 2.0 v2 models. It is not evidence that
a SeedanceAPI.org key can call the CyberBara `seedance-2.5` contract. Neither
intermediary credential can be used for the direct BytePlus contract. Adding
that official transport should use a separate provider slug and contract
review, not silently replace this Connection's fixed endpoint.

## Configure the Connection

Seedance 2.5 via CyberBara is configured manually under **World Graph Studio >
Connections**; it is not a first-run Setup Wizard choice.

1. Create and publish a Connection with Provider Type **Seedance 2.5 via
   CyberBara** and Environment **Production**.
2. Keep Endpoint URL at exactly `https://cyberbara.com`. The adapter rejects
   another origin, path, query, fragment, user information, non-HTTPS scheme,
   or nonstandard port.
3. In **API Key / OAuth Reference**, enter a CyberBara API key. For production,
   prefer `env://CYBERBARA_API_KEY` and set that environment variable in the
   WordPress runtime.
4. Leave the Connection enabled, save it, and run **Check connection**.

The check sends a non-generating `GET /api/v1/models?media_type=video` request.
Readiness requires the exact `seedance-2.5` model and the reviewed
`text-to-video` and `image-to-video` scenes, then idempotently provisions the
selected Templates. A reachable endpoint or valid key by itself is not enough.

## Reviewed Templates

World Graph Studio provisions only these fixed operation references:

| Template reference | Modality | Required input | Reviewed controls and defaults |
| --- | --- | --- | --- |
| `seedance-2.5:text-to-video` | `text_to_video` | Prompt | Duration `4`–`30` seconds (default `10`), resolution `480p` or `720p` (default `720p`), aspect ratio `21:9`, `16:9`, `4:3`, `1:1`, `3:4`, or `9:16` (default `16:9`) |
| `seedance-2.5:image-to-video` | `text_image_to_video` | Prompt plus one `image_input` bound to the World Graph Studio `image` slot | The same duration, resolution, and aspect-ratio controls and defaults |

The prompt is required, stripped of markup, and limited locally to 10,000
bytes. The client derives `model` and `scene` from the exact Template reference;
a browser request cannot replace them. Only `duration`, `resolution`, and
`aspect_ratio` from the reviewed controls are forwarded.

`Model Access` may contain a JSON array of exact references from the table. An
empty value permits both. For example, this limits the Connection to
text-to-video:

```json
["seedance-2.5:text-to-video"]
```

CyberBara may advertise other models or scenes, including video-to-video. They
are not executable through this adapter merely because they appear remotely.
World Graph Studio has not reviewed a matching source-video upload and input
contract for this Connection.

## REST request lifecycle

Although CyberBara documents both Bearer and `x-api-key` authentication, World
Graph Studio deliberately sends one credential form only:

```http
Authorization: Bearer <CYBERBARA_API_KEY>
```

The reviewed request sequence is:

1. optionally upload one authorized local reference image with
   `POST /api/v1/uploads/images`;
2. submit the fixed operation with `POST /api/v1/videos/generations`; and
3. poll `GET /api/v1/tasks/{taskId}` until a terminal state is observed.

The adapter does not use CyberBara's credits endpoints, video-upload route,
other models, or other generation scenes. It does not send a callback URL.

Provider task states are normalized as follows:

| CyberBara state | World Graph Studio state |
| --- | --- |
| `pending` or `processing` | `submitted` |
| `success` | `completed`, after supported outputs are imported |
| `failed` | `failed` |
| `canceled` | `cancelled` |

The entire `output.videos` list must satisfy the bounded HTTPS URL contract.
Every distinct URL is then normalized as a video and imported into the
WordPress Media Library before the generation job completes. An unknown state,
an invalid output entry, or a completed provider task without a supported video
output fails closed. The CyberBara credential is not forwarded to
provider-returned media hosts.

CyberBara does not document a submission idempotency key, callback contract,
or provider-side cancellation operation for this reviewed path. World Graph
Studio therefore does not automatically retry an ambiguous paid submission.
If a submit times out, inspect the CyberBara account before deliberately
retrying to avoid duplicate billable work. WordPress cancellation can stop
local queued work, but it does not claim to revoke a task already accepted by
CyberBara.

## Reference-image boundary

The image-to-video input can be a validated public HTTPS URL or a WordPress
attachment that remains authorized for the queued generation job. A local
attachment is reauthorized at execution time before WordPress reads or uploads
it. The adapter accepts one non-empty JPEG, PNG, WebP, GIF, or AVIF image no
larger than 10 MB.

The upload uses the CyberBara `files` multipart field. The returned image URL
must pass the HTTPS and WordPress URL-safety checks before it is placed in the
provider `options.image_input` array. Local paths and attachment identifiers
are not sent as generation inputs.

## Security and failure boundaries

- The API origin and routes are fixed; redirects are disabled and WordPress's
  SSRF-safe HTTP client is used.
- Request timeouts, upload size, response size, model-catalog size, task-ID
  shape, output count, prompt size, options, and error messages are bounded.
- Model, scene, upload field, and output type are code-reviewed allowlists, not
  values discovered and executed from the remote catalog.
- Provider errors are mapped to stable local errors without retaining raw
  response bodies. Credentials must not appear in job results, logs, fixtures,
  or user-facing errors.
- A literal credential is stored with the Connection's protected credential
  field. Prefer an `env://` reference, protect database backups, and rotate a
  compromised key in CyberBara or the external secret manager.

## Data, consent, moderation, and cost

Generation sends the prompt, reviewed options, and any supplied reference
image or validated image URL to CyberBara. Status checks send the task ID, and
WordPress downloads the returned video URLs. CyberBara may route work to
additional model infrastructure; review its current API documentation, terms,
privacy practices, region availability, and retention rules before sending
personal, confidential, likeness-bearing, or rights-restricted material.

The upstream repository markets access for real-person workflows and describes
its review behavior as more permissive than some other Seedance surfaces.
Those are provider claims, not a World Graph Studio guarantee or permission to
use a person's face, voice, identity, copyrighted work, or confidential data.
Operators must obtain appropriate consent and rights, follow CyberBara's
current moderation rules, and comply with applicable law. Do not attempt to
bypass safety controls.

CyberBara controls account eligibility, credits, pricing, quotas, moderation,
and model availability. The reviewed sources do not establish a durable output
retention period. Import successful outputs promptly and treat the WordPress
Media Library copy as the project copy.

## Troubleshooting

- **Credential rejected:** confirm the value is a CyberBara API key with no
  `Bearer ` prefix. A key from another Seedance access path is not equivalent.
- **Environment reference fails:** use the exact
  `env://CYBERBARA_API_KEY` form and expose that uppercase environment variable
  to the PHP/WordPress runtime.
- **Endpoint rejected:** use exactly `https://cyberbara.com`; do not append
  `/api`, `/api/v1`, a query, or a port.
- **Model check fails:** confirm the CyberBara account currently exposes
  `seedance-2.5` with both reviewed scenes. Remote availability is deployment
  state, not a reason to widen the local allowlist.
- **No Templates appear:** leave `Model Access` blank or use a JSON array with
  one or both exact reviewed references, then save or check the Connection.
- **Reference image rejected:** use a supported image up to 10 MB and confirm
  the queued requester still has access to the WordPress attachment.
- **A submit times out:** inspect the CyberBara task/account history before
  retrying because no submission idempotency key is documented.
- **A task succeeds without an Asset:** inspect the Generation Log. The adapter
  rejects missing, non-HTTPS, unsafe, or unsupported output URLs rather than
  marking the job complete without imported video.

## Reviewed references

This adapter was reviewed on August 30, 2026 against upstream `main` commit
[`6e5afd7b42e9d330c17449655c2a9b44a16ad0f3`](https://github.com/ZeroLu/seedance2.5-API/tree/6e5afd7b42e9d330c17449655c2a9b44a16ad0f3).
The external API and model catalog can change independently; re-review the
client, fixtures, Templates, and this guide before widening the contract.

- [Seedance 2.5 API repository at the reviewed revision](https://github.com/ZeroLu/seedance2.5-API/blob/6e5afd7b42e9d330c17449655c2a9b44a16ad0f3/README.md)
- [CyberBara API reference](https://cyberbara.com/docs/api-reference)
- [CyberBara Seedance moderation guidance](https://cyberbara.com/docs/seedance-moderation)
- [ByteDance Seed announcement for Seedance 2.5](https://seed.bytedance.com/en/blog/one-take-creation-flexible-referencing-introducing-seedance-2-5)
- [BytePlus direct Seedance 2.5 video-generation API](https://docs.byteplus.com/en/docs/byteplus_las/video_gen_enhanced)
- [SeedanceAPI.org documentation hub](https://seedanceapi.org/docs)
- [SeedanceAPI.org current v2 contract](https://seedanceapi.org/docs/v2)
- [SeedanceAPI.org Seedance 2.5 planning page](https://seedanceapi.org/seedance-2-5)
