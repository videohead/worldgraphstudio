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

## Why it exists

Creating new works in the age of AI across a variety of tools and services is
incredibly challenging. The "best" model today can be clearly usurped by tomorrow's
champion. Creating content locally keeps your thoughts and creative ideas out
of the way of the online generation tools, providing a concentrated place to 
store your world, location, and character ideas that can be easily shared. 
World Graph Studio keeps narrative context and production context together, 
so a character can stay connected to a world, a scene, a shot, a generated asset, 
an editorial decision, and the reasoning behind those choices. The narrative
context and descriptive content is maintained in one source of truth, and 
can be round-tripped through multiple story tools, or used to generate content
with any (or multiple) generation providers.

With self-hosted and open models, creators can work without a platform-level
credit meter, proprietary project format, or mandatory cloud account. Hosted
providers remain optional and may apply their own prices, quotas, licenses,
and usage policies. With the right hardware setup, this solution can generate 
end-to-end stories on BYOK or local compute at no cost using ComfyUI or other
local generate connections.

## What ships today

- A default-enabled Story Import & Export feature plugin with canonical World
  Graph Studio JSON import/export, Markdown screenplay/storyboard export, and
  preview-before-commit LLM decomposition for persisted JSON, TXT, Markdown,
  Fountain, Final Draft, RTF, text-layer PDF, EPUB, DOCX, and ODT uploads.
- A structured Story Graph with 15 content types, nine taxonomies,
  reusable relationships, analysis tools, and Structured Content Fields.
- Project, world, character, location, prop, organization, episode, scene,
  shot, sound, storyboard, asset, editorial, template, and connection tools.
- An AI Editor, Story Graph-aware analysis, continuity checks, relationship
  analytics, semantic-search fallbacks, and 50+ specialist agents loaded from
  extensible `.agent.md` profiles.
- Template-backed image, video, and audio generation jobs through an expanding list
  of connection providers, with media imports, provenance, status tracking,
  cancellation, and scheduled batches.
- Optional bidirectional project sync and reusable VideoDraft, EDL parsing, 
  timecode, and format-generation code.
- A permission-aware REST API and WordPress Abilities for tools, resources,
  and prompts.
- A custom WordPress theme for viewing and sharing your project and its structure
- Extensible headless interface for developers and users who want to escape
  the limitations of the WordPress GUI

See [Delivery status](about/Delivery_Status.md) for exact directions and
boundaries.

## Built to extend

- **Interchange adapters for importing and round trips.** The bundled Story
  Import & Export plugin owns canonical JSON import/export and Markdown
  projections. It can also extract persisted story uploads and ask a selected
  LLM Connection for a validated JSON candidate that the creator must preview
  and confirm. FDX and VideoDraft pull reuse that importer rather than replacing
  WordPress as the source of truth.
- **Connections are extendable to any API provider.** A generating connection integration 
  can register its provider metadata, a conditional loader, and setup choices through a
  WordPress hook. Its implementation then supplies any provider-specific
  validation, catalog, execution, or polling behavior it needs around shared
  Connection records to incorporate the widest range of generate providers.
- **Agents are profile-driven.** World Graph Studio discovers bundled
  `.agent.md` files at runtime, so a focused production role can be added
  without creating another service or changing the Story Graph. There are over
  50 agents provided by default, extend them or add more as needed.

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

## Quick Setup Guide

1. Install WordPress
   - Recommended: WordPress Studio
   - WordPress Studio is a free local WordPress development environment that lets you create and manage WordPress sites on your computer with minimal setup.
   - Visit: https://developer.wordpress.com/studio/
   - Download and install WordPress Studio for your operating system.
   - Launch Studio.
   - Create a new local WordPress site.
   - Wait for Studio to complete the automatic installation.
   - Open the WordPress Admin dashboard for your new site.
   - Alternative: LocalWP
   - LocalWP is another popular local development tool that automatically installs and configures WordPress, including SSL support.
   - Visit: https://localwp.com/
   - Download and install LocalWP.
   - Click Create New Site.
   - Enter a site name.
   - Accept the preferred environment settings (or customize as needed).
   - Create the site and allow WordPress to install automatically.
   - Click WP Admin to open the WordPress dashboard.

2. Install the Secure Custom Fields (SCF) plugin
   - Secure Custom Fields (SCF) allows you to create and manage custom fields, field groups, custom post types, and taxonomies within WordPress.
   - Log in to your WordPress Admin dashboard.
   - Navigate to Plugins → Add Plugin.
   - Search for Secure Custom Fields.
   - Click Install Now.
   - Click Activate.
   - After activation, access SCF from the WordPress admin menu to begin creating custom fields and field groups.
   - Plugin URL: https://wordpress.org/plugins/secure-custom-fields/

3. Install the World Graph Studio plugin
   - The plugin lives in wordpress/wp-content/plugins/worldgraph in this repository; you can copy it directly or zip and install it.
   - Activate the plugin.
   - Use the Setup Wizard to connect an LLM (API Key or BYOK) and a Generate connection (API Key or BYOK).
   - Add additional connections to your other Generate engines as needed.
   - Import an existing script or story and explore the tool.

## Theme files for a unified look
- There's also a theme in wordpress/wp-content/themes/worldworldgraph-child
- You will also need to install the frost theme (https://frostwp.com/)

## Quick start (for Developers and people looking to get into the guts)

The repository-root [`compose.yaml`](compose.yaml) is the sole local
development environment. It contains the WordPress + PHP 8.2, MariaDB,
phpMyAdmin, Node.js tooling, and optional headless services.

### Requirements

- Docker Desktop or Docker Engine
- Git
- An API-connected LLM only if you want AI Editor or specialist-agent features
- ComfyUI, Comfy Cloud, VideoDraft, or another configured provider only if you
  want automated asset generation

### Start the Docker Compose environment

```bash
git clone <repository-url> worldgraph
cd worldgraph
cp .env.example .env
docker compose up -d --build
docker compose ps
```

WordPress is served at `http://localhost:8080`, phpMyAdmin at
`http://localhost:8081`, and the optional headless frontend at
`http://localhost:3000` after its `headless` profile is started.

The `wordpress` image initializes WordPress core and `wp-config.php` in its
named volume. Secure Custom Fields remains a deployment dependency rather than
tracked source in this repository. For a fresh checkout and database, install
the site and required plugin before activating World Graph Studio:

```bash
docker compose exec wordpress wp core install \
  --url=http://localhost:8080 \
  --title="World Graph Studio" \
  --admin_user=admin \
  --admin_password='change-this-password' \
  --admin_email='you@example.com'
docker compose exec wordpress wp plugin install secure-custom-fields --activate
docker compose exec wordpress wp plugin activate worldgraph
```

World Graph Studio works with ordinary WordPress themes; no particular theme is
required by the plugin.

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
commands in the Docker Compose `node` service (or the `headless` service when
running the optional Next.js frontend). This avoids host-version drift and
ad-hoc local toolchain installs.

Examples:

```bash
docker compose exec node sh -lc 'node -v && npm -v'
docker compose --profile headless run --rm headless npm run build
```

Run the PHP test suite without writing PHPUnit's result cache:

```bash
docker compose exec wordpress /opt/worldgraph/vendor/bin/phpunit \
  -c /app/wordpress/wp-content/plugins/worldgraph/tests/phpunit.xml \
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
