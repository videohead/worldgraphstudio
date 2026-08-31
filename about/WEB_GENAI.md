# Web GenAI Platform Support

This page describes what a web-based generative AI platform can do with World Graph Studio today. World Graph Studio is the editorial and asset-management layer; it is not a compatibility layer for every provider API.

The entries below describe the delivered connector boundary. See
[Delivery Status](Delivery_Status.md) for the overall release status.

## Current Support

| Platform or route | World Graph Studio status | What users can do now |
| --- | --- | --- |
| Comfy Cloud MCP | Supported | Configure a ComfyUI Connection and credential reference, submit a compatible active Template, and let WordPress queue and poll jobs with WP-Cron. |
| Local ComfyUI through an MCP-capable client | Supported workflow | Connect the client to both World Graph Studio and the local `comfy-mcp` server. Run local workflows and register or upload the resulting assets in World Graph Studio. |
| fal MCP | Supported | Configure a fal API key, discover model schemas, provision text-to-image Templates, submit image jobs, poll them through WP-Cron, and import the returned media into WordPress. |
| ElevenLabs Generative Audio API | Supported | Configure an ElevenLabs API key, provision Templates for speech, dialogue, sound effects, music, or voice design, and import the generated audio into WordPress. |
| SunoAPI.org REST + AceData Cloud Suno MCP | Supported | Configure one `suno` Connection with separate REST and MCP credentials, provision transport-specific prompt-music, custom-music, and lyrics Templates, poll asynchronous tasks, import every final song, and retain generated lyric results. |
| midjourney-api.com REST + Ace Data Cloud MidJourney MCP | Supported | Configure one `midjourney` Connection with either intermediary credential or both, provision the matching Imagine text-to-image Templates, poll asynchronous tasks, and import every final image. These are third-party services, not an official Midjourney public API. |
| [Seedance 2.5 via CyberBara REST](plugins/SEEDANCE.md) | Supported | Manually configure one `seedance_25` Connection with a CyberBara API key, provision the two fixed text-to-video/image-to-video Templates, poll asynchronous tasks, validate the full bounded output list, and import every distinct final video. This is a third-party CyberBara route, not a direct ByteDance, BytePlus, Volcengine, or Dreamina API. |
| VideoDraft hosted MCP | Supported | Configure a VideoDraft PAT, discover live image, video, and audio tool schemas, provision Templates, poll asynchronous image/video jobs, and import completed media into WordPress. The optional bundled sync plugin also exchanges the shared structural Project subset. |
| OpenAI, Anthropic, or OpenAI-compatible LLM API | Supported for AI Editor | Configure an API credential and compatible base URL in **World Graph Studio > AI Settings**. A browser subscription alone is not sufficient. |
| Other web image/video platforms | External asset source | Generate in the provider's own web app, then upload or register the result in World Graph Studio with its prompt, model, source URL, and usage-rights information. |

## External Platforms and Extension Points

The following platforms are not direct World Graph Studio generation
connections in the current release:

- OpenAI Sora
- Runway
- Google Veo
- Kling
- Direct ByteDance, BytePlus, Volcengine, or Dreamina Seedance endpoints
- Adobe Firefly
- Amazon Bedrock or SageMaker video endpoints

Some of these services may be reachable through their own APIs, a third-party gateway, or a ComfyUI custom node. That does not make the route a World Graph Studio-supported connector. A supported connector must submit work, handle its lifecycle, retrieve downloadable artifacts, and preserve the asset metadata in WordPress.

The presence of `veo` or `nova_reel` in the connection form is an extension point and planning surface, not evidence that those providers execute successfully today.

## Recommended User Paths

### Path A: Managed generation in World Graph Studio

1. Create a Comfy Cloud account and API key.
2. Store the credential on the ComfyUI Connection for local evaluation, or use
   an environment reference such as `env://COMFYUI_API_KEY` in a managed
   deployment.
3. Configure a reliable host scheduler for `wp-cron.php`; local Lando users can run `lando wp-cron`.
4. Enable the Generation Engine and submit a workflow from World Graph Studio.
5. WordPress stores the generation record and polls Comfy Cloud through WP-Cron.

### Path B: Hosted generation through a supported provider

1. Create a fal, ElevenLabs, or VideoDraft account and credential; obtain both
   a SunoAPI.org key and a separate AceData Cloud token for Suno; or obtain
   the midjourney-api.com and/or Ace Data Cloud credential for the MidJourney
   transport you intend to use; or obtain a CyberBara API key for the reviewed
   Seedance 2.5 intermediary path.
2. In the Setup Wizard or **World Graph Studio > Connections**, choose the
   matching provider. Seedance 2.5 via CyberBara is configured on the
   Connections screen only.
3. Use `env://FAL_KEY`, `env://ELEVENLABS_API_KEY`,
   `env://VIDEODRAFT_API_KEY`, `env://OPENROUTER_API_KEY`, the paired
   `env://SUNO_API_KEY` and `env://ACEDATACLOUD_API_TOKEN` references, or the
   `env://MIDJOURNEY_API_KEY` and/or a service-scoped Ace Data Cloud token in
   production when credentials are supplied by the runtime. Use
   `env://CYBERBARA_API_KEY` for Seedance 2.5 via CyberBara.
4. Test the Connection so World Graph Studio can discover provider capabilities and create or update Templates.
5. Select a provider Template and submit the generation from World Graph Studio.

fal, Suno, MidJourney, Seedance 2.5 via CyberBara, and asynchronous VideoDraft
and OpenRouter image/video jobs are queued and polled through WP-Cron.
ElevenLabs and synchronous VideoDraft audio responses are imported directly.
Suno music completion
imports every final track; MidJourney completion imports every final image;
Seedance completion validates the full output list and imports every distinct
final video; the
`text_to_lyrics` modality retains normalized text results without pretending
they are media attachments.

These are first-party World Graph Studio execution paths. Provider model
availability, quotas, pricing, regions, and terms remain controlled by fal,
ElevenLabs, VideoDraft, OpenRouter, SunoAPI.org, midjourney-api.com, Ace Data
Cloud, and CyberBara.

### Path C: Local or third-party web generation

1. Create the image or video in the provider's web application or local ComfyUI setup.
2. Download the final artifact and retain the provider job URL or project reference.
3. Add the artifact to the WordPress media library or the relevant World Graph Studio asset.
4. Record the provider, model, prompt, source URL, generation date, and usage rights.

World Graph Studio can manage the story context, asset relationship, provenance, and downstream editorial work even when it did not submit the generation request.

## Connector Contract

A third-party extension can make another web platform a direct connector by
implementing its authentication, capability validation, submission, polling or
webhooks, cancellation where available, artifact download, and asset ingestion.
The delivered generation batch already dispatches ComfyUI, fal, ElevenLabs,
Suno, MidJourney, Seedance 2.5 via CyberBara, VideoDraft, and OpenRouter jobs
through their adapters; adding an arbitrary provider name or endpoint to a
Connection record does not create an executable adapter.

Before an extension labels another provider supported, its maintainer should
verify official API access and terms, implement the complete job lifecycle,
preserve permitted provenance, exercise retries and failures, and prove media
ingestion end to end. These are acceptance criteria for extensions, not a
current World Graph Studio roadmap commitment.

See [Deployment and Connections](Deployment_and_Connections.md) for credentials
and runtime setup, [VideoDraft Connection and Sync](plugins/VIDEODRAFT.md) for
generation and structural interchange, and [Suno Integration](plugins/SUNO.md)
for its distinct REST/MCP contract, and [MidJourney
Connection](plugins/MIDJOURNEY.md) for the two-intermediary image-generation
boundary. Keep provider availability, pricing,
regions, model limits, and terms of use with the provider's official
documentation.
