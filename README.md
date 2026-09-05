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

## Local setup

The supported local environment is Docker Compose. WordPress, PHP, MariaDB,
WP-CLI, Node.js, and project dependencies stay in containers; the host needs
only Docker and Git. Follow the Docker quick start below, then use the setup
wizard to configure optional LLM and generation Connections.

The plugin works with ordinary WordPress themes. The optional
`wordpress/wp-content/themes/worldgraph-child` theme uses Frost as its parent,
so install Frost only if you choose to activate that child theme.

## Docker quick start

The repository-root [`compose.yaml`](compose.yaml) is the sole local
development environment. Its default stack contains WordPress + PHP 8.2,
MariaDB, and Node.js tooling. The `tools` profile provides phpMyAdmin and
PHPUnit, while the `headless` profile provides the optional frontend.

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

WordPress is served at `http://localhost:8080`. Published project ports bind
only to the host loopback interface and are not exposed to the local network.

Start phpMyAdmin only when needed; it is then available at
`http://localhost:8081`:

```bash
docker compose --profile tools up -d phpmyadmin
```

Log in with `WORDPRESS_DB_USER` and `WORDPRESS_DB_PASSWORD` from `.env`, then
stop it with `docker compose stop phpmyadmin` when you are finished.

The optional headless frontend is available at `http://localhost:3000` after
its `headless` profile is started.

### Migrating existing local data

The new `db_data` and `wordpress_data` volumes do not automatically import a
database or uploads from a previous local stack. Before switching, export the
existing database and preserve or copy `wp-content/uploads`, then restore both
into the new volumes and verify the site before removing the old environment.

For the included import helper, start with a fresh Compose database, place an
SQL export at `scripts/backup.sql` (or a gzip-compressed export at
`scripts/backup.sql.gz`), and run:

```bash
./scripts/setup-db.sh
```

If the previous uploads are available at `wordpress/wp-content/uploads`, copy
them into the running WordPress volume and restore the web-server ownership:

```bash
docker compose cp wordpress/wp-content/uploads/. \
  wordpress:/var/www/html/wp-content/uploads/
docker compose exec --user root wordpress \
  chown -R www-data:www-data /var/www/html/wp-content/uploads
```

After restoring a database, inspect its public URL and update only the
`siteurl` and `home` options when needed:

```bash
docker compose exec wordpress wp --skip-plugins --skip-themes \
  option get siteurl
docker compose exec wordpress wp --skip-plugins --skip-themes \
  option get home
docker compose exec wordpress wp --skip-plugins --skip-themes \
  option update siteurl http://localhost:8080
docker compose exec wordpress wp --skip-plugins --skip-themes \
  option update home http://localhost:8080
```

Never run `docker compose down -v` until the migrated database, uploads, and
site behavior have been verified. That command deletes the Compose-managed
volumes.

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
docker compose --profile tools run --rm phpunit \
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
