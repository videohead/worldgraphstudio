# World Graph Studio

> Your ideas. Your assets. No credits needed.

World Graph Studio is a free, open-source, self-hosted creative production
platform for worldbuilding, storytelling, story analysis, asset generation,
and production planning. It runs in WordPress and keeps the people, places,
scenes, shots, media, and production decisions for a project connected in one
Story Graph.

Use World Graph Studio as a private creative workspace, publish from it when
you choose, and connect the local or hosted AI tools that fit your workflow.
The platform does not sell generation credits or require a single model
provider.

Interchange and extensibility are part of the product, not afterthoughts.
Bring screenplay and project data into the Story Graph, take readable
deliverables back out, add provider integrations around a shared Connection
registry without changing the canonical project model, and expand the bundled
team of specialist agents with new profile files.

## What ships today

- A structured Story Graph with 15 WordPress content types, nine taxonomies,
  reusable relationships, and Structured Content Fields.
- Project, world, character, location, prop, organization, episode, scene,
  shot, sound, storyboard, asset, editorial, template, and connection tools.
- An AI Editor, Story Graph-aware analysis, continuity checks, relationship
  analytics, semantic-search fallbacks, and 50+ specialist agents loaded from
  extensible `.agent.md` profiles.
- Template-backed image, video, and audio generation jobs through providers
  including VideoDraft, with WordPress media imports, provenance, status tracking,
  cancellation, and scheduled batches.
- A default-enabled Story Import & Export feature plugin with canonical World
  Graph Studio JSON import/export, Markdown screenplay/storyboard export, and
  preview-before-commit LLM decomposition for persisted JSON, TXT, Markdown,
  Fountain, RTF, text-layer PDF, EPUB, DOCX, and ODT uploads.
- Final Draft FDX import, normalized through the same canonical JSON importer.
- Optional bidirectional VideoDraft structural Project sync and reusable EDL
  parsing, timecode, and format-generation code.
- Bundled deterministic Fountain-to-FDX, Celtx, Descript, and Web Stories
  integration source with readiness and current blockers called out in the
  integration catalog.
- A filterable Connection adapter manifest that lets integrations register
  provider types, guided setup choices, and a conditional implementation
  loader.
- A permission-aware REST API and WordPress Abilities for tools, resources,
  and prompts.

The additional-script roadmap area that was previously described as on hold is
closed for the current release: story documents in the listed text-bearing
formats can now be decomposed through a selected LLM Connection, and Final
Draft FDX import remains delivered. Format-specific, lossless Fade In,
Highland, Story Architect, and other unaccepted adapters are extension
opportunities rather than unfinished requirements. The separate deterministic
Fountain-to-FDX importer, Celtx, Descript, and Web Stories surfaces remain
visible in the catalog with their actual scaffold or prototype status. See
[Delivery status](about/Delivery_Status.md) for exact directions and
boundaries.

## Built to extend

- **Interchange adapters reuse one Story Graph contract.** The bundled Story
  Import & Export plugin owns canonical JSON import/export and Markdown
  projections. It can also extract persisted story uploads and ask a selected
  LLM Connection for a validated JSON candidate that the creator must preview
  and confirm. FDX and VideoDraft pull reuse that importer rather than replacing
  WordPress as the source of truth.
- **Connections are an extension surface.** An integration can register its
  provider metadata, a conditional loader, and setup choices through a
  WordPress hook. Its implementation then supplies any provider-specific
  validation, catalog, execution, or polling behavior it needs around shared
  Connection records.
- **Agents are profile-driven.** World Graph Studio discovers bundled
  `.agent.md` files at runtime, so a focused production role can be added
  without creating another service or changing the Story Graph.

## Why it exists

Creative work is more than a prompt and an output file. World Graph Studio
keeps narrative context and production context together, so a character can
stay connected to a world, a scene, a shot, a generated asset, an editorial
decision, and the reasoning behind those choices.

With self-hosted and open models, creators can work without a platform-level
credit meter, proprietary project format, or mandatory cloud account. Hosted
providers remain optional and may apply their own prices, quotas, licenses,
and usage policies.

## Architecture

```text
Creator
  |
  v
WordPress + World Graph Studio
  |-- Story Graph, SCF, media, REST, and admin workflows
  |-- AI Editor, specialist agents, search, and continuity
  |-- extensible agents, interchange adapters, and Connection adapters
  |-- templates, generation jobs, and provenance
  |
  +--> optional LLM connection
  +--> optional ComfyUI / Comfy Cloud / provider connection
  +--> canonical JSON import/export and Markdown export
  +--> optional LLM decomposition of persisted story documents
  +--> FDX import
  +--> adjacent EDL format code
  +--> optional VideoDraft generation and structural project sync
  +--> experimental Descript transcript/media exchange source
```

WordPress is the application and source of truth. External AI and generation
services are replaceable connections; they do not own the Story Graph.

## Quick start (for Adam and other non-technical users)

- Install WordPress (recommend WordPress Studio at https://developer.wordpress.com/studio/ or use LocalWP from https://localwp.com/)
- Install SCF Plugin (https://wordpress.org/plugins/secure-custom-fields/)
- Install World Graph Studio Plugin (located in wordpress/wp-content/plugins/worldgraph from this repository - you can copy directly or zip and install)
- Activate the plugins
- Use the Setup Wizard to connect an LLM (API Key or BYOK) and Generate connection (API Key or BYOK)
- Add additional Connections to your other Generate engines as needed
- Import an existing script or story and explore the tool.

## Theme files for a unified look
- There's also a theme in wordpress/wp-content/themes/worldworldgraph-child
- You will also need to install the frost theme (https://frostwp.com/)

## Quick start (for Developers and people looking to get into the guts)

### Requirements

- Docker Desktop or Docker Engine
- [Lando](https://docs.lando.dev/getting-started/installation.html)
- Git
- An API-connected LLM only if you want AI Editor or specialist-agent features
- ComfyUI, Comfy Cloud, VideoDraft, or another configured provider only if you
  want automated asset generation

### Start the local site

```bash
git clone <repository-url> worldgraph
cd worldgraph
lando start
lando info
```

Lando starts WordPress, PHP 8.2, MariaDB, and phpMyAdmin. The default local URL
is `https://worldgraph.lndo.site`.

WordPress core and Secure Custom Fields are deployment dependencies rather than
tracked source in this repository. For a fresh checkout and database, install
them before activating World Graph Studio:

```bash
lando wp core download --force
lando wp config create \
  --dbname=wordpress \
  --dbuser=wordpress \
  --dbpass=wordpress \
  --dbhost=database \
  --skip-check
lando wp core install \
  --url=https://worldgraph.lndo.site \
  --title="World Graph Studio" \
  --admin_user=admin \
  --admin_password=<choose-a-password> \
  --admin_email=<your-email>
lando wp plugin install secure-custom-fields --activate
lando wp plugin activate worldgraph
```

World Graph Studio works with ordinary WordPress themes; no particular theme is
required by the plugin.

If you are restoring an existing database instead, import a serialization-safe
WordPress backup before activating `worldgraph`. Activation migrates supported
legacy StoryOS identifiers to the `worldgraph` namespace.

The Lando app name also changed to `worldgraph`. Lando uses that name when it
identifies services and database volumes, so an existing database from the old
app name is not moved automatically. Export it before switching Landofiles,
then import the archive into the new app and activate `worldgraph`.

Open **World Graph Studio > Setup** to configure an LLM and any optional
generation connections. Core story and production planning work without those
services. The bundled **Story Import & Export** feature is enabled by default
under **World Graph Studio > Plugins**. Canonical JSON import/export and
Markdown export work without an LLM; importing an unstructured story document
requires a compatible selected LLM Connection and an explicit preview/confirm
step.

## Documentation

Start with the [documentation guide](about/README.md), then use these primary
references:

- [Product overview](about/marketing/overview.md)
- [Delivery status](about/Delivery_Status.md)
- [Product requirements](about/World_Graph_Studio_PRD.md)
- [Architecture](about/World_Graph_Studio_Architecture.md)
- [User guide](about/example-workflow/USER_GUIDE.md)
- [Integration catalog](about/Integration_Catalog.md)
- [Script and editorial interchange](about/Script_EDL_Integration.md)
- [Story Import & Export plugin](about/plugins/STORY_IMPORT_EXPORT.md)
- [Agent architecture](about/Agent_Architecture.md)
- [Deployment and connections](about/Deployment_and_Connections.md)
- [VideoDraft connection and sync](about/plugins/VIDEODRAFT.md)
- [Descript connection and exchange](about/plugins/DESCRIPT.md)
- [Story Graph specification](about/Story_Graph_Specification.md)
- [REST API](about/REST_API_Specification.md)

## Namespace

The product name is **World Graph Studio**. Machine-readable identifiers use
`worldgraph`, PHP symbols use `WorldGraph`, and constants and environment
variables use `WORLDGRAPH_`.

## Development

### Node and npm usage

Use container-managed Node.js by default. For this repository, run Node/npm
commands in the Lando `cli` service (or the `headless` service when running
the optional Next.js frontend). This avoids host-version drift and ad-hoc local
toolchain installs.

Examples:

```bash
lando exec cli -- sh -lc 'node -v && npm -v'
lando exec cli -- sh -lc 'cd /app/headless && npm run build'
```

Only use host-installed Node/npm when you intentionally run the headless app
outside Lando.

Run the PHP test suite without writing PHPUnit's result cache:

```bash
./vendor/bin/phpunit \
  -c wordpress/wp-content/plugins/worldgraph/tests/phpunit.xml \
  --testsuite "World Graph Studio" \
  --do-not-cache-result
```

Development conventions and runtime-specific commands are in
[`.github/instructions/instructions.md`](.github/instructions/instructions.md).
Contributions are welcome; see the
[contributing guide](about/CONTRIBUTING_World_Graph_Studio.md).

## License

Copyright (c) 2026 Matthew Galvin.

Except where an individual component carries its own license notice, World
Graph Studio is licensed under the [GNU General Public License, version 2 or
(at your option) any later version](LICENSE) (`GPL-2.0-or-later`).
Third-party components and dependencies remain under their respective licenses,
including the [notice for the optional headless frontend](headless/THIRD_PARTY_NOTICES.md).

---

Build worlds. Connect ideas. Generate anything. No credits needed.
