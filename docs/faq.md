# Frequently Asked Questions

## Do I need to understand WordPress?

You need to know how to sign in, use the dashboard menu, edit and save records,
and upload files. You do not need to build a theme, write PHP, or run a blog.
Start with [Getting Started](getting-started.md).

## Is World Graph Studio a separate application?

It is a plugin that turns a WordPress installation into a structured creative
workspace. WordPress supplies the database, accounts, editing interface, media
library, and web publishing foundation.

## Do I need AI or a paid service?

No. Core Story Graph authoring, relationships, continuity tools, assets,
canonical JSON import/export, and Markdown export work without AI. Optional
hosted providers may charge separately.

## What is Secure Custom Fields?

It is the required WordPress plugin World Graph Studio uses for structured
fields. Install and activate it before activating World Graph Studio.

## What is the Story Graph?

It is the connected set of Projects, worlds, Characters, Locations, Scenes,
Shots, Assets, and other records. See [The Story Graph](world-graph.md).

## Can I import an existing manuscript?

Yes. Canonical World Graph Studio JSON imports without AI. TXT, Markdown,
Fountain, RTF, text-layer PDF, EPUB, DOCX, and ODT can be turned into an import
proposal through a configured LLM Connection. Final Draft FDX import is also
supported. Always review the preview before confirming.

## Can I export my work?

Yes. Canonical JSON preserves a project for interchange, while screenplay and
storyboard Markdown create readable deliverables. See [Story Export](story-export.md).

## Is my project automatically private because it is self-hosted?

No. Self-hosting gives you control over the installation, but privacy still
depends on hosting, WordPress accounts, site visibility, media permissions,
backups, and the external services you configure.

## Where do uploaded manuscripts go?

They remain in the WordPress Media Library after an import completes or is
cancelled. Delete them separately when required by your retention policy.

## What happens if I deactivate the plugin?

Deactivation stops World Graph Studio hooks and scheduled work but does not
delete its story content, media, settings, mappings, logs, or credentials.

## How do I permanently remove its data?

Administrators can use **World Graph Studio > Purge Data** with typed
confirmation. This is permanent and can remove Story Graph records, generated
media marked by the plugin, settings, credentials, jobs, and related data.
Make a backup first and separately revoke external provider credentials.

## Where can developers find implementation details?

The [technical documentation index](../about/README.md) covers architecture,
APIs, schemas, adapters, delivery status, and individual integrations.
