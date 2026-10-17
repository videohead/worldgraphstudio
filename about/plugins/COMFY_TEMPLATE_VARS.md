# ComfyUI Template Variables

> **Delivery status:** delivered as a bundled feature plugin in WordPress. It is
> enabled by default and is a no-op for workflows without `TemplateVariable`
> nodes. Headless creator parity is authentication-blocked debt; see
> [PARITY.md](../../headless/PARITY.md).

This plugin lets local ComfyUI Templates built with
[ComfyUI-TemplateVars](https://github.com/boobkake22/ComfyUI-TemplateVars) run
from the Story Graph. The node pack must be installed on the ComfyUI
Connection.

Source: `wordpress/wp-content/plugins/worldgraph/plugins/comfy-template-vars/`.
Enable or disable it under **World Graph Studio → Plugins**. The state is stored
in the `worldgraph_comfy_template_vars_enabled` option.

## Behavior

The plugin acts only on API-format `workflow_json` and on `TemplateVariable`
nodes whose `name` and `value` are literals. Linked values belong to the graph
and are never rewritten.

| Variable | Run-time source |
| --- | --- |
| `prompt` | The composed, policy-bounded Story Graph prompt. The runner writes `{{prompt}}` into this variable before generic prompt-node discovery. A workflow that already contains `{{prompt}}`, such as a variable value `{{prompt}}, film still`, is left unchanged. |
| Any other name | A `textarea` run control keyed `template_var_<name>`, in the **Conditioning** group. Its displayed default is the workflow value. |

ComfyUI-TemplateVars expands placeholders in a single pass, so braces inside
the Story Graph prompt (e.g. `{soaked}`) are passed through literally.

Variable run controls follow the normal `run_controls` contract:

- They are part of the Template fingerprint.
- Values are validated (text, at most 1000 characters).
- They are saved through the Template, Project, and item run-default layers.

The shared sensitive-key denylist still applies. For example, a variable named
`api_token` or `negative_prompt` is not exposed and keeps its workflow value.
Variable names that would make a key longer than 64 characters are skipped.

## Story Graph bindings

Add a `template_variables` map to the Template's **Input Bindings JSON** to
fill variables from the source item:

```json
{
  "image": { "source": "featured_image" },
  "template_variables": {
    "lighting": { "source": "time_of_day" },
    "subject": "post_title"
  }
}
```

Supported sources:

- `post_title`
- `post_excerpt`
- `post_content`
- any World Graph Studio Details or SCF field name. Array values are joined with commas.

Resolved values are stripped of markup and whitespace-collapsed. They are
truncated to 1000 characters. They form the lowest runtime layer: Project,
item, and one-run values override them. Empty or unresolved sources leave the
workflow value in place. The defaults editor does not display bound values.

## Extension hooks

The plugin uses only these core filters, documented in
[Connection Adapter Development Specification §7.7](../Connection_Adapter_Development_Specification.md):

- `worldgraph_template_run_control_fields`
- `worldgraph_template_run_controls_apply_workflow`
- `worldgraph_template_bound_run_values`
- `worldgraph_comfy_prompt_placeholder_workflow`

## Reference workflow

The ComfyUI deployment ships `h3-sol-engine-templatevars_api`, a MiniMax H3
image-to-video workflow. Its prompt template is:

`{prompt}. Lighting: {lighting}. Camera: {camera}. Audio: {audio}. {style}`

Imported as a World Graph Studio Template, it gets these run controls:

- **Lighting**, **Camera**, **Audio** and **Style**
- the discovered sampling and output controls

The scene prompt comes from the Story Graph.
