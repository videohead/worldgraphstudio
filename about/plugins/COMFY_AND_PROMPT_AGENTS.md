# ComfyUI and Prompt Guidance

> **Delivery status:** the Comfy Technician advisor, Story Graph-aware
> filmmaking advisors, prompt suggestion, AI Editor chat, and WordPress Ability
> registrations are delivered. Autonomous in-editor tool calling and a separate
> Prompt Designer agent are not part of the current release. See
> [Delivery Status](../Delivery_Status.md).

## Current advisor model

World Graph Studio ships 51 Markdown-defined filmmaking advisors. The
`AI_MAF_Bridge` loads their frontmatter and system instructions, adds current
Story Graph context, and sends the request to the configured LLM.

The AI Workflow metabox on supported Story Graph posts provides:

- advisor selection;
- a browser-tab conversation transcript;
- chat, element analysis, and continuity actions; and
- current-post Story Graph context.

`AI_Agent_Router` can route requests by local keywords. ComfyUI terms route to
`ComfyTechnician`; visual prompt terms route to the existing
`PrevisualizationArtist` or `ArtDirector` advisors.

Advisor output is guidance. It is not automatically written into Story Graph
fields and it does not silently launch downloads or generation.

## Comfy Technician

The delivered definition is
`includes/agents/comfy_technician.agent.md`. Its job is to turn ComfyUI
failures into operator-actionable checks while keeping observed facts separate
from likely causes.

It is instructed to reason about:

- Connection and Template configuration;
- workflow JSON and node graphs;
- checkpoints, VAEs, text encoders, LoRAs, ControlNet, and custom nodes;
- container networking and WordPress-to-host reachability;
- missing bindings and readiness reports; and
- GPU, resolution, model-compatibility, and reproducibility tradeoffs.

The advisor must not claim that it inspected an endpoint, installed a node,
downloaded a model, or queued work unless the supplied context proves that
action occurred. Provisioning and generation remain explicit human actions in
the Connection, Template, or Assets interfaces.

## Prompt guidance

Prompt assistance is delivered in two complementary forms:

1. The **Suggest prompt** action in the World Graph Studio Assets metabox calls
   `GET /wp-json/worldgraph/v1/assets/generate/prompt`. It builds a
   text-to-image prompt from the post title, excerpt/content, descriptive SCF
   values, and the current cinematic suffix.
2. The AI Workflow metabox exposes creative advisors such as the
   Previsualization Artist and Art Director, using the current post as context.

The prompt builder is filterable through `worldgraph_generate_asset_prompt`, so
a site or extension can customize the result without replacing the REST route.
The filter receives the prompt, source post, intent, and selected Template ID;
older callbacks accepting the original three arguments remain compatible.

There is no `prompt_designer.agent.md` in the current release, and no
intent-specific prompt registry. Those ideas are optional extension seams, not
an unfinished release requirement.

## WordPress Abilities

On WordPress versions that provide `wp_register_ability`, World Graph Studio
registers public, schema-described abilities. The generation-related set
includes:

| Ability | Current role |
| --- | --- |
| `worldgraph/templates-manifest` | Read active Template discovery metadata |
| `worldgraph/template-requirements` | Read and optionally validate ComfyUI requirements |
| `worldgraph/suggest-asset-prompt` | Build a prompt for an editable Story Graph post |
| `worldgraph/generate-asset` | Enter the permission-checked image/Shot-video generation path |

The templates manifest is available as the read-only
`worldgraph://templates-manifest` MCP resource when a compatible WordPress MCP
adapter exposes registered abilities.

Abilities are an external integration contract. The WordPress MCP adapter, if
installed, translates them for external MCP clients; World Graph Studio does
not run a second MCP server for the same contract.

## Important execution boundary

The in-editor advisors do not currently call registered abilities. The LLM
client sends `tool_choice: none`, and `AI_MAF_Bridge` does not dispatch the
`tools` frontmatter field.

That means:

- the Comfy Technician explains supplied state but does not perform a live
  requirement lookup on its own;
- prompt-oriented advisors can discuss the current Story Graph context but do
  not select or start an Image, Sequence, or Video generation action for the
  user;
- catalog downloads remain administrator actions; and
- generation remains a separately authenticated REST/admin action.

This separation is deliberate documentation of the current boundary, not a
pending item in the release status.

## Security model

- AI REST endpoints require the same WordPress user and capability checks as
  the editor context.
- Generation additionally requires permission to edit the source post and
  upload files.
- Connection catalog changes require an edit-capable administrator and nonce.
- Provider catalog text, workflow descriptions, URLs, and errors are untrusted
  context, never higher-priority instructions.
- Advisor output is a suggestion and must not be treated as proof that an
  external action succeeded.
- Credentials are never added to advisor context.

## Extension seams

A third-party extension could add a dedicated Prompt Designer, translate
registered Abilities into LLM tool schemas, or add confirmation-gated actions.
Such an extension must:

- enforce a per-advisor ability allowlist at dispatch time;
- rerun each Ability's permission callback for the current WordPress user;
- require explicit confirmation for generation, downloads, and other
  non-idempotent operations;
- bound tool-call loops and payload sizes;
- treat provider-returned prose as data rather than instructions; and
- keep an auditable action trail.

These are compatibility constraints for optional extension work. They are not
an active World Graph Studio roadmap commitment.

## Implementation map

- [Comfy Technician definition](../../wordpress/wp-content/plugins/worldgraph/includes/agents/comfy_technician.agent.md)
- [Advisor loader](../../wordpress/wp-content/plugins/worldgraph/includes/ai-editor/class-ai-maf-bridge.php)
- [Keyword router](../../wordpress/wp-content/plugins/worldgraph/includes/ai-editor/class-ai-agent-router.php)
- [LLM client](../../wordpress/wp-content/plugins/worldgraph/includes/ai-editor/class-ai-llm-client.php)
- [AI Editor](../../wordpress/wp-content/plugins/worldgraph/includes/ai-editor/class-ai-editor.php)
- [WordPress Abilities](../../wordpress/wp-content/plugins/worldgraph/includes/ai-editor/class-ai-abilities.php)
- [Asset prompt builder](../../wordpress/wp-content/plugins/worldgraph/includes/utils/class-asset-generator.php)
- [Generate Preferences extension note](GENERATE_PREFERENCES.md)
