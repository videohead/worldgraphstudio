# World Graph Studio AI Editor

> Story Graph-aware assistance, inside the creative workspace.

**Status: delivered and complete.** The Gutenberg interface, classic
Story Graph editor workflow, REST routes, LLM client, specialist advisors,
context builder, and WordPress Abilities are implemented in the current
repository. Provider credentials and endpoint availability are installation
configuration, not unfinished product work.

## Purpose

The AI Editor lets creators analyze, discuss, and develop a Story Graph entity
without leaving WordPress. It combines approved project context with one of
more than 50 specialist creative advisor profiles and a configured local or
hosted LLM.

The editor is human-directed:

- AI responses are labeled as generated suggestions.
- The server decides which Story Graph context is sent.
- Suggestions do not silently change canonical records.
- Gutenberg insertion or replacement requires an explicit creator action.
- The current browser session holds chat history; the chat route does not
  persist it as project content.

World Graph Studio remains useful for editing, search, continuity, production
planning, and asset organization when no LLM is configured.

## User surfaces

The delivered experience includes:

- A Gutenberg plugin sidebar for chat and context-aware actions.
- A story-element workflow metabox for supported classic editors.
- Specialist advisor selection and automatic routing.
- Chat, analysis, draft generation, and continuity actions.
- Context inspection for the current WordPress record.
- Explicit insert-as-blocks, insert-as-HTML, insert-as-text, and replace-content
  controls in Gutenberg.
- AI connection, model, fallback, rate-limit, and cache configuration through
  the World Graph Studio setup surface.
- Health, settings, and advisor discovery endpoints.

## Architecture

```text
Gutenberg sidebar, classic metabox, or authorized API client
                         |
                         v
               worldgraph/v1/ai routes
                 or WordPress Ability
                         |
             +-----------+-----------+
             |                       |
             v                       v
      Capability checks       Context Builder
                              Story Graph + SCF
             |                       |
             +-----------+-----------+
                         v
              Agent router / advisor skill
                         |
                         v
                    LLM Client
          local, OpenAI, or Anthropic endpoint
                         |
                         v
              Structured, labeled response
```

WordPress owns authentication, permissions, system instructions, context
assembly, request validation, error normalization, and user-visible controls.
The configured provider receives only the prompt, bounded conversation, and
approved context needed for the operation.

## Implementation map

The module lives under
`wordpress/wp-content/plugins/worldgraph/includes/ai-editor/`.

| Component | Responsibility |
| --- | --- |
| `class-ai-editor.php` | Module bootstrap, settings, admin integration, Gutenberg assets, and classic-editor workflow |
| `class-ai-editor-rest.php` | Permission-aware `worldgraph/v1/ai/*` routes |
| `class-ai-context-builder.php` | Bounded post, character, scene, project, and relationship context |
| `class-ai-llm-client.php` | Local/hosted requests, fallback, caching, rate limiting, health, and normalized errors |
| `class-ai-agent-router.php` | Advisor selection when the creator does not select one |
| `class-ai-agent-skills.php` | Loading and enabling specialist advisor definitions |
| `class-ai-maf-bridge.php` | Shared execution bridge between advisors and the configured LLM |
| `class-ai-image-client.php` | Configured AI image request support used by editor workflows |
| `class-ai-abilities.php` | Typed WordPress tools, resources, prompts, schemas, and MCP metadata |

The user interface lives under
`wordpress/wp-content/plugins/worldgraph/assets/ai-editor/` and includes the
Gutenberg sidebar and classic story-element workflow scripts and styles.
Advisor profiles live under
`wordpress/wp-content/plugins/worldgraph/includes/agents/`.

## Story Graph context contract

Context may include the current entity, its registered SCF values, relevant
relationships, project, story world, and concise neighboring records. Context
construction must:

- Verify that the current user can access the requested record.
- Prefer registered content types, fields, taxonomies, and canonical
  relationships over unregistered metadata.
- Include only relationships relevant to the requested action.
- Bound serialized context before calling a provider.
- Exclude credentials, nonces, private settings, unrelated users, and
  unauthorized entities.
- Preserve stable source IDs so a response can identify the evidence it used.

The Story Graph remains authoritative. An LLM can explain or propose a change;
it cannot redefine stored facts merely by returning different text.

## LLM connections

The delivered client supports:

| Mode | Use |
| --- | --- |
| OpenAI-compatible | Local or hosted endpoints such as Ollama, llama.cpp, vLLM, LM Studio, OpenRouter, or another compatible API |
| OpenAI | OpenAI chat-completions API |
| Anthropic | Anthropic messages API |
| Dual/fallback | A primary configured endpoint with an optional fallback backend |

An installation configures its backend, endpoint, model, token limit,
temperature, request rate, cache lifetime, and optional fallback. Deployment
constants can supply protected credentials:

- `WORLDGRAPH_AI_API_KEY`
- `WORLDGRAPH_AI_FALLBACK_API_KEY`
- `WORLDGRAPH_AI_IMAGE_API_KEY`

Browser-only consumer subscriptions do not supply API access. Hosted services
can impose their own pricing, quotas, moderation, and terms. Local or open
models can be used without buying World Graph Studio credits.

See [Deployment and Connections](Deployment_and_Connections.md) for operating
configuration.

## REST API

The delivered routes use the `worldgraph/v1` namespace:

| Method | Route | Purpose |
| --- | --- | --- |
| `POST` | `/worldgraph/v1/ai/chat` | Chat with an advisor using optional post context and bounded prior turns |
| `POST` | `/worldgraph/v1/ai/analyze` | Return structured observations about supplied content and context |
| `POST` | `/worldgraph/v1/ai/generate` | Return draft text, prompts, or another requested suggestion |
| `POST` | `/worldgraph/v1/ai/continuity` | Run the shared continuity workflow for a Story Graph entity |
| `GET` | `/worldgraph/v1/ai/context` | Return approved context for the requested entity |
| `GET` | `/worldgraph/v1/ai/agents` | List enabled specialist advisors |
| `GET` | `/worldgraph/v1/ai/settings` | Return non-secret client configuration |
| `GET` | `/worldgraph/v1/ai/health` | Report configured backend health without exposing credentials |

The chat route accepts a prompt, optional post ID, advisor, action, and up to
20 prior `user` or `assistant` messages. Client-supplied `system` messages
are rejected; the server owns its system instructions and Story Graph context.

Every route validates arguments and applies WordPress capability checks. Error
responses are normalized and must not reveal authorization headers, API keys,
or unrestricted provider payloads.

## WordPress Abilities

When the host WordPress installation provides the Abilities API, World Graph
Studio registers typed capabilities under the `worldgraph` namespace.
LLM-dependent abilities are registered when a valid LLM endpoint is
configured.

### AI tools

- `worldgraph/chat`
- `worldgraph/analyze`
- `worldgraph/generate`
- `worldgraph/continuity-check`

### Context resources

- `worldgraph/post-context`
- `worldgraph/character-context`
- `worldgraph/scene-context`

### Template and asset abilities

- `worldgraph/templates-manifest` — read-only discovery of published, active
  generation templates.
- `worldgraph/template-requirements` — inspect and optionally validate a
  template's ComfyUI requirements.
- `worldgraph/suggest-asset-prompt` — build a source-aware asset prompt.
- `worldgraph/generate-asset` — queue an authorized story-aware image or Shot
  video request and optionally link its result to the source.

### Agent workflow tools

- `worldgraph/decompose-story` and `worldgraph/decompose-story-upload` — create
  a canonical import preview from direct text or an existing upload.
- `worldgraph/import-story` — explicitly confirm and populate the Story Graph.
- `worldgraph/content-schema`, `worldgraph/list-entities`,
  `worldgraph/get-entity`, `worldgraph/create-entity`, and
  `worldgraph/update-entity` — inspect or revise post and SCF data through the
  existing REST controller permission boundary.
- `worldgraph/review-project` and `worldgraph/add-review-note` — inspect and
  annotate production/editorial state.
- `worldgraph/plan-end-to-end-generation`,
  `worldgraph/run-end-to-end-generation`, and `worldgraph/review-generation` —
  preview, confirm, monitor, and review a durable full-story generation batch.
- `worldgraph/preview-edl-import`, `worldgraph/import-edl`, and
  `worldgraph/export-edl` — operate the enabled EDL extension without its UI.

### Prompt resources

- `worldgraph/story-review-prompt`
- `worldgraph/continuity-prompt`

Each ability declares input and output schemas, a permission callback, and MCP
metadata describing whether it is a tool, resource, or prompt. Read/write,
destructive, and idempotency annotations describe the actual behavior. An
installed WordPress MCP adapter may expose these abilities to compatible
clients; it does not change their WordPress permission boundary. MCP clients
must authenticate as a WordPress user, preferably through a dedicated account
and Application Password with least-privilege capabilities.

## Advisor model

Advisor profiles cover writing, directing, cinematography, art, camera,
lighting, sound, editorial, visual effects, production, locations, costumes,
hair and makeup, stunts, and other production disciplines.

A creator can select an enabled advisor or let the router choose one from the
prompt. The selected profile contributes domain instructions; it does not
create a separate agent server, data store, or permission system. All advisors
reuse the same context, LLM, REST, and WordPress security services.

## Security and privacy

- Require an appropriate WordPress capability for every route and Ability.
- Verify nonces for browser-originated requests.
- Sanitize prompts and parameters and escape rendered output.
- Treat AI output as untrusted until a creator explicitly uses it.
- Keep keys in protected options or deployment constants, never JavaScript.
- Keep credentials out of prompts, context resources, logs, history, and
  response bodies.
- Bound prompt length, chat history, context, response size, request time, and
  per-user request rate.
- Do not expose private entities to a user who cannot read or edit them.

The creator controls WordPress deployment and content visibility. A configured
provider still receives the approved material sent to it and applies its own
data handling terms.

## Failure behavior

- If no LLM is configured, normal WordPress and Story Graph features continue
  to work.
- If an endpoint is unreachable, the client returns a sanitized error and can
  use the configured fallback when enabled.
- If no advisor is enabled, the request fails clearly instead of inventing an
  execution path.
- If context is missing or unauthorized, the request is rejected or proceeds
  without that context as appropriate.
- Caching and rate limiting use WordPress-owned state and do not expose
  credentials.

## Delivered acceptance contract

The AI Editor is complete when an authorized creator can open the supported
WordPress editing surfaces, inspect and send approved context, use an enabled
advisor through a configured backend, receive a labeled response, explicitly
insert or replace content, invoke continuity and analysis actions, and discover
the registered Ability resources available on that installation. That contract
is implemented in the current release.

Browser, accessibility, security, caching, and provider-compatibility work may
continue as normal maintenance. It is not represented as a pending product
phase.

## Extension points

Additional advisor profiles, typed editor actions, provider adapters, and
context resources can extend the module when they reuse WordPress permissions
and the canonical Story Graph. They are not active roadmap commitments.

## Related documents

- [Delivery Status](Delivery_Status.md)
- [Product Requirements](World_Graph_Studio_PRD.md)
- [Architecture](World_Graph_Studio_Architecture.md)
- [Agent Architecture](Agent_Architecture.md)
- [Story Graph Intelligence](Story_Graph_Intelligence.md)
- [REST API Specification](REST_API_Specification.md)
