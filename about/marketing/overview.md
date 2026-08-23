# World Graph Studio

> Your ideas. Your assets. No credits needed.

**The open-source, extensible studio for connected storytelling, portable
production data, and AI-powered creative production.**

World Graph Studio is a free, open-source, self-hosted creative production
platform for filmmakers, game creators, scriptwriters, video producers, and
anyone building a connected fictional world.

Built on WordPress and designed for open AI workflows, it brings worldbuilding,
story analysis, asset generation, and production planning into one system.
Characters, places, scenes, shots, sounds, storyboards, media, and editorial
decisions remain connected through a Story Graph instead of being scattered
across prompts and proprietary project files.

That connected model also makes World Graph Studio unusually adaptable. New
script formats can feed the same importer, new services can register as
Connection adapters, and new production specialists can join a profile-driven
agent roster without replacing the core application.

## Your creative work stays connected

World Graph Studio gives you a durable home for both ideas and assets:

- Build rich Story Graphs and connected worlds.
- Develop projects, characters, locations, props, scenes, shots, sounds, and
  storyboards as structured content.
- Analyze stories with a Story Graph-aware AI Editor and 50+ specialist agents
  defined in extensible profiles.
- Check continuity, search across story entities, and explore relationship
  analytics.
- Configure template-backed image, video, and audio generation workflows and
  retain returned media with provenance in WordPress.
- Manage video, editorial, and other production assets alongside the story
  records that give them meaning.
- Create shot lists, storyboard sequences, and editorial handoffs.
- Import World Graph Studio project JSON or Final Draft FDX and export Markdown
  screenplays or storyboards.
- Reuse the included EDL parsing, timecode, and format-generation code when
  building an editorial adapter.
- Generate through VideoDraft and optionally push or pull the shared
  structural Project subset with preview and conflict checks.
- Inspect experimental Descript exchange source for separate transcript-import
  and bound-media-export directions; it is not yet a marketed release workflow.
- Connect ComfyUI, Comfy Cloud, VideoDraft, and supported AI providers without
  making one provider the owner of your project.

The former blanket hold on additional script formats is closed for the current
release. Final Draft FDX import now joins JSON import, Markdown export, and
VideoDraft structural sync; formats that were not accepted into this scope are
extension opportunities rather than missing release work. Fountain, Celtx,
EDL admin, Descript, and Google Web Stories sources remain cataloged with their
current scaffold or prototype status rather than marketed as operational.

## Built for an expanding creative toolchain

World Graph Studio turns extensibility into a product advantage:

- **One interchange foundation.** Import adapters translate external files or
  services into the canonical Story Graph and reuse its validation, identity,
  relationship, and persistence rules; exporters derive portable projections
  from live Story Graph records. That is why FDX was added quickly and why a
  hardened Fountain adapter can reuse the same pipeline without creating a
  parallel project model.
- **Replaceable Connection types.** Provider integrations register their
  metadata, conditional loader, and optional guided setup through a
  filterable adapter manifest. Each integration supplies its provider-specific
  behavior while the Story Graph and Connection records stay stable.
- **A growing specialist team.** The 50+ bundled agents are portable
  `.agent.md` profiles discovered by WordPress. New focused roles are directly
  selectable and reuse the same context, permissions, and LLM layer; automatic
  routing can be added when router keywords are configured.

For creators, that means more ways to bring work in, move it forward, and take
it out. For developers and integration partners, it means a stable core with
small, focused surfaces for adding formats, providers, and expertise. The
[Integration Catalog](../Integration_Catalog.md) provides a table view of every
bundled plugin, executable Connection adapter, AI backend, and registered
extension placeholder.

## Creative control without a platform meter

When you use local or open models, World Graph Studio lets you generate and
iterate without buying World Graph Studio credits, accepting a platform-level
quota, or moving your project into a proprietary creative suite.

Your creativity is not metered by World Graph Studio.

Your content is not trapped in a World Graph Studio cloud.

Your workflow is not limited to a single model provider.

You decide where WordPress runs, which services it can reach, which work stays
private, and which work gets published. Optional hosted providers can still
have their own prices, quotas, licenses, moderation rules, and terms.

## Core principles

- **Free and open source.** The project's default license is GNU GPL
  v2-or-later; components with their own notices retain those terms.
- **Self-hosted.** Run the application and Story Graph in an environment you
  control.
- **No World Graph Studio credits.** Local and open-model workflows do not
  require a commercial credit balance from this project.
- **Model agnostic.** Use supported local or hosted connections and change
  providers without rebuilding the Story Graph.
- **Extensible by design.** Add focused format adapters, register provider
  Connection adapters through WordPress hooks, and grow the specialist team
  through profile files around one stable Story Graph.
- **No project lock-in.** WordPress data, REST endpoints, JSON and FDX import,
  Markdown export, and VideoDraft structural Project sync provide practical
  paths into and out of the system. Experimental and scaffold integrations
  remain visible for contributors without being presented as operational.
- **Privacy under your control.** Keep a site private or publish from it by
  configuring WordPress and its hosting appropriately.
- **Creator ownership.** World Graph Studio does not claim ownership of your
  source material or generated assets; model and provider licenses still
  apply.
- **Human-directed creativity.** Specialist agents propose, analyze, and
  generate; creators decide what becomes part of the project.

World Graph Studio combines story development, worldbuilding, AI-assisted
production, and asset management without turning the creative process into a
metered subscription.

**Build worlds. Connect ideas. Generate anything. No credits needed.**
