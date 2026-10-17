# EDL extension notes

This bundled extension provides CMX-style text and XML parsing/generation, a preview/confirm admin workflow, line-level parse validation, timecode helpers, and export from a live Project or Episode Scene/Shot timeline. Confirmed imports persist as `worldgraph_editorial` Editorial Artifact posts.

The same workflow is exposed to authenticated WordPress Abilities/MCP clients
as `worldgraph/preview-edl-import`, `worldgraph/import-edl`, and
`worldgraph/export-edl`. The preview is supplied back explicitly for confirmed
import, avoiding the admin UI's request-scoped transient.

See [EDL format tools](../../../../../../about/plugins/EDL_IMPORT_AND_EXPORT.md) for the delivered boundary and extension work.
