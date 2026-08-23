# VideoDraft Connection and Sync

**Status: delivered optional generation adapter and bidirectional structural
project sync.** VideoDraft is available both as a `videodraft` Connection for
asset generation and as a bundled import/export plugin.

## Connection setup

Create the Connection through the Setup Wizard or **World Graph Studio >
Connections**:

| Field | Value |
| --- | --- |
| Provider Type | `videodraft` |
| Environment | `production` |
| Endpoint URL | `https://app.videodraft.ai/api/mcp` |
| MCP Endpoint URL | `https://app.videodraft.ai/api/mcp` |
| API Key / OAuth Reference | A dedicated VideoDraft PAT, preferably `env://VIDEODRAFT_API_KEY` |

The WordPress adapter implements the VideoDraft CLI's JSON-RPC transport in
PHP. It does not install or invoke Node. Authentication is sent as a bearer
token, and secrets are not copied into Template, generation, sync-mapping, or
log records. The PHP cURL extension is required for streamed local-reference
uploads; that transport honors WordPress proxy constants and uses WordPress's
CA bundle.

Testing the Connection discovers `tools/list`, verifies the generation and
project tools used by this integration, and provisions Templates from the live
input schemas. Current mapped tools are:

| VideoDraft tool | World Graph Studio modality | Output |
| --- | --- | --- |
| `generate_image` | `text_to_image` | Image |
| `generate_video` | `text_to_video` | Video |
| `generate_audio` | `text_to_sound_effect` | Audio |
| `generate_voiceover` | `text_to_speech` | Audio |
| `generate_music` | `text_to_music` | Audio |
| `generate_sound_effect` | `text_to_sound_effect` | Audio |

Image and video jobs are polled with `check_generation_status`. Synchronous
audio URLs and completed asynchronous media are downloaded into the WordPress
Media Library before the generation record is marked complete. Template media
bindings that resolve to local attachments use VideoDraft's presigned upload
flow. Seed Audio imports support MP3, WAV, and Ogg/Opus; raw PCM is rejected
before submission because WordPress does not provide a portable PCM media type.
Transient Seed Audio reconciliation reuses one persisted idempotency key and
the exact resolved tool request. Attachment permissions are checked again on
every retry without re-uploading the same references.

## Sync setup

Open **World Graph Studio > Plugins**, configure **VideoDraft Sync**, select the
same VideoDraft Connection, and enable the plugin. The settings page provides:

- Connection testing and remote-project listing;
- local Project push, with blank-project creation when no remote ID is mapped;
- pull preview through the World Graph Studio importer's no-write dry run;
- committed pull with stable external IDs and overwrite-based idempotency;
- explicit conflict override; and
- local mapping removal without deleting either project.

The mapping is stored on the local Project in
`_worldgraph_videodraft_mapping`, keyed by Connection ID. It records the remote
project ID, local and remote hashes, push/pull timestamps, and the latest sync
status. There is no second copy of the API token.

Before updating an existing VideoDraft project, the plugin creates a remote
checkpoint. Push sends the complete mapped `storyboard.scenes` and
`visual_assets` arrays because VideoDraft array updates replace arrays rather
than merging individual members. Before replacement, the mapper overlays WGS-
owned fields onto matching raw remote assets, Scenes, and Shots. This retains
VideoDraft record IDs, remote-only array members, asset subtypes, media URLs,
and provider-specific fields. A changed remote hash blocks push, and a changed
local hash blocks committed pull, until an administrator previews and explicitly
forces the chosen direction. Projects with no storyboard Scenes cannot be
pushed or pulled.

## Mapped project subset

The two directions preserve the shared structural subset:

- Project title and description;
- per-Scene script text;
- storyboard Scenes and nested Shots;
- Scene dialogue and Character, Location, and Prop references;
- visual assets classified as Characters, Locations, or Props; and
- available Shot and storyboard descriptions.

Pull translates that subset into the canonical World Graph Studio JSON format
and delegates persistence to the Story Import & Export feature plugin's
canonical importer. It then adds the Project edge needed to keep imported
Scenes project-scoped. WGS Props are emitted with VideoDraft's `object`
visual-asset type. An existing VideoDraft `style`, `custom`, or other provider
subtype is retained when that record is updated.

The top-level VideoDraft script, image and other media URLs, provider-only
fields, production timeline clips, account data, comments, and checkpoints
remain provider-owned. The mapper preserves those project and array values
during push, but does not import them into WGS, include them in the WGS-owned
conflict subset, or synchronize changes to them. Deletions are not mirrored,
and generated remote media URLs are not treated as a lossless Media Library
migration.

The exact hosted project schema is discovered through `get_project_schema` at
runtime because it is not vendored by the CLI repository. Sync is manual: the
upstream CLI exposes no webhook, delta cursor, ETag, or revision contract for
automatic two-way reconciliation.

## REST routes

When the plugin is enabled, administrators can use:

```http
GET    /wp-json/worldgraph/v1/videodraft/projects
GET    /wp-json/worldgraph/v1/videodraft/schema
POST   /wp-json/worldgraph/v1/videodraft/push
POST   /wp-json/worldgraph/v1/videodraft/pull
GET    /wp-json/worldgraph/v1/videodraft/mapping/{project_id}
DELETE /wp-json/worldgraph/v1/videodraft/mapping/{project_id}
```

`POST /videodraft/pull` defaults to `dry_run: true`. Set `dry_run: false` to
commit after reviewing the preview. All routes require `manage_options`.

## Upstream basis

The adapter follows VideoDraft CLI `0.14.0` at commit
`78914d58d1f46d4c4e26cdec7df3304f1bed5af8`:

- [JSON-RPC transport](https://github.com/videodraft-ai/cli/blob/78914d58d1f46d4c4e26cdec7df3304f1bed5af8/src/core/rpc.ts)
- [generation commands](https://github.com/videodraft-ai/cli/blob/78914d58d1f46d4c4e26cdec7df3304f1bed5af8/src/commands/generate.ts)
- [project commands](https://github.com/videodraft-ai/cli/blob/78914d58d1f46d4c4e26cdec7df3304f1bed5af8/src/commands/projects.ts)
- [project update and checkpoint guidance](https://github.com/videodraft-ai/cli/blob/78914d58d1f46d4c4e26cdec7df3304f1bed5af8/skills/videodraft/references/pipeline.md)
