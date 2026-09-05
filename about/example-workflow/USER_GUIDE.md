# World Graph Studio User Guide

This guide takes a new user from installation to a connected sample project.
AI and generation services are optional: the Story Graph, planning, import,
export, and editorial tools work as ordinary WordPress features.

## 1. Install World Graph Studio

World Graph Studio requires WordPress 6.0 or newer, PHP 8.1 or newer, and the
Secure Custom Fields plugin. It works with ordinary WordPress themes and does
not require a specific theme.

From the repository root, start the Docker Compose environment and activate
the required plugins:

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec wordpress wp core install \
  --url=http://localhost:8080 \
  --title="World Graph Studio" \
  --admin_user=admin \
  --admin_password='change-this-password' \
  --admin_email='you@example.com'
docker compose exec wordpress wp plugin install secure-custom-fields --activate
docker compose exec wordpress wp plugin activate worldgraph
```

Then open `http://localhost:8080` and choose **World Graph Studio > Setup**.

## 2. Choose only the connections you need

The setup screen separates core WordPress operation from optional services:

- **No AI connection:** create and manage the Story Graph, assets, continuity
  metadata, imports, exports, and integrations manually.
- **LLM connection:** enable AI Editor conversations, analysis, generation
  assistance, specialist advisor workflows, and optional decomposition of
  unstructured story uploads into a canonical import preview.
- **Generation connection:** submit supported Templates through Comfy Cloud
  MCP, local ComfyUI, fal MCP, ElevenLabs, SunoAPI.org REST, AceData Cloud Suno
  MCP, Seedance 2.5 via CyberBara REST, or VideoDraft hosted MCP, depending on
  the adapter and Templates you configure.
- **Manual external generation:** create media in another tool, then import it
  into WordPress with its source and provenance.

For production, prefer environment-managed secrets. See
[Deployment and connections](../Deployment_and_Connections.md) for provider
requirements and network boundaries.

## 3. Import the sample project

The bundled **Story Import & Export** feature is enabled by default under
**World Graph Studio > Plugins**. Its canonical JSON path does not require an
LLM Connection.

The repository includes a comprehensive version 1.2 Little Red Riding Hood
example:

`about/example-workflow/little-red-riding-hood-full-featured.worldgraph.json`

The smaller
`about/example-workflow/little-red-riding-hood.worldgraph.json` version 1.1
fixture is retained for importer compatibility testing.

In WordPress:

1. Open **World Graph Studio > Import**.
2. Choose the `.worldgraph.json` sample.
3. Leave overwrite disabled for a first import.
4. Select **Create Import Preview**.
5. Review the validated canonical JSON preview, enable the confirmation
   checkbox, and select **Confirm and Import Project**.
6. Review the import report for created, updated, and skipped records.

The full-featured importer example creates the Project, Story World,
Characters, Locations, Props, Organization, Episode, Scenes, Shots, Sounds,
Assets, Editorial Artifact, Sequence data, taxonomies, and
relationships. The [JSON import contract](JSON_import_spec.md) documents the
exact mapping, compatibility behavior, and expected record counts.

### Import an existing story document

The same screen accepts JSON, TXT, Markdown, Fountain, RTF, PDF, EPUB, DOCX,
and ODT files up to 20 MB. Canonical JSON follows that upload-size boundary;
other extracted sources are limited to 500,000 characters. WordPress first
saves the selected file as a Media Library attachment. If it is already
canonical World Graph Studio JSON, the plugin validates it directly and makes
no model request. For non-canonical sources, a known model context can support
spans up to a 12,000-character target and 16,000-character hard maximum. When
context metadata is unavailable, the conservative profile uses a 1,100-
character target, 1,400-character hard maximum, 128 characters of context on
each side, and a 768-token response allowance. A plan cannot exceed 1,000
spans, including adaptive subdivisions. For an LLM decomposition:

The repository's [sample EPUB](pg28554-images-3.epub) can be used to exercise
the spine-order extraction, heading-aware planning, and resumable path below.

1. Ensure that a published OpenAI-compatible, OpenAI, or Anthropic LLM
   Connection is configured as the default and that you may manage it.
2. Choose the source file and select **Create Import Preview**.
3. Follow the progress view while the browser advances the decomposition one
   bounded step at a time. PHP first plans the manuscript around EPUB spine,
   chapter, section, Scene, paragraph, and sentence boundaries. The first model
   pass gathers textual evidence; the second re-reads each span and reconciles
   it with the compact graph assembled so far. Neighboring context is kept
   separate from the span so it can clarify continuity without being duplicated
   as a new Scene.
4. If a model response is invalid or truncated, the job may request compact
   JSON repair. A persistently invalid evidence span may be structurally
   subdivided without repeating completed spans. Progress completed before an
   interrupted browser request remains checkpointed; resume the same job from
   its progress page. Cancel stops further model work but does not delete the
   uploaded attachment. A source that exceeds the safe plan limit must be split
   or processed through a Connection with a larger context window.
5. Read the resulting Project, entity counts, source/preparation details, and
   JSON candidate.
   Model output is a draft; cancel and revise the source or retry if the
   candidate does not represent the source accurately.
6. Enable **I reviewed this candidate and want to import it.**, then select
   **Confirm and Import Project**. No Story Graph records are written before
   this step.

The progress view sends one request per analysis, synthesis, repair, or
finalization step instead of holding one browser request open for the entire
book. A provider, PHP, web-server, or reverse-proxy timeout does not confirm or
commit the candidate. Retry the current step to resume from the saved
checkpoint; persistent model failures can still require a smaller source, a
faster model, or a larger-context Connection. Temporary provider failures may
be resumed at the same saved checkpoint for three failed retries; the fourth
failure at that checkpoint stops the job. The active preparation deadline is
fixed at creation: 806,760 seconds (9 days, 8 hours, and 6 minutes), covering
the two passes over the 1,000-span ceiling plus a 24-hour resume grace. Once it
finishes, the separate review preview remains available for 30 minutes.
Prepared chunks and model intermediates are held in separate, verified private
transient shards of at most 512 KiB rather than one large job record. A terminal
checkpoint requests early cleanup; expiry remains the backstop if cleanup or a
run itself is abandoned.

For API-driven imports, create long-form work with
`POST /wp-json/worldgraph/v1/import/decompositions` using the persisted
`attachment_id`; then follow the returned `Location` and advance its item URL.
The legacy `/import/decompose` route is synchronous only for canonical JSON or
non-canonical input of at most 1,400 UTF-8 characters whose initial plan is
exactly one span.

The story reaches the model as a JSON data envelope under separate server-owned
instructions, not as chat history. Story text that resembles a command remains
untrusted manuscript data. Compatible reasoning controls are chosen by the
server, and model chain-of-thought is not shown or saved. Only the merged,
normalized, dry-run-validated World Graph Studio version 1.2 document becomes
the candidate you can confirm; intermediate evidence and partial graph objects
cannot be imported.

By default, related evidence is selected privately with lexical matching.
Neither Story Import & Export nor that base decomposer requires WPVDB.

The bundled **Story RAG Decomposer** is a separate, optional long-form
retrieval enhancement shown under **World Graph Studio > Plugins**. To use it:

1. Separately install WPVDB as a top-level WordPress plugin at
   `wp-content/plugins/wpvdb` in the target WordPress installation, alongside
   `worldgraph/` rather than inside it.
2. Activate WPVDB and configure its active embedding provider and model.
3. Then enable **Story RAG Decomposer**. Enabling World Graph Studio does not
   install WPVDB, and the nested RAG directory does not contain it. The Plugins
   page shows **Configure First** and keeps the enhancement's Enable control
   unavailable until WPVDB reports a valid configuration.

The embedding provider receives at most 12,000 characters of bounded evidence
or 6,000 characters of query input per call. The bridge stores up to 128
uniformly sampled numeric vectors and bounded identifiers in private,
per-user/per-run transients. Each expiration is capped by the owning run's fixed
deadline, so a later write cannot extend retention. Synchronous requests use a
separate run scope and request cleanup when they finish. The bridge does not put
manuscript, evidence, query text, or vectors in WPVDB's shared embeddings
table. WPVDB Core briefly uses its shared object cache during an embedding call,
so the bridge HMAC-scopes the input to the user and run and makes a best-effort
eviction of that exact cache key before and after the call. For resumable jobs,
completion, failure, and cancellation request per-run cleanup after the
terminal checkpoint commits, while expiration is the backstop. If WPVDB is
unavailable or fails, preparation retains the lexical result instead of
failing the base decomposition.

The model is instructed to ignore publishing metadata, tables of contents,
scan/OCR notices, legal boilerplate, and other front or back matter where the
narrative evidence permits. Review the candidate because text extraction and
that distinction remain best effort.

PDF support requires an extractable text layer. If the PDF is a scan or contains
only page images, the plugin reports that OCR is required; run OCR and upload
the searchable PDF or a text/EPUB version. Password-protected PDFs are not
supported.

The original upload remains in WordPress uploads after confirmation or
cancellation. Delete it separately from the Media Library if your retention
policy does not allow keeping the manuscript. Access follows the site's
WordPress media/upload policy; do not assume a manuscript attachment is private
solely because the import screen is administrator-only. When a hosted LLM
Connection is selected, the extracted story text is sent to that provider;
review its privacy and data-retention terms before proceeding.

## 4. Explore the Story Graph

Open the imported Project from the World Graph Studio dashboard, then follow
its related records:

- The Story World provides shared setting and worldbuilding context.
- Characters connect to scenes, locations, organizations, and props.
- Scenes establish narrative order and connect to shots and planned sounds.
- Shots connect production intent to storyboard frames and media assets.
- Assets preserve uploaded or generated media and provenance.
- Editorial artifacts preserve downstream edit and delivery information.

Edit these records like normal WordPress content. Structured Content Fields
hold the production-specific metadata; relationship controls preserve the
graph connections.

## 5. Review the story with AI

With an LLM connection configured, open a supported Story Graph record in the
block editor and use the World Graph Studio AI Editor sidebar.

You can:

- ask questions using the current record and related Story Graph context;
- analyze character, scene, dialogue, visual, or production choices;
- request suggestions from specialist creative advisors;
- run continuity-oriented checks; and
- prepare prompts and generation intent without detaching them from the
  project.

AI output is advisory. Review it before changing canonical project data.

## 6. Create or attach assets

For a configured generation connection:

1. Publish an active Template compatible with the connection.
2. Open a Story Graph record with the Assets workflow.
3. Select a runnable Template and provide any required prompt or references.
4. Queue the generation request.
5. Follow the persisted job status while WP-Cron processes it.
6. Review the returned media in WordPress and its linked Asset record.

World Graph Studio records the target entity, connection, template, parameters,
job state, and available provenance. Supported output depends on the selected
adapter and template. You can also upload media made elsewhere and link it to
the same Story Graph records.

For local WAN 2.2 or LTX 2.5 video, begin with the official task-specific
ComfyUI workflow, select its model files in ComfyUI, test it, and export API
format before materializing the World Graph Studio Template. Model loaders are
stored in the workflow, while only safely discovered scalar inputs become
per-run controls. See [Text to Video with Local ComfyUI](../how-to-text-to-video.md)
for model matrices, prompt patterns, tuning boundaries, and end-to-end
verification.

The Assets metabox offers direct **Image** and Shot **Video** outputs plus an
item/Project **Sequence** plan. On a Project, **Demonstration** previews an
ordered whole-story pass, with separate image and video Template selectors and
an audio Template selector when Sound cues need generation. Choose one Template
to override all generated work of that type or leave it configured per item;
declared scalar run controls appear only for the selected Templates. A Character
still is preferred for character-conditioned I2V, with recurring Characters
ordered first, while other moving Shots can use first/last Shot stills. Linked
Sound Assets are reused; unavailable
motion or audio falls back to stills, subtitle/title graphics, and silence.
The durable queue can run for hours or days, and **Stop pending work** prevents
new local dispatch while already-dispatched provider work may finish. When all
children are terminal, a separate resumable FFmpeg worker assembles and imports
the demonstration if FFmpeg is installed; otherwise the generated child media
is retained with an assembly diagnostic.

Suno prompt music, custom music, and `text_to_lyrics` are also available through
the generic Template-backed generation API. A Suno Connection requires a
SunoAPI.org REST key and a separate AceData Cloud MCP token; one cannot
authenticate the other. See [Suno Integration](../plugins/SUNO.md).

A manually configured Seedance 2.5 via CyberBara Connection provisions fixed
text-to-video and image-to-video Templates. The latter accepts one authorized
reference image; asynchronous completion imports the returned videos. This
uses a third-party CyberBara key, not a BytePlus or SeedanceAPI.org key. See
[Seedance 2.5 via CyberBara](../plugins/SEEDANCE.md).

A VideoDraft Connection provisions Templates from the provider's live tool
schemas. Image and video jobs are polled through WP-Cron, completed media is
imported into WordPress, and local reference attachments use the provider's
presigned upload flow. See [VideoDraft Connection and
Sync](../plugins/VIDEODRAFT.md).

## 7. Export and exchange work

The current release provides and catalogs these portable surfaces:

- **World Graph Studio JSON import** for structured project data.
- **World Graph Studio JSON export** for a deterministic canonical version 1.2
  snapshot of one live Project and its supported Story Graph.
- **Story document import** for JSON, TXT, Markdown, Fountain, RTF, text-layer
  PDF, EPUB, DOCX, and ODT through a default-Connection, resumable two-pass
  preview/confirm flow.
- **Final Draft FDX import** for screenplay scenes, locations, characters,
  action, and dialogue normalized into the Story Graph.
- **Deterministic Fountain importer source** targeting the shared browser-side
  Fountain-to-FDX and Story Graph pipeline; its current bootstrap blocker is
  cataloged and is separate from the delivered LLM decomposition of a
  `.fountain` upload.
- **Markdown screenplay export** from live project and scene records.
- **Markdown storyboard export** with shot and storyboard context.
- **Celtx connector source** for intended outbound synchronization; current
  response and Scene-call defects require repair before use.
- **Bidirectional VideoDraft structural Project sync** with preview, conflict
  checks, and checkpointed updates through the optional bundled integration.
- **EDL parsing, timecode, and format-generation PHP functions** for custom
  editorial adapters. The bundled admin workflow is incomplete.

The repository also contains prototype source for a Google Web Stories
connector. It is not loaded or supported as a current release workflow.

Open **World Graph Studio > Export** to download canonical World Graph Studio
JSON, a Markdown screenplay, or a Markdown storyboard. The sample Markdown
output is
[Little Red Riding Hood screenplay export](Little-Red-Riding-Hood-Screenplay-Example-Export.md).

Open **World Graph Studio > Import Final Draft FDX** to select a screenplay
file. Parsing and conversion happen in the browser; the normalized World Graph
Studio document is then validated and persisted by the canonical importer.
The deterministic Fountain-to-FDX admin surface remains a scaffold until its
browser bootstrap is repaired; use the general Import screen and an LLM
Connection when a Fountain file can be treated as unstructured story text.

The previously paused blanket category is closed for the current release:
Final Draft FDX is delivered, while Fade In, Highland, Story Architect,
additional professional exporters, and other unaccepted formats remain
possible adapters rather than unfinished requirements.

## 8. Keep the project portable and private

- Back up the WordPress database and uploads together.
- Remember that story-source uploads are retained in the Media Library; delete
  them separately when they should no longer be stored.
- Restrict site access using WordPress, hosting, and network controls when a
  project should remain private.
- Keep API credentials in deployment environment variables for production.
- Review the licenses and terms of every model and provider used to create an
  asset.
- Export project and editorial artifacts at meaningful milestones.

World Graph Studio does not impose a credit meter or claim ownership of your
work. Hosted services can still impose their own pricing, quotas, moderation,
and licensing terms.

## Next references

- [Delivery status](../Delivery_Status.md)
- [Story Graph specification](../Story_Graph_Specification.md)
- [Setup guide](../../wordpress/wp-content/plugins/worldgraph/documentation/SETUP_GUIDE.md)
- [REST API](../REST_API_Specification.md)
- [Generation engine](../plugins/GENERATION_ENGINE.md)
- [Suno integration](../plugins/SUNO.md)
- [Seedance 2.5 via CyberBara](../plugins/SEEDANCE.md)
- [VideoDraft connection and sync](../plugins/VIDEODRAFT.md)
- [Script and EDL integration](../Script_EDL_Integration.md)
- [Story Import & Export plugin](../plugins/STORY_IMPORT_EXPORT.md)
