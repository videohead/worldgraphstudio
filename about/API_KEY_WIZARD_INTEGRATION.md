# Setup wizard credential model

World Graph Studio's setup wizard configures optional generation and LLM
connections. Core Story Graph authoring, JSON import, and Markdown export do
not require an API key.

The canonical field-by-field guide is the
[Setup Wizard Guide](../wordpress/wp-content/plugins/worldgraph/documentation/SETUP_WIZARD_GUIDE.md).

## Delivered behavior

- Only administrators with `manage_options` can view, test, or save setup.
- Generation choices are sourced from the installed Connection adapter
  registry.
- The wizard can create or update one managed generation Connection and one
  managed LLM Connection.
- Local ComfyUI can be configured without a provider credential.
- Hosted fal, ElevenLabs, VideoDraft, and Comfy Cloud choices accept the
  credential needed by their adapter.
- The VideoDraft choice verifies its hosted MCP generation and Project tools
  and schedules live-schema image, video, and audio Template provisioning.
- The Suno API + MCP choice accepts two separate credentials: a SunoAPI.org
  REST key and an AceData Cloud MCP token. Testing requires both services to
  pass; saving schedules six transport-specific music, custom-music, and
  `text_to_lyrics` Templates.
- LLM configuration stores provider, endpoint, model, credential, maximum
  tokens, and temperature.
- Connection and LLM tests use the unsaved form values; testing and saving are
  separate actions.
- Saving marks setup complete and runs or schedules the relevant Template
  bootstrap.

The current form does not expose separate fallback-LLM credential fields.

## Where credentials live

Wizard-entered REST/provider credentials are persisted in the managed
Connection's `credential_reference` field. Suno's separate AceData Cloud token
is persisted in `mcp_credential_reference`; it must not be replaced by or
copied from the SunoAPI.org key. The primary LLM key is also represented by
the `worldgraph_ai_api_key` option for the AI Editor's current configuration
path.

For the primary LLM, deployments can define `WORLDGRAPH_AI_API_KEY` in
`wp-config.php`. fal and ElevenLabs Connections also support explicit
`env://FAL_KEY` and `env://ELEVENLABS_API_KEY` references. VideoDraft supports
`env://VIDEODRAFT_API_KEY`. Suno supports `env://SUNO_API_KEY` for REST and
`env://ACEDATACLOUD_API_TOKEN` for MCP.

Database backups can contain secrets. Keep backups, logs, screenshots, and
tracked environment files private.

## Useful checks

```bash
docker compose exec wordpress wp option get worldgraph_gen_connection_mode
docker compose exec wordpress wp option get worldgraph_ai_backend
docker compose exec wordpress wp option get worldgraph_ai_model
docker compose exec wordpress wp option get worldgraph_setup_complete
```

Do not print credential options in shared terminal logs. Use **World Graph
Studio → Connections** to test providers and review non-secret status fields.

See [Suno Integration](plugins/SUNO.md) for its credential, Template, callback,
polling, and result-import contract.
See [VideoDraft Connection and Sync](plugins/VIDEODRAFT.md) for its PAT,
generation, and structural Project-sync contract.
