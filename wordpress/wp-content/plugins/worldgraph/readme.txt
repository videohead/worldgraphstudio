=== World Graph Studio - Story Core ===
Contributors: videohead
Tags: storytelling, writing, pre-production, artificial intelligence, media
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Structure stories, production plans, relationships, assets, and optional AI-assisted media workflows in WordPress.

== Description ==

World Graph Studio - Story Core provides a structured story and production graph inside WordPress. It manages projects, story worlds, characters, locations, props, organizations, episodes, scenes, shots, sounds, assets, editorial artifacts, generation templates, and service connections as related WordPress content.

Core features include:

* Structured story, production, editorial, and asset content types.
* Relationships between story entities, plus sequencing and continuity tools.
* Search, summaries, analytics, and project interchange tools.
* Optional AI editor, filmmaking advisors, and queued media generation.
* Optional connections for generation, transcription, project synchronization, and headless cache revalidation.

Core Story Graph authoring does not require an AI provider, API key, or paid service. External integrations are disabled until an administrator configures or enables them. Administrators can test and manage Connections; afterward, authorized editors may initiate the AI, generation, synchronization, or download actions permitted by their WordPress capabilities. An enabled webhook may run automatically when relevant content changes.

= Required plugin =

World Graph Studio requires [Secure Custom Fields](https://wordpress.org/plugins/secure-custom-fields/) to store and edit its structured fields. Install and activate Secure Custom Fields before activating World Graph Studio.

= Source code =

The human-readable development source is available at [GitHub](https://github.com/videohead/storyos).

== Installation ==

1. Install and activate [Secure Custom Fields](https://wordpress.org/plugins/secure-custom-fields/).
2. Upload the `worldgraph` directory to `/wp-content/plugins/`, or install the plugin ZIP through Plugins > Add New > Upload Plugin.
3. Activate World Graph Studio - Story Core in the Plugins screen.
4. Open World Graph Studio > Setup and review the first-run settings.
5. Leave all provider settings empty for Story Graph-only use, or add only the optional connections you intend to use.
6. If you enable asynchronous generation, configure a reliable WP-Cron runner for your site.

Back up the WordPress database before upgrading an existing installation or importing a project.

== Frequently Asked Questions ==

= Is an AI service required? =

No. Projects, worlds, characters, locations, scenes, shots, relationships, continuity, editorial planning, import/export, and asset management work without an AI or generation provider.

= Why is Secure Custom Fields required? =

World Graph Studio uses Secure Custom Fields for its field definitions and structured content values. WordPress must be able to activate that dependency before World Graph Studio can initialize.

= Does the plugin contact external services by default? =

No. It does not send telemetry or make provider calls merely because the plugin is active. Calls occur after an administrator configures a service: administrators may test it, and authorized editors may use the actions allowed by their WordPress capabilities. An enabled headless webhook is then sent automatically when relevant content changes. An administrator-requested model installation also causes the configured downloader to contact Hugging Face.

= Do optional services cost money? =

The plugin does not collect service fees. Many hosted providers require their own account, API credential, subscription, credits, or usage-based payment. Provider pricing and availability can change; review the provider's current pricing before enabling it. A consumer subscription, such as ChatGPT, Claude, or Suno, does not necessarily include API access.

= Where are credentials stored? =

Credentials entered in WordPress are stored as authenticated AES-256-GCM ciphertext in the site's options or Connection records, using a key derived from the installation's WordPress authentication salts. Admin forms display a fixed mask rather than the saved value. Supported Connections may instead reference deployment-managed environment variables with an `env://` value; those references are also encrypted in the database. Protect WordPress administrator access, database exports, backups, and the WordPress salts. If the salts change, re-enter stored credentials.

= What happens to my data when the plugin is deactivated? =

Deactivation stops the plugin's hooks and scheduled generation worker, but it does not delete story content, media, settings, mappings, logs, or credentials.

= How do I delete plugin data? =

An administrator can open World Graph Studio > Purge Data and use the typed-confirmation purge. On multisite, this requires a network administrator and removes World Graph Studio data from every site in the current network, along with shared plugin network options and user metadata. It removes World Graph Studio story records, SCF values, Connections, encrypted credentials, Templates, Jobs, plugin taxonomies, settings, mappings, caches, scheduled events, generation logs, plugin upload artifacts, and Media Library items explicitly marked as generated by World Graph Studio. It preserves the active plugin, its code-defined SCF schema, and unrelated WordPress data. This is permanent and does not delete copies already held by external providers or secrets from server environment variables, so make a backup first and separately revoke credentials or delete provider-side data when required.

= Should generated content be reviewed? =

Yes. AI and media providers can return inaccurate, inappropriate, non-unique, or rights-restricted output. A human should review output and confirm the necessary rights and permissions before publishing or distributing it.

== Privacy ==

World Graph Studio stores story content, settings, encrypted provider credentials or credential references, mappings, job state, and a bounded, non-autoloaded generation log in the WordPress database. Generated or synchronized media may be imported into the WordPress Media Library. The plugin does not operate its own telemetry or remote data-collection service.

Site administrators decide which optional connections to configure. Before sending story content, personal data, confidential material, voices, likenesses, or media to a provider, obtain all required rights and consent and review that provider's current terms, privacy practices, retention rules, model-provider policies, and pricing. Data sent to an administrator-configured endpoint is controlled by that endpoint's operator after transmission.

The following disclosures describe the external connections included in version 1.0.0.

Hosted URLs listed below are the plugin defaults. Where a Connection exposes an editable endpoint, an administrator may replace that default; requests and the disclosed data then go to the configured endpoint and are governed by its operator's terms, privacy practices, retention, and pricing.

== External Services ==

= Comfy Cloud MCP =

When an administrator selects Comfy Cloud, tests or inspects the Connection, runs a generation Template, checks a job, or requests a provider-managed model download, the plugin contacts `https://cloud.comfy.org/mcp`. It sends MCP client metadata, authentication, tool and Template names, prompts, workflow or generation parameters, supplied reference-media values or URLs, model download URLs, and job identifiers as required by the selected tool. Comfy returns schemas, job state, generated-output information, and media locations.

A Comfy account and API key are required, and a paid subscription or usage charges may apply. [Service and MCP documentation](https://docs.comfy.org/development/cloud/mcp-server), [Terms](https://comfy.org/terms-of-service), [Privacy](https://comfy.org/privacy-policy).

= Comfy public template registry =

When an administrator loads or refreshes the published workflow catalog, including while preparing the default local ComfyUI workflow, the plugin retrieves the public index at `https://cloud.comfy.org/templates/index.json` and selected template JSON files below `https://cloud.comfy.org/templates/`. These read-only requests reveal ordinary HTTP connection data such as the WordPress server's IP address, user agent, request time, and requested index or template. They do not include a provider credential, Story Graph content, or generation prompt.

Reading the public registry normally requires no Comfy account or plugin payment, although normal hosting and bandwidth costs still apply. [Comfy documentation](https://docs.comfy.org/), [Terms](https://comfy.org/terms-of-service), [Privacy](https://comfy.org/privacy-policy).

= Local or administrator-hosted ComfyUI =

When an administrator configures a ComfyUI HTTP API or local MCP endpoint, tests readiness, generates media, or retrieves output, the plugin contacts that configured endpoint. It may send workflow JSON, prompts, negative prompts, model and generation settings, uploaded reference images or other media, and job identifiers; it also reads system, node, workflow, model, history, and output information. Data remains on infrastructure controlled by the endpoint operator only when that endpoint is genuinely local or self-hosted. Partner nodes, hosted MCP servers, and other remote URLs used by a workflow may contact additional services under their own policies.

Self-hosted ComfyUI does not require a third-party account or service fee, but the operator supplies the hardware, models, bandwidth, and maintenance. There is no separate privacy policy for requests to an operator's own instance. Review the operator's policies for any remotely hosted instance. [ComfyUI project](https://github.com/Comfy-Org/ComfyUI), [software license](https://github.com/Comfy-Org/ComfyUI/blob/master/LICENSE).

= fal =

When an administrator configures or tests fal, browses model schemas, submits a generation, or checks its status, the plugin contacts `https://mcp.fal.ai/mcp`. It sends MCP client metadata, authentication, model endpoint identifiers, prompts, generation inputs and parameters, supplied media or media URLs, and job identifiers. fal may route inputs to the model provider selected for the endpoint.

A fal account and API key are required; credit or usage charges apply according to fal and the selected model. [Service](https://fal.ai/), [Terms](https://fal.ai/legal/terms-of-service), [API Terms](https://fal.ai/legal/api-services), [Privacy](https://fal.ai/legal/privacy-policy).

= ElevenLabs =

When an administrator configures or tests ElevenLabs, the plugin retrieves model and voice information. When the administrator generates audio, it sends the requested text or audio prompt, selected voice and model identifiers, and applicable voice, style, duration, format, and generation settings to `https://api.elevenlabs.io`. ElevenLabs returns generated speech, dialogue, music, sound effects, or voice-preview audio.

An ElevenLabs account and API key are required; plan or usage charges may apply. Do not send a person's voice or likeness without the necessary permission. [Service](https://elevenlabs.io/), [Terms](https://elevenlabs.io/terms-of-use), [EEA/UK/Swiss Terms](https://elevenlabs.io/terms-of-use-eu), [Service-Specific Terms](https://elevenlabs.io/service-specific-terms), [Privacy](https://elevenlabs.io/privacy-policy).

= SunoAPI.org =

SunoAPI.org is a third-party API service operated by MIRA MUSE LLC; it is not represented here as an official Suno API. When an administrator tests a SunoAPI.org Connection, the plugin requests the account credit balance. When music or lyrics generation is requested, it sends authentication, a prompt or lyrics, title, style, model and generation options, and a signed WordPress callback URL to `https://api.sunoapi.org`; it later sends job identifiers while polling and downloads returned audio and cover-art URLs.

A separate SunoAPI.org account and API key or credits are required. A Suno consumer subscription does not authenticate this service. [Service](https://sunoapi.org/), [Terms](https://sunoapi.org/terms-of-use), [Privacy](https://sunoapi.org/privacy-policy).

= Ace Data Cloud Suno MCP =

Ace Data Cloud is a separate third-party Suno intermediary operated by Germey Technology, LLC. When an administrator configures or tests this transport, submits music or lyrics generation, or checks a task, the plugin contacts `https://suno.mcp.acedata.cloud/mcp`. It sends MCP client metadata, authentication, prompts or lyrics, title, style, model and generation options, and task identifiers. It receives task state and generated audio or metadata.

A separate Ace Data Cloud account, token, and prepaid or usage-based service package are required. Its token is not interchangeable with a SunoAPI.org key or Suno consumer subscription. [Service documentation](https://docs.acedata.cloud/en/mcp/suno), [Terms](https://docs.acedata.cloud/en/resources/terms), [Privacy](https://docs.acedata.cloud/en/resources/privacy).

= VideoDraft =

When an administrator configures or tests VideoDraft, the plugin requests its MCP tool catalog. When generation or project synchronization is requested, it sends authentication, prompts, tool and model choices, generation parameters, reference media or media URLs, and the selected project structure and fields needed for the requested push, pull, or update to `https://app.videodraft.ai/api/mcp`. VideoDraft returns schemas, project data, job state, and generated image, video, or audio information. VideoDraft may pass generation inputs to downstream model providers identified in its policies.

A VideoDraft account and personal access token are required; subscription, credit, storage, or model charges may apply. [Service](https://videodraft.ai/), [Terms](https://videodraft.ai/legal/terms-of-service), [Privacy](https://videodraft.ai/legal/privacy-policy).

= Descript =

Descript synchronization is administrator-initiated. Testing lists project metadata. Importing a transcript sends project and composition identifiers plus transcript format, speaker-label, marker, and timecode options to `https://descriptapi.com/v1`, then stores the returned transcript in WordPress. Exporting media sends a target project name or identifier, folder and team-access choices, composition definitions, WordPress media URLs, and a signed callback URL so Descript can create or update the requested project and report job status.

A Descript account and API token are required; plan limits, media minutes, AI credits, or other charges may apply. [Service and API documentation](https://docs.descriptapi.com/), [Terms](https://www.descript.com/terms), [Privacy](https://www.descript.com/privacy).

= OpenRouter =

When an administrator configures or tests OpenRouter, the plugin requests available video models. When video generation is requested, it sends authentication, the selected model, text prompt, duration, aspect ratio, resolution, and optional reference-image URLs or data to `https://openrouter.ai/api/v1`; it later sends job identifiers and downloads the completed videos. OpenRouter routes the request to a selected downstream model provider, whose terms, retention, training, and privacy practices may also apply.

An OpenRouter account, API key, and credits are required; model-specific usage charges apply. Review the terms for the selected downstream model as well. [Service](https://openrouter.ai/), [Terms](https://openrouter.ai/terms), [Privacy](https://openrouter.ai/privacy/).

= OpenAI =

When an administrator selects OpenAI and uses the AI Editor, an advisor, or compatible image generation, the plugin sends authentication, the selected model, system and user prompts, relevant Story Graph context, prior conversation messages, response limits, temperature, and, for images, the image prompt and requested size to OpenAI's API. OpenAI returns text or image output and usage metadata.

An OpenAI API account and API key are required; API usage is billed separately from a ChatGPT subscription. [API](https://platform.openai.com/docs/), [Services Agreement](https://openai.com/policies/services-agreement/), [Service Terms](https://openai.com/policies/service-terms/), [Privacy](https://openai.com/policies/privacy-policy/). API customer content is also governed by the applicable business agreement and data-processing terms.

= Anthropic =

When an administrator selects Anthropic and uses the AI Editor or an advisor, the plugin sends authentication, the selected model, system and user prompts, relevant Story Graph context, prior conversation messages, response limits, and temperature to `https://api.anthropic.com`. Anthropic returns generated text and usage metadata.

An Anthropic API account and API key are required; API usage is billed separately from a Claude consumer subscription. [API](https://docs.anthropic.com/en/api/overview), [Commercial Terms](https://www.anthropic.com/legal/commercial-terms), [Privacy](https://www.anthropic.com/legal/privacy). Commercial API content is also governed by the agreement and data-processing addendum incorporated into those terms.

= Administrator-configured OpenAI-compatible endpoint =

An administrator may provide a different OpenAI-compatible text or image API URL, including a local server or another hosted provider. When the administrator tests or uses it, the plugin sends authentication if configured, the selected model, prompts, relevant Story Graph context, prior messages, generation settings, and image size where applicable to that URL. Account requirements, costs, data location, retention, terms, and privacy are determined entirely by that endpoint's operator. No fixed service, Terms, or Privacy URL can be supplied for an administrator-selected endpoint.

= Celtx =

Celtx synchronization is administrator-initiated. When an administrator configures, tests, pulls, or pushes Celtx data, the plugin authenticates to `https://games-api.celtx.com/api` and sends or retrieves the configured project identifier and applicable project, episode, character, location, prop, scene, and shot/comment fields. This can include titles, descriptions, biographies, appearance details, scene summaries or script content, production attributes, and local-to-Celtx mappings.

A Celtx account, project, API credential, and eligible service plan or API access are required; charges may apply. [Service](https://www.celtx.com/), [Terms and Privacy](https://www.celtx.com/company/legal/).

= Configurable headless revalidation webhook =

When an administrator supplies a headless site URL and shared secret, the integration sends an automatic POST request to that site's `/api/revalidate` endpoint when relevant WordPress content is created, updated, trashed, or deleted. The request contains the affected content type, WordPress content ID, slug, optional story type, and the shared webhook secret in an HTTP header. It does not send the full post body. The endpoint may be local, administrator-operated, or hosted by any provider the administrator chooses.

World Graph Studio does not require an account or charge a fee for this webhook. Hosting costs and all Terms and Privacy policies are determined by the configured endpoint's operator, so no fixed external policy URLs apply.

= Hugging Face model download =

When an administrator explicitly requests installation of the default local ComfyUI checkpoint, the configured ComfyUI or MCP downloader retrieves `v1-5-pruned-emaonly-fp16.safetensors` from Hugging Face. The download request reveals ordinary HTTP connection data, such as the downloader's IP address, user agent, requested file, and time, but sends no Story Graph prompt or post content. The model file is installed on the configured ComfyUI system, not uploaded to WordPress.

The public file normally does not require a Hugging Face account or plugin payment, although the operator pays its own bandwidth, storage, and compute costs. Use of the model is separately subject to the `creativeml-openrail-m` license shown on its model card. [Model and license](https://huggingface.co/Comfy-Org/stable-diffusion-v1-5-archive), [Hugging Face Terms](https://huggingface.co/terms-of-service), [Hugging Face Privacy](https://huggingface.co/privacy).

== Changelog ==

= 1.0.0 =

* Initial WordPress.org release.
