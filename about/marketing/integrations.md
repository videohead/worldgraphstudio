# Integrations

> **Use the right tool for each part of the work. Keep the project connected.**

World Graph Studio is the home for your story, production plan, and creative
assets. Its integrations bring existing work into that shared structure, send
specific jobs to outside tools, and return useful results to the project.

You do not need every integration. A writer may only need story import and
export. A small film team may add image, voice, music, and video services. A
studio can connect its own local tools or build an adapter for a service it
already uses.

The important part is that the Project, Characters, Locations, Scenes, Shots,
and Assets stay connected even when the tools around them change.

## Bring stories and projects in

### World Graph Studio project files

World Graph Studio JSON carries a structured project from one installation to
another. It preserves the records and relationships that make up the Story
Graph. Import and export do not require an outside service.

**Place in the platform:** project backup, transfer, and structured exchange.

### Final Draft

Import an FDX screenplay and turn its supported structure into World Graph
Studio records. The imported material joins the same Story Graph used for
worldbuilding, planning, shots, media, and export.

**Place in the platform:** screenplay import.

### Text, Markdown, Fountain, RTF, PDF, EPUB, DOCX, and ODT

Bring in a manuscript, treatment, script, or other text-bearing document. With
a language-model Connection configured, World Graph Studio prepares a draft
Story Graph for review. Nothing is added to the project until you approve the
preview.

Fountain and Final Draft are supported through this reviewed document-import path. PDFs need a
searchable text layer; scanned pages need OCR first.

**Place in the platform:** turn existing writing into a connected project.

## Write, review, and develop

### External AI assistants through MCP

Use Claude, Codex, Cursor, VS Code, or another MCP client with a separately
installed compatible WordPress MCP Adapter. World Graph Studio publishes typed,
permission-checked abilities for Story Graph discovery and editing, story
preview/import, project and editorial review, generation planning and runs,
asset review, contextual resources and prompts, and EDL exchange.

The MCP client authenticates as a WordPress user, so WordPress capabilities and
record-level permissions remain in force. The transport adapter is not bundled,
and operations that write data or spend provider credits should remain under
human confirmation.

**Place in the platform:** bring an assistant into a controlled end-to-end
creative workflow without giving it unrestricted database or provider access.

### OpenAI

Use an OpenAI API model for the editor, specialist advisers, and reviewed story
document import. API access and billing are separate from a ChatGPT
subscription.

**Place in the platform:** writing support, story analysis, and project-aware
advice.

### Anthropic

Use an Anthropic model through its API for the same project-aware writing,
analysis, and import work.

**Place in the platform:** writing support, story analysis, and project-aware
advice.

### LiteLLM

Connect World Graph Studio to a local or hosted LiteLLM proxy. This is useful
when a person or team wants one managed route to several language-model
providers.

**Place in the platform:** a shared gateway for writing and analysis models.

### OpenAI-compatible services and local models

Connect a service that provides a compatible chat endpoint, including one
running on hardware you control. This gives teams another way to choose where
story material is processed. Use Ollama, vLLM, Llama.cpp, and other locally hosted options.

**Place in the platform:** local or hosted writing and analysis without tying
the project to one model company.

## Create images and video

### ComfyUI

Run compatible ComfyUI workflows on your own machine, on a private server, or
through Comfy Cloud. World Graph Studio can send a Template-backed job, follow
its progress, and bring finished media back into WordPress.

Local ComfyUI uses its HTTP API. An optional MCP service is a separate process;
ordinary ComfyUI on port 8188 is not itself an MCP server.

**Place in the platform:** flexible local or hosted image and video workflows.

### fal

Connect to fal through its MCP service. World Graph Studio reads the available
model information, prepares supported image Templates, submits jobs, and
imports the results.

**Place in the platform:** hosted image generation across supported fal models.

### MidJourney

Create images through either midjourney-api.com or the Ace Data Cloud
MidJourney MCP service. These are separate third-party services with separate
credentials. Midjourney does not provide the official public API used here.

World Graph Studio supports the reviewed Imagine path and imports the final
images. Other tools advertised by the MCP service are not automatically made
available.

**Place in the platform:** third-party MidJourney image generation tied back to
Characters, Locations, Scenes, Shots, or other records.

### Higgsfield

Generate images and image-led video through three reviewed Higgsfield
operations. Generation uses the Higgsfield REST service. A separate Higgsfield
account connection is used to inspect its MCP catalog, not to run arbitrary
remote tools.

**Place in the platform:** selected character-image and image-to-video work.

### Seedance 2.5 through CyberBara

Create a video from text or animate a reference image with Seedance 2.5 through
the CyberBara API. This is a third-party connection; it is not a direct
ByteDance, BytePlus, Volcengine, or Dreamina integration.

**Place in the platform:** short text-to-video and image-to-video jobs.

### OpenRouter

Send text-to-video, image-to-video, or reference-led video work to a supported
video model available through OpenRouter. World Graph Studio follows the job
and imports the completed video.

**Place in the platform:** one Connection for choosing among supported hosted
video models.

## Create voices, sound, and music

### ElevenLabs

Create speech, dialogue, sound effects, music, and voice-design previews with
supported ElevenLabs models and voices. Returned audio is imported into the
project for review and use.

**Place in the platform:** spoken performance and sound creation connected to
Scenes, Characters, and production Assets.

### Suno

Create songs, custom music, and lyrics through SunoAPI.org or the Ace Data
Cloud Suno MCP service. These are independent third-party services and use
different credentials; a Suno website subscription does not replace either
API credential.

**Place in the platform:** music and lyric creation connected to the story and
its production records.

## Generate media and exchange projects

### VideoDraft

VideoDraft has two roles in World Graph Studio. Its generation Connection can
create supported images, video, and audio. Its optional synchronization plugin
can also push and pull the shared structural part of a Project, with a preview,
checkpoints, conflict checks, and saved record mappings.

**Place in the platform:** media generation and two-way project exchange.

## Move work into editorial systems

### EDL tools

World Graph Studio includes CMX-style text and SMPTE 436m XML parsing,
timecode handling, preview and confirmation, and EDL generation from project
or episode timeline information. Imported edit information is stored as an
Editorial Artifact.

EDL is an exchange format rather than a direct account connection. Files can
move between World Graph Studio and tools that support the same format, subject
to the details each editing application preserves.

**Place in the platform:** handoff between story and shot planning and the edit.

## Bring in assets made elsewhere

Not every creative tool needs a direct Connection. Images, video, audio, and
reference material made in another application can be added through the
WordPress Media Library and attached to an Asset record. Source, prompt, model,
rights, and other provenance can travel with the media inside the project.

**Place in the platform:** the general route for tools without a dedicated
adapter.

## What is not a current integration

The repository also contains early or incomplete work for Celtx, Descript, and
Google Web Stories. These are useful directions for future development, but
they are not working release integrations today. Google Gemini, Veo, and Nova
Reel appear as provider placeholders only; they do not have complete adapters.

Listing that work in the repository does not mean a user can connect the
service. A finished integration must handle sign-in or credentials, check the
service, send and track supported work, handle failures, and bring the result
back into World Graph Studio safely.

## How integrations fit together

Every current integration serves one or more of four jobs:

| Job | What it does | Current examples |
| --- | --- | --- |
| Bring work in | Turns an existing file or outside project into Story Graph records | Text, Final Draft FDX, supported story documents, VideoDraft sync, EDL |
| Help develop the work | Uses selected language tools with relevant project context | OpenAI, Anthropic, LiteLLM, OpenAI-compatible and local services |
| Make production assets | Sends a defined job out and returns media to the project | ComfyUI, fal, MidJourney bridges, Higgsfield, Seedance through CyberBara, OpenRouter, ElevenLabs, Suno, VideoDraft |
| Send work forward | Creates a portable file or updates another supported project | World Graph Studio JSON, Markdown screenplay and storyboard export, VideoDraft sync, EDL |

Connections hold the details needed to reach an outside service. Templates
define the specific jobs that service is allowed to run. Assets hold the media
that comes back. The Story Graph keeps those pieces related to the creative
work they support.

External services may charge for use and may apply their own limits, licenses,
moderation rules, and privacy terms. World Graph Studio does not add its own
credit system. You choose which Connections to set up, and the core workspace
continues to work without them.

For exact setup requirements and current technical limits, see the
[Integration Catalog](../Integration_Catalog.md) and
[Delivery Status](../Delivery_Status.md).
