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

## Delivered capability

| Direction | Behavior | LLM required |
| --- | --- | --- |
| Canonical JSON → Story Graph | Dry-run validation, external-ID upsert, SCF/taxonomy population, relationship construction, Sequence ordering, verification, and import report | No |
| Story document → canonical JSON preview → Story Graph | Persist source, extract bounded text, ask the selected Connection for a version 1.2 candidate, normalize and validate it, then wait for explicit confirmation | Yes, except when the upload is already canonical JSON |
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
| EPUB | `.epub` | Visible document text is read in package spine order |
| Word document | `.docx` | Visible paragraph text is read from the document XML |
| OpenDocument text | `.odt` | Visible paragraph text is read from the content XML |

Current bounds are 20 MB per source and at least 20 usable extracted
characters. Canonical JSON follows the 20 MB upload boundary; non-canonical
sources are limited to 500,000 extracted characters and 300,000 characters per
decomposition. Sources through 60,000 characters use one
direct pass; longer sources are split near paragraph boundaries into at most
six ordered chunks of approximately 50,000 characters, then merged and
validated as one document. A source above 300,000 characters must be split into
separate imports. These are implementation limits, not a promise that every
selected model has enough context for the maximum accepted input.

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

1. The administrator selects a published, enabled OpenAI-compatible, OpenAI,
   or Anthropic Connection they may manage, with an endpoint and model
   configured.
2. WordPress persists the source and extracts bounded text.
3. The server sends that text, the sanitized filename, and server-owned
   decomposition instructions only to the selected Connection. It disables
   response caching and provider fallback for this request.
4. The decomposer accepts JSON objects from the model, merges bounded ordered
   chunks when needed, normalizes required sections, identifiers, references,
   and ordering, and asks the authoritative importer to dry-run validate the
   final document.
5. A direct candidate or individual chunk can receive one bounded JSON repair
   attempt. A final candidate that still fails validation is not offered for
   import.
6. The administrator reviews the resulting canonical document. The plugin does
   not create or update Story Graph records until **I reviewed this candidate
   and want to import it.** and **Confirm and Import Project** are submitted.

The admin preview is held in an immutable, user-scoped transient for 30 minutes.
The confirmation token cannot be used by another user, and the overwrite choice
cannot be changed between preview and commit. Confirmation revalidates before
writing. Cancel discards the pending preview but not the uploaded source.

LLM decomposition is a structured first draft, not a claim of perfect literary
interpretation. The administrator is responsible for checking titles,
characters, locations, Scene boundaries, dialogue, Shot suggestions,
relationships, and omissions before confirmation.

## Source retention and privacy

Every source selected through the admin flow remains a WordPress attachment
after import, cancellation, preview expiry, or a validation/model error. This
makes the original available for audit and retry, but it also means database and
uploads backups can retain manuscripts. Delete the attachment separately from
the Media Library when the site's retention policy requires it. Attachment
access follows the site's WordPress media/upload policy; the administrator-only
import screen does not by itself make an uploaded manuscript private.

For canonical JSON, extraction and validation remain local to WordPress. For
every other source, the extracted manuscript text is sent to the selected LLM
endpoint. A local OpenAI-compatible Connection can keep that request inside the
operator's network; a hosted Connection sends it to that provider. Operators
must evaluate the provider's privacy, retention, copyright, and usage terms.

The preview REST response includes the derived canonical candidate, filename,
format, character count, model name, backend, model-pass and chunk counts, and
token count. For a non-canonical source, it does not return the original
extracted manuscript. It never returns endpoint configuration, a credential
reference, or a credential. Successful preview/export payloads are marked
`no-store`, and provider or credential failures are normalized.

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

Portable relationships use external string IDs, never WordPress numeric IDs.
Taxonomy values use lowercase term slugs. Import with overwrite disabled uses
existing external IDs for reference resolution without mutating those records.
With overwrite enabled, optional fields use patch semantics: omission preserves
the stored value and an explicit empty value clears it. Sequence order is a
snapshot; other container relationships are additive.

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
the non-existent Storyboard CPT, and `generation_prompt` or other fields not in
the importer version 1.2 contract are intentionally excluded. The WordPress
download name is `<project>.worldgraph.json`.

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
| `POST /import/decompose` | Persisted `attachment_id`; selected `connection_id` for non-canonical sources | Validated canonical preview and safe processing metadata; no writes | `manage_options`, attachment `read_post`, and, when supplied, permission to manage the Connection |
| `GET /export/{project_id}?format=json` | Project ID | Canonical document in `content` plus filename/MIME metadata | `manage_options` and Project `read_post` |
| `GET /export/{project_id}?format=screenplay` | Project ID | Markdown string in `content` plus filename/MIME metadata | Same |
| `GET /export/{project_id}?format=storyboard` | Project ID | Markdown string in `content` plus filename/MIME metadata | Same |

The decomposition route is intentionally preview-only. A client must present
the derived candidate for review, then send its confirmed JSON through
`POST /import`. It accepts an attachment already persisted inside WordPress
uploads; it does not accept raw manuscript text in the request or echo that
non-canonical source text in the response.

## Headless parity

The REST compatibility surface is delivered, but the optional Next.js
application has no authenticated creator adapter or import/export interface.
It does not yet provide browser-user authorization, upload and retained-source
controls, selected-Connection preview, explicit confirmation/cancellation, or
download handling. The capability is therefore **Partial —
authentication-blocked**, not headless parity. See
[Headless Parity](../../headless/PARITY.md).

## Related documents

- [Delivery Status](../Delivery_Status.md)
- [Integration Catalog](../Integration_Catalog.md)
- [Script and EDL Integration](../Script_EDL_Integration.md)
- [REST API Specification](../REST_API_Specification.md)
- [Example Workflow User Guide](../example-workflow/USER_GUIDE.md)
