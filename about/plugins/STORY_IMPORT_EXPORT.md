# Story Import & Export

**Status: delivered, bundled, and enabled by default.** The feature plugin at
`wordpress/wp-content/plugins/worldgraph/plugins/story-import-export/` owns
canonical World Graph Studio JSON import/export, Markdown screenplay and
storyboard export, and LLM-assisted decomposition of supported story documents.

The feature is loaded by the main World Graph Studio plugin and can be disabled
from **World Graph Studio > Plugins**. Its saved option is
`worldgraph_story_io_enabled`, whose default is `true`. Disabling it removes the
feature's admin and REST surfaces; it does not delete imported Story Graph data
or retained source attachments.

> **Dependency boundary:** Story Import & Export, including its built-in
> chapter-aware decomposer and lexical evidence retrieval, does **not** require
> WPVDB. The separate, disabled-by-default **Story RAG Decomposer** enhancement
> does require WPVDB. Before enabling that enhancement, install and activate
> WPVDB as a separate top-level WordPress plugin at `wp-content/plugins/wpvdb`
> in the target WordPress installation, alongside rather than inside
> `worldgraph/`, and configure an active embedding provider and model in
> WPVDB. The nested `worldgraph/plugins/story-rag-decomposer/`
> directory does not include WPVDB. That embedding setup is additional to the
> LLM Connection required to decompose a non-canonical story document.

## Delivered capability

| Direction | Behavior | LLM required |
| --- | --- | --- |
| Canonical JSON → Story Graph | Dry-run validation, external-ID upsert, SCF/taxonomy population, relationship construction, Sequence ordering, verification, and import report | No |
| Story document → canonical JSON preview → Story Graph | Persist source, build a structural reading plan, run evidence and graph-assembly passes through the configured Connection, normalize and validate version 1.2, then wait for explicit confirmation | Yes, except when the upload is already canonical JSON |
| Story Graph → canonical JSON | Deterministic version 1.2 projection of one live Project and its supported related graph | No |
| Story Graph → Markdown | Screenplay and storyboard projections from live Project, Scene, and Shot data | No |

The canonical JSON importer and exporters were moved into this feature plugin;
they are no longer owned by the main plugin's `includes/importer/` or
`includes/exporter/` directories. Compatibility PHP class names and the
existing Import and Export admin identifiers remain available so bundled
connectors can continue delegating to the same contract.

## Enablement and administration

The plugin adds these administrator screens when enabled:

- **World Graph Studio > Import** — upload, preview, confirm, or cancel an
  import.
- **World Graph Studio > Export** — select a readable Project and download
  canonical JSON, a Markdown screenplay, or a Markdown storyboard.

The historical admin page slugs and form identifiers remain compatible:

| Surface | Identifier |
| --- | --- |
| Import page | `worldgraph-import` |
| Import action and nonce | `worldgraph_import_json` / `worldgraph_import` |
| Export page | `worldgraph-export` |
| Export action and nonce | `worldgraph_export_markdown` / `worldgraph_export_markdown` |

All admin operations require `manage_options`. Export also validates that the
selected object is a `worldgraph_project` the current user may read.

## Supported source files

The Import screen persists the source through WordPress's upload handling, then
extracts normalized UTF-8 text without invoking external conversion binaries.

| File type | Extensions | Extraction boundary |
| --- | --- | --- |
| Canonical JSON | `.json` | Recognized by the required top-level contract sections and validated directly; no LLM call |
| Plain text | `.txt`, `.text` | Normalized text |
| Markdown | `.md`, `.markdown` | Treated as text; markup is not rendered |
| Fountain | `.fountain` | Treated as text for LLM decomposition; this is independent of the separate deterministic Fountain-to-FDX scaffold |
| Rich Text Format | `.rtf` | Text and common RTF escapes are extracted; layout and embedded media are not imported |
| PDF | `.pdf` | Text-layer extraction only; encrypted PDFs are rejected and scans/image-only files return an OCR-required error |
| EPUB | `.epub` | Visible body text is read in package spine order; document headings and horizontal-rule scene breaks are retained as structural metadata while document-head and known non-narrative wrapper content is excluded |
| Word document | `.docx` | Visible paragraph text is read from the document XML |
| OpenDocument text | `.odt` | Visible paragraph text is read from the content XML |

Current bounds are 20 MB per source and at least 20 usable extracted
characters. Canonical JSON follows the 20 MB upload boundary; non-canonical
sources are limited to 500,000 extracted characters. The planner uses advertised
model-context metadata when available and reserves capacity for server
instructions, the data envelope, compact graph state, and model output. It
refuses an advertised context window below 2,048 tokens and uses a conservative
target when metadata cannot be discovered. Every manuscript, including a short
one, goes through bounded evidence windows instead of an unbounded
whole-document prompt. The requested output allowance remains capped at 4,096
tokens and by the Connection's lower configured `max_tokens`.

With usable context metadata, the target span grows only as far as 12,000
characters and its hard maximum only as far as 16,000. Without metadata, the
conservative profile targets 1,100 characters with a 1,400-character hard
maximum, 128 characters of context on either side, and a 768-token output
allowance. The planning engine and resumable job allow at most 1,000 primary
spans, including targeted evidence-pass subdivision. The public synchronous
REST compatibility route accepts non-canonical input only when the extracted
text is at most 1,400 UTF-8 characters and the initial plan is exactly one span.
Each evidence or synthesis call can make an initial request plus at most two
compact JSON repair requests. In the ordinary successful case this means two
model calls per span. `attempts` reports actual model calls and `chunks` reports
final primary spans after any subdivision.

### Structural reading plan

PHP is the authority for source preparation and chunk identity. It preserves
the prepared UTF-8 story exactly once and plans context-window-sized spans with
this boundary preference: chapter, section, Scene, paragraph, sentence,
whitespace/word, then a necessary hard character cut. EPUB extraction emits
spine, heading, and horizontal-rule markers in reading order. Each trustworthy
extractor marker carries a UTF-8 offset, type, label, source entry and heading
level when available, plus bounded before/after anchors and an anchor hash so
the planner can remap it safely after conservative front/back-matter trimming.

The planner does not cut through a UTF-8 character and prefers a whitespace
boundary over splitting a word. A hard character cut is used only when no such
boundary exists before the safe maximum, such as one exceptionally long token.
Every story character belongs to one primary span, in source order, with stable
hashes and boundary metadata available for checkpoint validation. Neighboring
text is supplied separately as bounded `source_context`; it is not duplicated
into the span's `story_text` and must not be emitted as a second Scene. The
standard context allowance is at most 1,000 characters on either side. If a
provider rejects or truncates one span, the server can subdivide that span at
the same natural boundaries without restarting completed work. A hard part
limit still fails explicitly rather than silently dropping the remainder of a
manuscript.

The Import screen's JavaScript does not decide narrative boundaries. It advances
one server-owned job step at a time, displays the PHP plan's safe section and
progress metadata, and can resume the same checkpoint after an interrupted
browser request. This keeps source extraction, chunk hashes, model inputs, and
the evolving graph under the server's validation boundary.

PDF handling does not run OCR. If the importer reports that no usable text layer
exists, run OCR outside World Graph Studio and upload the searchable PDF or a
text/EPUB version. A successful extraction is best effort and does not preserve
page geometry, typography, images, comments, or application-specific layout.

## Preview and confirmation workflow

### Canonical JSON

1. WordPress saves the uploaded file as a Media Library attachment.
2. The plugin recognizes the canonical sections and runs the importer in
   `dry_run` mode without contacting an LLM.
3. The administrator reviews the Project summary, entity counts, source
   details, and canonical JSON candidate.
4. Confirmation revalidates the same candidate and commits it with the
   overwrite choice captured at preview time.

### Other story documents

1. WordPress persists the source, extracts normalized text plus trustworthy
   structural markers, and chooses the configured default published LLM
   Connection that the administrator may manage. A synchronous REST
   compatibility client may identify a different eligible Connection
   explicitly.
2. PHP prepares the structural reading plan and creates a time-limited,
   user-scoped decomposition job. Its attachment, Connection ID, and complete
   prepared-source hash are immutable; ordered chunk hashes are checked before
   every pass and can change only through an exact-coverage subdivision.
3. In the evidence pass, each primary span is re-read for grounded entity,
   chronology, setting, and possible Scene observations. In the synthesis pass,
   the same span and its evidence are reconciled against a bounded summary of
   the graph assembled so far. This allows later spans to reuse established IDs
   and to continue a Scene across a retrieval boundary without making every
   chunk a Scene.
4. The decomposer accepts only graph-shaped JSON, removes unsupported generated
   catalog names that do not occur in the manuscript, preserves typed
   references when the same evidenced name legitimately appears in more than
   one catalog, normalizes required sections, identifiers, references, and
   ordering, and asks the authoritative importer to dry-run validate the final
   document.
5. An invalid or output-truncated response in either pass may receive bounded
   JSON repair attempts. Multiple recognizable top-level story documents are
   treated as ambiguous and repaired rather than trusted according to their
   position. A persistently invalid evidence span may also receive adaptive
   structural subdivision. Completed job steps remain checkpointed; a final
   candidate that still fails validation is not offered for import.
6. The administrator reviews the resulting canonical document. The plugin does
   not create or update Story Graph records until **I reviewed this candidate
   and want to import it.** and **Confirm and Import Project** are submitted.

For each model call, the server-owned system message contains the pass-specific
contract. The user message is one JSON data envelope: `story_text` is explicitly
tagged as an untrusted story document, `source_context` is read-only neighboring
manuscript data, and the request carries no interactive chat history. Text that
resembles a prompt, role, XML tag, or JSON delimiter inside the manuscript
therefore remains quoted source data, not an instruction. The two prompts ask
the model to reason privately and return structured JSON only. Compatible model
reasoning controls may be enabled only by server-owned options. The pipeline
requests medium effort for evidence and high effort for synthesis; the shared
LLM client translates that intent only for recognized provider/model controls,
such as Qwen 3 thinking mode or allowlisted OpenAI reasoning effort. There is no
browser or REST override. Chain-of-thought is neither requested as output nor
returned in job status, saved in a checkpoint, or included in the import
preview.

### Evidence retrieval extension

After the complete evidence pass, the built-in private retriever supplies each
synthesis request with its current observation and up to three observations
from other spans, ranked by lexical overlap and source proximity. This is a
bounded continuity aid, not a second source of story facts. The
`worldgraph_story_decomposition_evidence_ready` action allows an extension to
index accepted structured evidence, and the
`worldgraph_story_decomposition_retrieval_context` filter can replace the
lexical bundle with private vector or other retrieval. Extensions must return
bounded structured evidence and must not log or expose manuscript text or
vectors. The synthesis envelope bounds the retrieved bundle again before it is
sent to the LLM.

#### Optional private WPVDB bridge

The bundled nested plugin at `plugins/story-rag-decomposer/` implements the two
retrieval hooks with [WordPressVectorDB (WPVDB)](https://github.com/Automattic/wpvdb),
but is disabled by default. Its option is `worldgraph_story_rag_enabled`.
**WPVDB is a requirement for this RAG enhancement:** it must be installed and
activated separately as the top-level `wp-content/plugins/wpvdb` plugin, and it
must have a valid active embedding provider and model. Installing or enabling
World Graph Studio does not install WPVDB. If that dependency or configuration
is missing, the bridge stays inactive and the base Story Import & Export
decomposer continues with its built-in lexical retrieval. The World Graph
Studio Plugins page displays this requirement and keeps the enhancement's
Enable control unavailable until WPVDB reports a valid configuration.

The bridge calls WPVDB's public embedding method with WPVDB's active provider
and model, so WPVDB remains authoritative for provider selection and its own
embedding extension filter. The bridge neither reads provider credentials nor
calls WPVDB's database or REST storage APIs.

For an accepted evidence object selected for the bounded index, the bridge
sends at most 12,000 characters of structured evidence to WPVDB's active
embedding provider. A synthesis lookup sends at most 6,000 characters of
transient query text, then cosine-ranks at most three evidence neighbors from
the same WordPress user and decomposition-run scope; the prepared-source hash
is also validated. The embedding provider therefore receives those bounded
inputs; operators must apply that provider's privacy and retention terms.

The bridge never writes the uploaded manuscript, evidence text, query text, or
vectors to WPVDB's shared/indexable embeddings table. WPVDB Core does briefly
use its shared `wpvdb` object-cache group while servicing each embedding call.
The bridge prefixes that call with an installation-local HMAC scope for the
current user and decomposition run, deletes the exact scoped cache key before
the call, and makes a best-effort deletion again when the call ends. Operators
should still apply their object-cache retention and access policy to that brief
concurrent-use window.

The bridge retains at most 128 uniformly sampled numeric evidence vectors from
a corpus of up to 1,000 spans, plus opaque run, chunk, user, provider, model,
and dimension identifiers in private user-and-run-scoped WordPress transients.
Its transient TTL is capped by the owning run's absolute deadline and can never
extend that deadline. A resumable run begins with a fixed 806,760-second
deadline (9 days, 8 hours, and 6 minutes); a synchronous run receives its own
bounded scope and deadline. For resumable jobs, completion, terminal failure,
and cancellation request per-run cleanup only after the terminal core-job
checkpoint has committed; cleanup is best effort and expiration is the
backstop for an abandoned run or failed deletion. A synchronous run has its own
scope and requests cleanup when it succeeds or fails. Separate runs by the
same user over the same source do not share the RAG transient scope. Missing
vectors, provider errors, non-finite or oversized vectors,
dimension/model/run mismatches, and every other bridge failure leave the
built-in lexical result unchanged instead of failing story decomposition. Job
status may report the sanitized retrieval backend `wpvdb-private-vector` and
aggregate counts, never vectors or inputs.

The completed import preview is held in an immutable, user-scoped transient for
30 minutes.
The confirmation token cannot be used by another user, and the overwrite choice
cannot be changed between preview and commit. Confirmation revalidates before
writing. Cancel discards the pending preview but not the uploaded source.

Non-canonical decomposition in wp-admin is resumable. JavaScript requests one
bounded analysis, synthesis, repair, or finalization step, then renders the
latest safe status before asking for the next step. A provider, PHP, web-server,
or reverse-proxy timeout can fail one request without confirming or committing
an import; submitting the next authorized step resumes from the last verified
checkpoint. Temporary provider/transport failures leave that checkpoint ready
for resume for up to three failed retries; a fourth failure at the same
checkpoint stops the job. Explicit cancellation stops further model work and
discards the job's private working text and intermediates, but retains its safe
terminal status and the uploaded source. The existing synchronous decomposition
REST operation remains available for compatibility clients only when a
non-canonical source is no more than 1,400 UTF-8 characters and its initial
plan is exactly one span; canonical JSON remains synchronous.

LLM decomposition is a structured first draft, not a claim of perfect literary
interpretation. The administrator is responsible for checking titles,
characters, locations, Scene boundaries, dialogue, Shot suggestions,
relationships, and omissions before confirmation.

The decomposition instructions tell the model to ignore publishing metadata,
tables of contents, scan/OCR notices, legal boilerplate, and other front or back
matter where the extracted narrative provides enough evidence to distinguish
it. Extraction and model judgment are best effort, so the administrator should
still verify that paratext did not become Story Graph content.

## Source retention and privacy

Every source selected through the admin flow remains a WordPress attachment
after import, cancellation, preview expiry, or a validation/model error. This
makes the original available for audit and retry, but it also means database and
uploads backups can retain manuscripts. Delete the attachment separately from
the Media Library when the site's retention policy requires it. Attachment
access follows the site's WordPress media/upload policy; the administrator-only
import screen does not by itself make an uploaded manuscript private.

During a resumable run, prepared chunks, evidence objects, partial graphs, and
compact graph memory are kept in separate user-and-job-scoped transient shards;
the small core state contains authenticated references and digests rather than
one monolithic manuscript value. Each shard is capped at 512 KiB, and shard
writes and reads are verified. Obsolete shards are deleted only after the state
that stopped referencing them commits. A durable cleanup list retries deletion
on a later authenticated job load when necessary.

The active-job deadline is fixed at creation and is not extended by successful
steps. It is 806,760 seconds (9 days, 8 hours, and 6 minutes): enough for two
passes over the maximum 1,000 spans using the six-minute request window, plus a
24-hour resume grace. Completion aligns the remaining job state to the
separate 30-minute preview deadline. Completion, terminal failure, and
cancellation remove core derivative shards early; if a deletion cannot finish,
the shard's original active-job expiration is the backstop. Optional WPVDB
vectors contain no raw story text, are capped by the owning run's fixed
deadline so a later write cannot extend their retention, and receive
best-effort per-run cleanup at those same terminal states. Synchronous runs use
their own run scope and request cleanup when they succeed or fail. Neither
transient lifecycle deletes the separate Media Library source.

For canonical JSON, extraction and validation remain local to WordPress. For
every other source, the extracted manuscript text is sent to the selected LLM
endpoint. A local OpenAI-compatible Connection can keep that request inside the
operator's network; a hosted Connection sends it to that provider. Operators
must evaluate the provider's privacy, retention, copyright, and usage terms.

The final preview response includes the derived canonical candidate, filename,
format, character count, model name, backend, pass/part counts, and token count.
Job-status responses expose only bounded progress such as the phase, completed
steps, current-section message, attempts, totals, and sanitized retrieval
backend/counts when an evidence retriever reports them. Neither response
returns the original extracted manuscript, chunk text, neighboring context,
evidence payloads, evolving graph memory, retrieval vectors, prompts, model
reasoning, endpoint configuration, credential reference, or credential.
Successful status, preview, and export payloads are marked `no-store`, and
provider or credential failures are normalized.

## Canonical JSON contract

The importer accepts versions 1.0, 1.1, and 1.2. The story decomposer and
canonical exporter emit version 1.2; compatibility adapters can still submit
older accepted versions. The canonical top-level sections are:

```text
project, world, characters, locations, props, organizations, episodes,
scenes, shots, sounds, assets, editorial_artifacts, sequence
```

`organizations`, `episodes`, `sounds`, `assets`, and
`editorial_artifacts` may be omitted by older or smaller documents and
normalize to empty arrays. There is no top-level `storyboards` section;
storyboard Markdown is a derived view of Scenes, Shots, and linked assets.

Evidence-pass observations, per-span synthesis objects, `continues_scene`
markers, chunk manifests, and compact evolving-graph summaries are transient
pipeline formats, not alternate import contracts. Only the fully merged,
normalized, dry-run-validated version 1.2 document is presented for import.

Portable relationships use external string IDs, never WordPress numeric IDs.
Taxonomy values use lowercase term slugs. Import with overwrite disabled uses
existing external IDs for reference resolution without mutating those records.
With overwrite enabled, optional fields use patch semantics: omission preserves
the stored value and an explicit empty value clears it. Sequence order is a
snapshot; other container relationships are additive.

The portable Project `generation_prompt` is the concise, pervasive Project
Visual Direction. Scenes may carry a `generation_prompt` as a Scene Look &
Lighting Changes field containing only differences from the Project baseline;
those differences take precedence inside the Scene. Scenes may also carry
optional `lens` and `camera_movement` defaults plus concise `audio_direction`
for Scene-wide ambience, music, and sonic palette inherited by linked Sound
generation. It excludes dialogue, lyrics, and individual cue events. Shots may
additionally carry `camera_movement`, `motion_direction`, and a
`generation_prompt` reserved for exceptional generation constraints. Scene-wide
direction belongs in `audio_direction`; dialogue, lyrics, and individual cue
details remain represented by linked `sounds[]` records rather than overloading
the visual `generation_prompt`. These optional fields remain valid in version
1.2, so older 1.0–1.2 documents and integrations that omit them continue to
import.

Because a canonical document contains one root Project and one root Story
World, portable records do not repeat those IDs on every fallback relationship.
Import hydrates each Prop's optional `story_world` relationship from the root
World for shared or unowned-Prop ancestry; an Owner Character remains primary
when present. It likewise hydrates each Scene's optional direct `project`
relationship from the root Project so a standalone Scene has Project ancestry;
an assigned Episode's Project path takes precedence.

Sound records are also direct representative-media sources. Their generated
audio prompt uses cue identity and type, compact Scene context and
`audio_direction`, duration, diegetic meaning, description, and production
notes. Imported `spoken_text` and `lyrics` remain exact performance copy; a
selected Template that cannot contain the required verbatim block must be
changed or the copy shortened rather than silently truncating it.

See the [JSON Import Specification](../example-workflow/JSON_import_spec.md) for
the complete field and relationship contract.

## Canonical JSON export

The JSON exporter finds the selected Project's nearest-project Story Graph and
requires exactly one directly related Story World. It emits the supported
Project, World, Character, Location, Prop, Organization, Episode, Scene, Shot,
Sound, Asset, Editorial Artifact, and one synthetic Sequence section.

Stored external IDs are preserved. A record without one receives a stable,
non-persisted `worldgraph-{cpt}-{post_id}` fallback in the export. Ordering is
deterministic:

- Episodes by `episode_number`;
- Scenes by Sequence order, then `scene_number`;
- Shots and Sounds grouped by Scene; and
- other collections by external ID.

Connections, Templates, generation jobs, WordPress lifecycle status and users,
the non-existent Storyboard CPT, and fields not in the importer version 1.2
contract are intentionally excluded. Project visual direction and Shot camera,
motion, and exceptional generation fields are included, as are Scene-specific
look and lighting changes, audio direction, lens, and camera defaults. The
derived Prop `story_world` and standalone Scene `project` fallback fields are
not repeated because the format reconstructs them from its single root World
and Project. The WordPress download name is `<project>.worldgraph.json`.

## Markdown export

The screenplay export uses the live Project and ordered Scenes, including Scene
summary/script content, linked Character names, and Shot headings. Structured
dialogue is not separately rendered when it is absent from `script_content`.
The download name ends in `-screenplay.md`.

The storyboard export projects ordered Scenes and Shots with their descriptions,
framing, lens, duration, and editorial notes. It does not serialize a separate
Storyboard entity. The download name ends in `-storyboard.md`.

## REST compatibility surface

The routes use WordPress authentication and the established `worldgraph/v1`
namespace:

| Method and route | Request | Result | Authorization |
| --- | --- | --- | --- |
| `POST /import/validate` | `json` | Dry-run validation only | `manage_options` |
| `POST /import` | `json`, optional `overwrite` | Committed import report | `manage_options` |
| `POST /import/decompose` | Persisted `attachment_id`; optional eligible `connection_id` for non-canonical sources | Synchronous canonical preview, or compatibility decomposition only for non-canonical text of at most 1,400 UTF-8 characters whose initial plan is exactly one span; larger/multi-span sources return HTTP 409 `worldgraph_story_decomposition_job_required`; no writes | `manage_options`, attachment `read_post`, and permission to manage the resolved Connection when an LLM is needed |
| `POST /import/decompositions` | Persisted non-canonical `attachment_id`; optional/default-zero `connection_id`; optional `overwrite` defaulting to `false` | Create a private resumable job; HTTP 202 with safe `job` projection and item `Location`; canonical JSON returns HTTP 409 `worldgraph_story_decomposition_not_required` | `manage_options`, attachment `read_post`, and permission to manage the resolved Connection |
| `GET /import/decompositions/{job_id}` | Existing user-scoped job token | Safe checkpoint status; does not advance work | `manage_options` and the same WordPress user that created the job |
| `POST /import/decompositions/{job_id}` | Existing user-scoped job token | Advances exactly one bounded checkpoint and returns safe status | Same |
| `DELETE /import/decompositions/{job_id}` | Existing user-scoped job token | Requests cancellation; source is retained | Same |
| `GET /export/{project_id}?format=json` | Project ID | Canonical document in `content` plus filename/MIME metadata | `manage_options` and Project `read_post` |
| `GET /export/{project_id}?format=screenplay` | Project ID | Markdown string in `content` plus filename/MIME metadata | Same |
| `GET /export/{project_id}?format=storyboard` | Project ID | Markdown string in `content` plus filename/MIME metadata | Same |

All decomposition routes are preview-only. A client must present the derived
candidate for review, then send its confirmed JSON through `POST /import`.
`POST /import/decompose` accepts an attachment already persisted inside
WordPress uploads; it does not accept raw manuscript text in the request or
echo that non-canonical source text in the response. REST clients can create a
resumable job with `POST /import/decompositions`, but that collection operation
also requires an existing persisted attachment and does not create a raw-text
upload surface. `connection_id` defaults to `0` so the server resolves the
default compatible Connection; `overwrite` defaults to `false` and is frozen
into the eventual preview. A successful creation is no-store, returns HTTP 202
as `{ "success": true, "job": { ... } }`, and supplies the item URL in the
`Location` header. Canonical JSON returns HTTP 409 with
`worldgraph_story_decomposition_not_required` from the collection route and
should use `/import/decompose`, `/import/validate`, or `/import` instead.

A job ID is an unguessable 256-bit base64url token whose transient state is
additionally bound to the creating WordPress user. Its 806,760-second active
deadline is fixed at creation and is not extended by steps. Each item POST
claims a per-job lock, performs one checkpoint, and returns the phase, a safe
current-section message, aggregate progress, analysis/synthesis counts,
attempts/tokens, action flags, and an optional normalized error. A sanitized
retrieval backend plus indexed/retrieved counts may be present, but never source
excerpts or vectors. Only a completed job exposes its 30-minute import preview
URL. No job response exposes
manuscript/chunk text, context, evidence, partial graphs, prompts, model
reasoning, model/profile/Connection details, or credentials.

## Headless parity

The REST compatibility surface is delivered, but the optional Next.js
application has no authenticated creator adapter or import/export interface.
It does not yet provide browser-user authorization, upload and retained-source
controls, collection-create/202/`Location` handling, safe job-status DTOs,
step/retry/resume/cancel handling, explicit confirmation, or download handling.
The capability is therefore **Partial — authentication-blocked**, not headless
parity. See
[Headless Parity](../../headless/PARITY.md).

## Related documents

- [Delivery Status](../Delivery_Status.md)
- [Integration Catalog](../Integration_Catalog.md)
- [Script and EDL Integration](../Script_EDL_Integration.md)
- [REST API Specification](../REST_API_Specification.md)
- [Example Workflow User Guide](../example-workflow/USER_GUIDE.md)

## Design references

The structure-aware chunk planner was informed by the general chunking approach
demonstrated by [PHPTextChunker](https://github.com/EdouardCourty/PHPTextChunker),
and the evidence-to-graph workflow was informed by Adaptive Recall's
[Build a Knowledge Graph from Text](https://www.adaptiverecall.com/knowledge-graphs/build-from-text.php).
These are design references, not runtime dependencies, and this implementation
does not claim to copy either package's code.
