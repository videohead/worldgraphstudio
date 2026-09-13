# Story Import

Story Import turns an existing project or manuscript into records in the Story
Graph. Every import provides a preview before it changes project records.

## Canonical project import

A `.worldgraph.json` file already follows the World Graph Studio structure. It
can be validated, previewed, and imported without AI. This is the most reliable
format for moving a project between installations or restoring an export.

In **World Graph Studio > Import**:

1. Choose the file.
2. Decide whether existing matching records may be overwritten.
3. Create the preview.
4. Review the project summary, counts, and validation messages.
5. Confirm the import only when the preview is correct.

Back up the destination site before importing valuable work.

## Importing a manuscript or script

The importer also accepts TXT, Markdown, Fountain, RTF, text-based PDF, EPUB,
DOCX, and ODT sources. These documents are not already a Story Graph, so World
Graph Studio needs a configured LLM Connection to propose Characters,
Locations, Scenes, and relationships.

Long documents are processed in bounded sections. Progress is checkpointed so
an interrupted browser request can resume. The resulting graph is a draft:
read the preview and correct the source or try again if it invents, omits, or
misunderstands story details. No Story Graph records are written until you
explicitly confirm.

Scanned or image-only PDFs require OCR before upload. Password-protected PDFs
are not supported.

## Final Draft and Fountain

Final Draft FDX import is a delivered structured path. Fountain files are
accepted as manuscript text for LLM-assisted decomposition; the separate
deterministic Fountain-to-FDX integration is not currently a supported import
workflow.

## Files, privacy, and retention

The uploaded source remains in the WordPress Media Library after confirmation
or cancellation. Delete it separately if your retention policy requires that.
WordPress media is not automatically private merely because the Import screen
requires administrator access.

When a hosted LLM is used, extracted story text is sent to that provider.
Review its privacy, retention, licensing, and pricing terms before importing
confidential or unpublished material.

See the [technical import and export specification](../about/plugins/STORY_IMPORT_EXPORT.md)
for supported limits and API behavior.
