# World Graph Studio Script and EDL Integration

> Build worlds. Connect ideas. Generate anything. No credits needed.

## Current Release Boundary

World Graph Studio treats the Story Graph as the canonical source of truth.
The repository contains both delivered interchange paths and bundled
integration scaffolds:

| Capability | Current status |
| --- | --- |
| World Graph Studio JSON import/export | Delivered by the default-enabled Story Import & Export feature plugin |
| Uploaded story decomposition | Delivered for JSON/TXT/Markdown/Fountain/RTF/text-layer PDF/EPUB/DOCX/ODT, with selected-Connection preview/confirm |
| Final Draft FDX screenplay import | Delivered bundled admin integration |
| Deterministic Fountain-to-FDX import | Bootstrap-blocked scaffold; separate from LLM decomposition of `.fountain` text |
| Markdown screenplay export | Delivered |
| Markdown storyboard export | Delivered |
| Celtx synchronization | Bundled source; response and Scene-call repair required |
| VideoDraft structural Project synchronization | Delivered optional bidirectional plugin |
| Descript transcript/media exchange | Experimental scaffold; not a delivered workflow |
| CMX 3600 EDL parser/formatter | Delivered PHP format code and admin workflow |
| SMPTE 436m XML EDL parser/formatter | Delivered PHP format code and admin workflow |
| CMX/XML EDL admin preview/download | Delivered; persists confirmed imports, exports a live Project/Episode timeline, and reports unparsable lines |
| Additional professional script-file adapters | Extension opportunity |

See [Delivery Status](Delivery_Status.md) for the repository-wide status source
of truth. The old blanket hold on additional script formats is closed for a
defined current-release scope; the table records what is operational and what
still needs implementation work.

## Canonical Workflow

```text
canonical JSON ────────────────┐
story uploads → extract → LLM  ├→ preview/confirm → Story Graph
FDX / VideoDraft pull ─────────┘                       │
                          JSON / Markdown export ←────┤
                          VideoDraft push ←───────────┘

CMX/XML files ↔ implemented EDL PHP format functions ↔ clip arrays
```

Scripts, storyboards, shot lists, and editorial files are projections or
exchange artifacts. Project, World, Character, Location, Scene, Shot, Sound,
Asset, and relationship records remain canonical in WordPress.

## Delivered Project Interchange

### World Graph Studio JSON Import and Export

The default-enabled
[`plugins/story-import-export/`](plugins/STORY_IMPORT_EXPORT.md) feature plugin
owns the versioned World Graph Studio JSON importer. It is available through
the WordPress admin or these administrator-only REST routes:

```http
POST /wp-json/worldgraph/v1/import/validate
POST /wp-json/worldgraph/v1/import
```

Validation can run without writes. A committed import creates or updates the
supported Project, Story World, Character, Location, Prop, Scene, Shot, Sound,
and Sequence records; resolves external IDs; assigns terms;
builds relationships; and verifies the resulting counts and references.

The JSON engine is the canonical structured project importer. FDX and
VideoDraft pull normalize their supported external structures into this
contract so validation and persistence do not fork by source format.

The same feature plugin exports one live Project as canonical version 1.2 JSON
from **World Graph Studio > Export** or:

```http
GET /wp-json/worldgraph/v1/export/{project_id}?format=json
```

The export contains the supported Project, World, Character, Location, Prop,
Organization, Episode, Scene, Shot, Sound, Asset, Editorial Artifact, and
Sequence sections. It omits Connections, Templates, generation jobs,
installation-specific users/status, and fields outside the importer contract.

### Uploaded Story Decomposition

The same Import screen accepts persisted JSON, TXT, Markdown, Fountain, RTF,
PDF, EPUB, DOCX, and ODT attachments. Canonical JSON is dry-run validated
without calling an LLM. Other sources are converted to bounded UTF-8 text and
sent only to the administrator-selected OpenAI-compatible, OpenAI, or Anthropic
Connection. The returned candidate is normalized and dry-run validated by the
canonical importer before it is shown.

The creator must inspect the preview and explicitly confirm before the plugin
commits any Story Graph records. Cancelling or completing that flow does not
delete the source attachment; it remains in WordPress uploads. PDF support is
limited to documents with an extractable text layer. A scan or image-only PDF
returns an OCR-required error and must be made searchable before retrying.

The headless-compatible preview endpoint accepts a persisted attachment and
selected Connection:

```http
POST /wp-json/worldgraph/v1/import/decompose
```

It returns the derived canonical candidate and bounded processing metadata.
For non-canonical sources it does not return the original manuscript, and it
never returns resolved Connection credentials. A REST client confirms by
submitting the reviewed candidate to `POST /import`.

### Final Draft FDX Import

The bundled FDX integration reads a `.fdx` screenplay locally in the browser.
It derives the Project and Story World title from the file name and maps Scene
Heading, Action, Character, Dialogue, Parenthetical, and Dual Dialogue content
into a World Graph Studio document, including Character, Location, Scene,
dialogue, and sequence records. Only the normalized World Graph Studio JSON is
submitted to WordPress, where the canonical importer validates and commits it.

The integration is an administrator workflow rather than a `/scripts/*` REST
surface. Its import direction is Final Draft FDX into the Story Graph; the
current release does not claim FDX export.

### Deterministic Fountain Import Scaffold

The bundled Fountain source is intended to read `.fountain`, `.spmd`, or
plain-text Fountain files locally in the browser, convert their supported
screenplay structure to FDX, and send the result through the FDX normalization
and canonical Story Graph importer. Its parser covers scene headings, action,
character cues, dialogue, parentheticals, transitions, and common shot lines.

The current page loads the shared FDX script without the FDX form. That script
dereferences the missing form before it publishes the parser needed by
Fountain, so the browser workflow is not currently delivered. After that
bootstrap defect is fixed and tested, its intended direction remains Fountain
into the Story Graph—not Fountain export or lossless preservation of every
application-specific syntax extension.

This blocker applies only to the deterministic Fountain-to-FDX page. A
`.fountain` file can already be persisted and treated as text by the Story
Import & Export plugin, then decomposed through a selected LLM Connection.

### Markdown Screenplay Export

The Story Import & Export plugin's WordPress Export screen derives a
screenplay-style Markdown document from the selected live Project, including
ordered Scenes, Scene summary and script content, linked Character names, and
Shot headings where present. Structured Scene dialogue is not read separately
by this exporter, so it appears only when it is already represented in
`script_content`. The download uses a `-screenplay.md` suffix.

### Markdown Storyboard Export

The same screen can derive a storyboard Markdown document from live Scenes,
Shots, descriptions, framing, lens, duration, editorial
notes, and ordering. The download uses a `-storyboard.md` suffix.

Markdown output is intentionally readable, diffable, and suitable for version
control. The current release does not claim native Final Draft or other
professional screenplay-file output.

## Celtx Connector Scaffold

The bundled `worldgraph-celtx` source targets outbound synchronization to the
Celtx GEM API. Its current sync layer re-parses already normalized API results
and passes them to a raw-response status check; Scene calls also require
episode and argument-order correction. Those defects block a verified
outbound workflow.

Persistent `_worldgraph_celtx_mapping` post meta stores the remote identity and
sync timestamp by entity category.

The source defines connection testing, full and type-specific outbound actions,
individual-item actions, mapping inspection, and unsync routes under the
`worldgraph/v1/celtx/*` REST surface. It does not import remote changes into
WordPress. See [Celtx Connector](plugins/CELTX.md) for the implementation status
and intended boundary.

## Delivered VideoDraft Synchronization

The bundled VideoDraft integration provides manual push and pull for the
shared structural Project subset. Pull supports a no-write preview before the
canonical importer persists data. Push checkpoints an existing remote Project
before update, and per-Connection mappings plus content hashes support conflict
detection.

This is bidirectional structural synchronization, not a claim of lossless
VideoDraft production-timeline interchange. See
[VideoDraft Connection and Sync](plugins/VIDEODRAFT.md) for the exact mapped
subset and REST contract.

## Experimental Descript Exchange

The Descript integration source sketches two separate directions rather than a
bidirectional project mirror. A pull exports one remote composition
transcript and imports it through the canonical importer as a Project, Story
World, Sequence, and transcript Scene. A push collects eligible audio/video
attachments bound to the selected Project's Scenes and related Shots and
submits their URLs to a Descript project-media import job.

It is not a delivered workflow: canonical media relationship lookup, callback
handling, binary transcript handling, and runtime contract tests remain
incomplete. It also does not infer structured Characters or Locations from transcript text,
mirror editable Descript composition structure, or export the Story Graph as a
Descript project schema. See [Descript Connection and Exchange](plugins/DESCRIPT.md)
for setup, routes, mapping, and job boundaries.

## Extending Script Interchange

The old blanket hold is closed without claiming every possible creative file
format. The following remain possible adapter work rather than shipped
capabilities or active delivery commitments:

- Fade In import.
- Highland import.
- Story Architect project import.
- OCR inside image-only or scanned PDFs.
- Deterministic, lossless application-specific parsing beyond the accepted FDX
  adapter and the LLM-assisted story decomposition surface.
- Format-specific deduplication and merge workflows beyond canonical
  validation and preview.
- Professional screenplay exporters beyond the delivered Markdown views.
- Additional script synchronization providers beyond delivered VideoDraft
  structural sync.

No `/scripts/*` routes are registered in v1. The Story Import & Export plugin
instead provides administrator-only `/import/validate`, `/import`,
`/import/decompose`, and `/export/{project_id}` compatibility routes. The
headless REST contract therefore exists, but the optional Next.js application
still lacks browser-user authentication, a creator adapter, and import/export
UI. Delivered FDX import uses a capability- and nonce-protected WordPress admin
action; the deterministic Fountain source targets the same pattern but is not
yet operational. Extensions should use their own namespaces until they satisfy
the canonical Story Graph mapping and validation contract.

## Story Graph Mapping Rules

An interchange adapter should preserve these meanings:

### Character

- Dialogue speaker and action references map to Character records.
- Scene participation is a graph relationship, not duplicated free text.
- Storyboard and Asset links remain associated with the Character.

### Location

- Scene headings and structured location references resolve to Location
  records.
- Location visual references and generated Assets retain their provenance.

### Scene

- Scene order, title/heading, summary, script content, structured dialogue,
  location, time of day, and production notes remain distinct fields.
- Shots, Sounds, Characters, and Assets remain linked rather
  than flattened into one document.

### Shot

- Shot order, camera/lens information, duration, slate/take data, and editorial
  notes remain attached to the Shot.

## EDL Integration

The optional EDL plugin contains a capability- and nonce-protected WordPress
admin-page scaffold. It does not add a REST namespace, and its current admin
workflow is not operational.

### Implemented PHP Formats

| Format | Parse function | Format function |
| --- | --- | --- |
| CMX 3600 ASCII (`.txt`, `.edl`) | Yes | Yes |
| SMPTE 436m XML (`.xml`) | Yes | Yes |
| AAF | No | No |
| OMF | No | No |

The PHP layer can parse CMX/XML into normalized clip data and convert timecodes
to frame positions. Handler code can store a short-lived preview, but the admin
page references missing JavaScript/CSS and its AJAX action name conflicts with
its operation dispatch. Confirmation does not create or update persistent Story
Graph timeline entities.

The export side formats clip arrays as downloadable CMX 3600 or SMPTE 436m XML.
The current Project/Episode timeline resolver is a development placeholder that
returns two fixed sample clips; it does not yet derive a live Story Graph cut.

### Export Surface and Controls

The admin-page scaffold presents controls for:

- Frame-rate choices for 23.976, 24, 25, 29.97, 30, 50, 59.94, and 60 fps.
- CMX reel names and configurable video/audio track designators.
- Optional pre-roll and post-roll handles.
- Eight- or 32-character clip-name formatting.
- A drop-frame option for fractional NTSC rates.
- Sequential record-in/record-out positions derived from source clip lengths.

The current admin handler does not forward its reel, handle, track, clip-name,
or drop-frame choices to the formatter, and its fractional-rate values are
integer sentinels rather than usable fractional rates. Treat those controls as
prototype UI. The implemented path is the PHP formatter operating on supplied
clip arrays, not a validated admin download or live-timeline export.

The CMX output is intended for NLEs that accept CMX 3600, including common
Premiere Pro, DaVinci Resolve, Avid Media Composer, and Unreal Sequencer
workflows. Actual compatibility still depends on the receiving application's
version, import settings, supported CMX subset, frame rate, and media relinking.
The XML path uses the plugin's SMPTE 436m event/component/timecode structure;
consumers should validate it against their target application.

### Current EDL Boundaries

- World Graph Studio does not ship AAF or OMF codecs.
- It does not ship Premiere Pro, Resolve, Avid, Final Cut, or Unreal panels.
- It does not embed or transfer source media in an EDL.
- EDL clip names and timecodes do not replace Story Graph identity and
  relationship metadata.
- Preview data, when the handler is invoked correctly, is transient.
- Project/Episode timeline extraction and fully wired export controls are not
  delivered.

AAF, OMF, persistent timeline import, direct media relinking, and NLE-specific
panels are valid extension points, not promised current-release work.

## Production, Continuity, and Advisors

World Graph Studio uses connected Scenes, Shots, Sounds, Storyboards, Assets,
and Editorial Artifacts to preserve context across planning and export. The
delivered continuity checker, graph analytics, AI Editor, and specialist
advisors can analyze that data without treating an imported script or EDL as a
second source of truth.

Advisor output may include scene analysis, shot suggestions, storyboarding
ideas, production preparation, and editorial guidance. It becomes canonical
only when a user saves approved information to the Story Graph.

## Design Contract

A script is not the source of truth.

A storyboard is not the source of truth.

An EDL is not the source of truth.

The Story Graph is the source of truth, and delivered exchange formats remain
traceable views of that structured data.
