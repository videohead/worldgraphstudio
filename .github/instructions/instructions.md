# World Graph Studio Build Instructions

> Your ideas. Your assets. No credits needed.

This file defines the active development conventions for World Graph Studio. World Graph Studio is a
WordPress application whose canonical data model is the Story Graph. ComfyUI is
an optional external generation service used by the relevant plugin.

## Local Entry Points

When the Docker Compose services are running, use these entry points for local
validation:

- WordPress app: http://localhost:8080/
- phpMyAdmin: http://localhost:8081/
- Optional headless frontend: http://localhost:3000/
- ComfyUI HTTP API: http://localhost:8188
- Optional ComfyUI MCP: a deployment-specific, separate Streamable HTTP
  endpoint (for example, http://localhost:9000/mcp); port 8188 is not MCP
- Local LLM: http://localhost:11434

Use `docker compose ps` to confirm service state before testing. WordPress is
the application and control plane; do not assume a separate Python, queue, or
orchestration service exists.

## Docker Compose Runtime Ownership

Use the service that owns the runtime required by the command:

| Runtime | Docker Compose service | Project path |
| --- | --- | --- |
| WordPress, PHP, and WP-CLI | `wordpress` | live root `/var/www/html`; source `/app/wordpress` |
| Node.js, npm, Playwright, and JavaScript checks | `node` | `/app` |
| MariaDB | `database` | N/A |

WP-CLI belongs in `wordpress`, not the Node-based `node` service. The intended
project command, run from the repository root, is:

```bash
docker compose exec wordpress wp <command> [arguments]
```

A pinned WP-CLI Phar is installed and checksum-verified by the `wordpress`
image. Existing containers created before that build step was added may still
lack the executable. If the command fails with an OCI error such as
`exec: "wp": executable file not found in $PATH`, confirm the container state:

```bash
docker compose exec wordpress sh -lc 'command -v wp'
```

An empty result means WP-CLI is not installed in the PHP runtime. Do not retry
the command in `node`: that service intentionally provides Node.js and does
not own the WordPress PHP runtime. Rebuild the service with
`docker compose up -d --build wordpress`, then retry. WP-CLI should run against
the live WordPress root at `/var/www/html`; pass `--path=/var/www/html` when
invoking it from another working directory.

For PHP-only diagnostics that do not require WP-CLI, use the installed PHP
runtime directly:

```bash
docker compose exec wordpress php -r '<php code>'
```

This is a diagnostic fallback, not a general replacement for WP-CLI commands.

## Project Scope

- WordPress core and the World Graph Studio plugin live under `wordpress/`.
- World Graph Studio custom post types, Structured Content Fields, REST endpoints, and
  integrations live under `wordpress/wp-content/plugins/worldgraph/`.
- ComfyUI integration lives in the relevant World Graph Studio plugin and should fail
  clearly when the optional service is unavailable.
- When a separate ComfyUI MCP server is configured, its advertised tools are
  authoritative for that MCP surface. Ordinary ComfyUI on port 8188 remains an
  HTTP API and must not be treated as MCP.
- The Story Graph is the canonical model for projects, story worlds,
  characters, locations, scenes, shots, and assets.
- `headless/` is optional to deploy, but parity is a required repository
  deliverable for applicable delivered functionality and user-facing behavior.
  Follow the scoped headless parity instructions and maintain
  `headless/PARITY.md`; WordPress-only exceptions must be explicit.
- Keep architecture and API changes synchronized with the specifications in
  `about/`.

## Project Structure

### Root Level
- `.github/` - GitHub configuration, including agent definitions and testing utilities
  - `agents/` - VS Code Copilot Agent definitions (builder, code-reviewer,
    connection-builder, feature-builder, implementer, planner, researcher,
    thorough-reviewer)
  - `instructions/` - Build and development instructions (this file)
  - `testing/` - Testing documentation and utilities
- `about/` - Comprehensive documentation, specifications, and roadmap
- `headless/` - Optional Next.js deployment with a required, tracked parity
  deliverable for applicable WordPress capabilities
- `scripts/` - Setup and utility scripts (database, initialization, etc.)
- `wordpress/` - WordPress core and plugins
  - `wp-content/plugins/worldgraph/` - Main World Graph Studio plugin with expanded structure:
    - `includes/admin/` - WordPress admin functionality
    - `includes/agents/` - Agent-related code and integrations
    - `includes/ai-editor/` - AI Editor implementation
    - `includes/cpts/` - Custom Post Type definitions and handlers
    - `includes/rest-api/` - REST API controllers and endpoints
    - `includes/taxonomies/` - Custom taxonomy definitions
    - `includes/utils/` - Utility functions and helpers (generation, search, relationships, continuity)
    - `plugins/` - Sub-plugins and integrations:
      - `story-import-export/` - Default-enabled canonical JSON importer and
        exporter, Markdown exporters, persisted story-source extraction, and
        selected-Connection LLM decomposition with preview/confirm
      - `celtx/` - Celtx GEM API connector scaffold
      - `descript/` - Experimental Descript transcript/media exchange
      - `edl/` - EDL PHP format functions and admin scaffold
      - `fdx/` - Final Draft FDX screenplay import
      - `fountain/` - Fountain-to-FDX importer scaffold
      - `videodraft/` - VideoDraft structural Project synchronization
      - `web-stories/` - Web Stories connector prototype source
    - `assets/` - Frontend assets
      - `ai-editor/` - AI Editor React components and styles
      - `css/` - Stylesheets
      - `js/` - JavaScript files
    - `tests/` - Test files and test utilities
  - `wp-content/plugins/secure-custom-fields/` - Structured Content Fields (SCF) plugin

## Current Delivery Scope

The current core repository is delivered, but the presence of a bundled
integration directory does not by itself make that workflow release-ready.
Optional provider accounts and external services still require deployment-
specific credentials and configuration. Use `about/Delivery_Status.md` and the
integration catalog as the status sources of truth.

### Script Ecosystem

- The default-enabled `plugins/story-import-export/` feature plugin owns World
  Graph Studio JSON import/export, Markdown screenplay/storyboard export, and
  preview/confirm decomposition of persisted JSON, TXT, Markdown, Fountain,
  RTF, text-layer PDF, EPUB, DOCX, and ODT sources through a selected LLM
  Connection.
- Final Draft FDX import normalizes through the feature plugin's canonical
  importer.
- Bidirectional VideoDraft synchronization for its shared structural Project
  subset, with preview, checkpoints, conflict hashes, and persistent mapping.
- The separate deterministic Fountain-to-FDX importer source is
  bootstrap-blocked; this does not prevent the Story Import & Export plugin
  from treating `.fountain` as an unstructured LLM source. Celtx connector
  source needs response and Scene-call repair; Descript exchange remains
  experimental.
- Further screenplay formats and professional exporters are extension
  opportunities, not unfinished current-release commitments.

### Extension Surfaces

- `includes/agents/*.agent.md` profiles are discovered at runtime; a focused
  advisor can be added without introducing another execution service.
- Provider Connection adapters register metadata, conditional loaders,
  Connection callbacks, Template provisioners, and generation dispatch through
  `worldgraph_conn_adapters`. The provider still owns authenticated transport,
  safe discovery, operation allowlists, normalization, and output handling.
  Follow the
  [provider Connection adapter specification](../../about/Connection_Adapter_Development_Specification.md)
  for the exact REST API, MCP, Template, execution, security, and test
  contracts.
- Format integrations should normalize through the Story Import & Export
  plugin's canonical World Graph Studio import contract or project an export
  from live Story Graph data rather than create a parallel data model.

### Editorial Ecosystem

- CMX-style text and XML EDL PHP parsing, timecode, and generation functions.
- The bundled admin workflow, timeline persistence, and live Project/Episode
  export remain adapter work.
- Editorial artifact, scene, shot, track, and timecode metadata.
- AAF, OMF, and provider-specific NLE panels are extension points, not current
  delivery commitments.

### Story Graph Intelligence

- Semantic search.
- Continuity validation.
- Relationship analytics and narrative reasoning.

### AI Editor

The AI Editor is a Gutenberg sidebar backed by a direct LLM connection
layer and Story Graph context. Keep its boundary inside WordPress:

- Chat, analysis, generation, and continuity-check REST endpoints.
- A context builder that assembles data for the current post.
- Local vLLM support with optional cloud fallback.
- WordPress Abilities API declarations for future AI capability exposure; the
  current registration lifecycle is incomplete and must not be treated as a
  working inbound MCP surface.
- Settings for backend selection, credentials, and model configuration.

Do not add a router, framework bridge, or separate execution service to this
module. Implementation files are located in:

- `includes/ai-editor/` - AI Editor PHP implementation
  - `class-ai-editor.php` - Main bootstrap/controller
  - `class-ai-llm-client.php` - LLM communication
  - `class-ai-context-builder.php` - Story Graph context assembly
  - `class-ai-editor-rest.php` - REST API endpoints
  - `class-ai-abilities.php` - Intended Abilities API declarations; current
    registration lifecycle is incomplete
- `assets/ai-editor/` - Frontend assets
  - `js/` - React Gutenberg sidebar components
  - `css/` - Panel and component styles

The full feature specification is in `about/AI_Editor.md`.

## Coding Conventions
For WordPress PHP code, templates, CPTs and other WordPress specific work, follow
https://codex.wordpress.org/WordPress_Coding_Standards


### Node and npm usage

Use container-managed Node.js by default. For this repository, run Node/npm
commands in the Docker Compose `node` service (or the `headless` service when running
the optional Next.js frontend). This avoids host-version drift and ad-hoc local
toolchain installs.

Examples:

```bash
docker compose exec node sh -lc 'node -v && npm -v'
docker compose --profile headless run --rm headless npm run build
```

### WordPress

- Use WordPress Coding Standards (WPCS).
- Register custom post types in the World Graph Studio plugin's established registration
  surface.
- Use Structured Content Fields via SCF in
  `wordpress/wp-content/plugins/secure-custom-fields`.
- Preserve the established `worldgraph/v1` REST compatibility surface beneath
  `/wp-json/worldgraph/v1/` for existing clients. Do not make its wire shape the
  automatic product model or contract for new headless work; a new or changed
  headless API contract requires an explicit architecture and specification
  decision.
- All World Graph Studio custom post types must support the REST API.
- Use WordPress nonces for form submissions.
- Sanitize input and escape output.
- Keep sub-plugins under `worldgraph/plugins/`.

### WordPress REST Controllers: Static Method Pitfall

- `WP_REST_Controller::register_routes()` is non-static in WordPress core.
- Child classes must not override it as static; PHP 8.x treats that as a fatal
  signature error.
- Use an instance for route registration:

  ```php
  public static function init(): void {
      $instance = new self();
      add_action( 'rest_api_init', [ $instance, 'register_routes' ] );
  }

  public function register_routes() {
      // Register routes here.
  }
  ```

- Apply this pattern to every REST controller in `includes/rest-api/`.

### Docker Compose

- Use the repository-root Docker Compose stack for local environment
  management.
- Keep WordPress and database data in named volumes; never bind-mount them.
- Run ComfyUI behind its GPU-enabled service when GPU support is available.
- Never commit sensitive `.env` files.
- Restart containers only when changing Docker configuration, dependencies, or
  infrastructure. PHP changes take effect on the next request.

### Git

- Use conventional commit messages.
- Humans review changes before committing.
- Keep feature work on an appropriate branch.
- Tests must pass before merging.

## Testing

### Tool Calling

- Docker Compose is the required environment for local validation.
- Run WordPress and PHP commands in `wordpress`; run Node.js commands in `node`.
- Use `docker compose exec wordpress wp` only after `command -v wp` succeeds
  in `wordpress`. An OCI
  `executable file not found` error means the image lacks WP-CLI; it does not
  mean WP-CLI belongs in the Node service.
- Node.js is available in the `node` service, not the host or `wordpress`
  service. Run JavaScript checks with
  `docker compose exec node node --check /app/path/to/file.js`.

### WordPress: Do Not Restart

- WordPress runs PHP directly and does not need restarting after PHP changes.
- Do not run `docker compose restart wordpress` for PHP changes.
- If old code is still served, clear OPcache with:

  ```bash
  docker compose exec wordpress php -r "opcache_reset();"
  ```

### WordPress: WP_Widget Method Signatures

WordPress's `WP_Widget` methods have no parameter type hints. Child classes must
preserve compatible signatures:

- `form( $instance )`, not `form( array $instance )`.
- `widget( $args, $instance )`, not typed parameters.
- `update( $new_instance, $old_instance )`, not typed parameters.

Return types are acceptable only when compatible with the installed WordPress
version.

### WordPress: Duplicate Function Declarations

Multiple utility files in the `WorldGraph\\Utils` namespace may be loaded by the
plugin. Before adding a shared helper in `includes/utils/`, check for an
existing definition and guard duplicates with the established
`function_exists()` pattern.

## Build Rules

1. Read existing files before writing.
2. Keep new entities aligned with the Story Graph and its specification.
3. Make the smallest change that satisfies the requested behavior.
4. Validate after each code change with the narrowest relevant test.
5. Update the relevant specification when an architecture or API contract
   changes.
6. Ask before guessing when a specification is ambiguous.
7. Preserve working code unless its removal is explicitly required.
8. Treat applicable WordPress/headless parity as part of the same deliverable;
   update `headless/PARITY.md` when coverage or status changes.
9. For headless parity, prioritize complete working behavior and accessible
   controls over visual polish; an inert or simulated UI is not parity.

## VS Code Agent System

The project includes agent definitions in `.github/agents/` for use with VS Code
Copilot. These agents are specialized for different development tasks:

- `builder.agent.md` - Build and deployment tasks
- `code-reviewer.agent.md` - Code review and quality checks
- `connection-builder.agent.md` - REST API, MCP, and hybrid provider
  Connection implementation
- `feature-builder.agent.md` - Feature implementation
- `implementer.agent.md` - Implementation details
- `planner.agent.md` - Project planning and architecture
- `researcher.agent.md` - Research and investigation
- `thorough-reviewer.agent.md` - Comprehensive review and analysis

The scoped `.github/instructions/connections.instructions.md` file also points
agents editing the World Graph Studio plugin or repository documentation to
the canonical provider Connection contract.

See `AGENTS.md` in the project root for agent instructions and usage guidance.

## Testing and Quality Assurance

Testing documentation and utilities are maintained in `.github/testing/`. The
World Graph Studio plugin includes a `tests/` directory for unit and integration tests.

Key testing principles:
- Run tests locally through Docker Compose to ensure environment consistency
- Test narrowly after each code change
- Ensure all tests pass before merging changes
- Use the testing utilities and documentation in `.github/testing/` for setup and execution

## Reference Documents

- Story Graph: `about/Story_Graph_Specification.md`
- Content model: `about/Content_Model_Specification.md`
- REST API: `about/REST_API_Specification.md`
- Connection adapter development:
  `about/Connection_Adapter_Development_Specification.md`
- Delivery status: `about/Delivery_Status.md`
- Roadmap: `about/ROADMAP_World_Graph_Studio.md`
- AI Editor: `about/AI_Editor.md`
- Story Graph Intelligence: `about/Story_Graph_Intelligence.md`
- Script EDL Integration: `about/Script_EDL_Integration.md`
- CPT and SCF Schema: `about/CPT_and_SCF_Schema.md`
- Deployment: `about/Deployment_and_Connections.md`
- Headless parity: `headless/PARITY.md`

## Key Utilities and Components

### Story Graph and Relationships
- `includes/utils/relationship-graph.php` - Story graph relationship management
- `includes/utils/relationships.php` - Relationship utilities
- `includes/utils/story-search.php` - Semantic search within Story Graph
- `includes/utils/continuity-checker.php` - Continuity validation and analysis

### Generation and ComfyUI Integration
- `includes/utils/generation-log.php` - Generation history tracking
- `includes/utils/generation-batch.php` - Batch generation handling
- `includes/utils/generation-modality.php` - Media type and modality management
- `includes/utils/comfy-bootstrap.php` - ComfyUI initialization
- `includes/utils/comfy-cloud-mcp.php` - Cloud ComfyUI MCP integration
- `includes/utils/local-comfyui.php` - Local ComfyUI instance management
- `includes/utils/comfy-manifest.php` - ComfyUI node/workflow manifest
- `includes/connections/class-adapter-registry.php` - Connection adapter manifest, capabilities, and lazy loading
- `includes/connections/class-connection-test-service.php` - Generic Connection health-test lifecycle
- `includes/utils/connection-adapters.php` and `connection_tester.php` - Backward-compatible utility facades
- `includes/templates/class-template-manager.php` - Common Template provisioning scheduler/dispatcher
- `includes/templates/class-template-repository.php` - Idempotent provider Template persistence

### Data and Model Management
- `includes/utils/model_family.php` - Model family definitions and handling
- `includes/utils/template_bindings.php` - Template binding utilities
- `includes/utils/capability_sync.php` - Capability synchronization
- `includes/utils/class-asset-generator.php` - Asset generation utilities
- `includes/utils/connection_repository.php` - Connection configuration repository
- `includes/utils/helpers.php` - General helper functions
