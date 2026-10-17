# World Graph Studio

> **Your ideas. Your assets. Your creative world. No credits needed.**

**An open-source, self-hosted studio for worldbuilding, connected storytelling, and AI-powered creative production.**

World Graph Studio brings the entire creative process into one workspace. Import and develop stories, build worlds, generate media, plan productions, and manage your assets — without scattering your work across disconnected prompts, proprietary project files, and competing (and expensive) platforms. Export your work any time to use  in other creative tools including video, image, and audio editors.

Built on WordPress and designed for open AI workflows, World Graph Studio helps Writers, Filmmakers, Game Creators, Worldbuilders, Showrunners, Narrative Teams, and Production Studios turn ideas into finished projects.

## Persistent Creative Memory

**Your Story Never Forgets.**

World Graph Studio stores your story as structured knowledge.

Characters, locations, props, scenes, storyboards, assets, and production notes remain available to AI assistants through the World Graph and AI-powered memory retrieval.

## One creative world. Everything connected.

At the heart of World Graph Studio is the **story graph**: a living network that connects the people, places, scenes, shots, sounds, storyboards, media, and creative decisions that make up your project.

Instead of treating every text file or script, prompt or asset as an isolated file, World Graph Studio analyzes your story and preserves the relationships between dicrete elements. Your generated character image remains connected to the character it represents. A storyboard remains connected to its scene and shots. Editorial decisions remain connected to the story behind them. Add decisive exploratory and expository information without disrupting the story graph.

The result is a creative workspace that understands not only what your assets are, but what they mean, and how to generate images, video and audio from your written ideas and images.

## From your first idea to the final edit

World Graph Studio gives your stories and production assets a unified home that IS a website that you can share with others (or keep private)

* Import and analyze scripts and stories with a Story Graph-aware import tool..
* Use AI tools where and when you want - as story analysis tools, production experts, or use them to write and enhance your story.
* Work with the more than 50 specialist AI agents for writing, continuity, production, story development, and creative analysis.
* Connect Claude, Codex, Cursor, VS Code, and other compatible MCP clients to
  permission-checked workflows for importing stories, reading and editing the
  Story Graph, reviewing projects, planning generation, and exchanging EDLs.
* Search across your entire fictional world and explore the relationships between story elements, use dramaturgical tools and find plot and continuity weaknesses to detect issues before they become production problems.
* Create shot lists, storyboard sequences, production plans, and editorial handoffs.
* Generate images, video, audio, dialogue, and music through configurable AI workflows, including no-cost local generation on your existing GPU hardware.
* Store generated media in a WordPress website alongside its prompts, provenance, and related story records.
* View character cards and sheets, locations, storyboards, props, etc.
* Manage footage, editorial files, reference material, and production assets in the context of the story they support.
* Drag and drop story sequence elements.
* Export to other tools or roundtrip with trusted creative tools, including a wide array of creative tools. Your assets and story remain yours throughout the workflow. Import elements back in as needed.

## Bring your existing work with you

You do not have to start over to start using World Graph Studio.

Import existing stories, napkin sketches, scripts, images, storyboards, and production data from the tools you already use. Supported workflows include:

* Plain text and structured story content
* PDF, ePub, Doc or any compatible text format
* Final Draft and Fountain screenplays
* Character and reference images
* Descript storyboards
* VideoDraft projects
* Google Web Stories
* Celtx projects
* Edit decision lists and editorial data from tools such as Adobe Premiere Pro, Final Cut Pro, Avid Media Composer, and DaVinci Resolve
* Production data used in game and real-time workflows, including Unity

Export and synchronization tools help move your work back into writing, editing, visualization, and production applications when you are ready for the next stage. Import and export as many times as you like and World Graph Studio will adapt your ideas.

## Use the best creative tool for every job - don't always use a hammer

Commercial creative tools are invested in keeping you in their tools, but they are not always the best tool for every job. World Graph Studio is open source and is not tied to a single AI company, model, or generation platform. Keep the tools you want. Don't use what you don't.

World Graph Studio CAN connect your project to thousands of generative models and creative workflows, and supported AI providers. Too many to list here (and you can build your own). Use World different models for different parts of your production while keeping the project itself connected.

For example:

* Do demonstration renders on your local system for free
* Send character work to LTX
* Generate action sequences with WAN
* Send location and background images to another API
* Produce dialogue with ElevenLabs
* Create music with Suno
* Generate text-to-video or animate one reference image with Seedance via third-party CyberBara
* Round trip editorial process with Adobe Premiere
* Run private and uncensored workflows using local models
* Add hosted services when they are useful and manage your costs
* Use brokers like LiteLLM and manage your costs across all APIs.

Connections can send work to specialized tools and bring the results back into your Story Graph. The provider may change; your project structure does not.

## Bring your own AI assistant through MCP

World Graph Studio publishes schema-described WordPress Abilities that a
compatible WordPress MCP Adapter can expose to an external assistant. With the
permissions you assign, that assistant can inspect or revise Story Graph
records, turn story text into a reviewable import, assess production and
editorial state, plan and run configured generation, review returned assets,
and preview, import, or export EDL data.

The boundary stays explicit: World Graph Studio does not bundle the transport
adapter, every client authenticates as a WordPress user, and existing
capability and record-level checks still apply. Paid generation remains a
confirmable action rather than an automatic side effect. World Graph Studio
also uses MCP in the other direction for selected provider Connections.

See [MCP Integration](../../docs/mcp-integration.md) for the complete surface
and setup requirements.

## Your creativity should not be metered

When you use local or open models, World Graph Studio does not require credits, impose a platform quota, or charge you each time you experiment.

**Your creativity is not metered.**

**Your content is not trapped.**

**Your workflow is not limited.**

You decide where WordPress runs, which services it can access, what stays private, and what might get published or made public.

Optional third-party providers may still have their own prices, quotas, licenses, moderation policies, and terms—but World Graph Studio does not add a credit system between you and your creative tools.

## Built to grow with your workflow

Creative technology changes quickly. World Graph Studio is designed to evolve with it.

### Exchange creative data

Import adapters translate external files and services into the shared Story Graph. Exporters create portable versions of that live production data for other tools.

This gives every integration the same foundation for identity, relationships, validation, and persistence—without forcing every tool to understand every other tool.

### Connections you can add or replace

Provider integrations register as modular Connection adapters. New AI services and production tools can be added without rebuilding the core application or restructuring your projects.

Each adapter handles the requirements of its provider while your Story Graph and Connection records remain stable.

### A specialist team that can keep expanding

The bundled AI team includes more than 50 focused creative and production roles. Each specialist is defined through a portable `.agent.md` profile discovered by WordPress.

Developers and creators can add new specialists, customize existing roles, and give them access to the same project context, permissions, and language-model layer. Specialists can be selected directly or routed automatically when matching keywords are configured.

For creators, this means more ways to bring work in, develop it, and send it forward.

For developers and integration partners, it means a stable foundation with focused extension points for adding new formats, providers, and creative expertise.

Explore the [Integration Catalog](../Integration_Catalog.md) for the current collection of plugins, Connection adapters, AI backends, and planned integrations.

## Open by design

### Free and open source

World Graph Studio is released under the GNU GPL v2-or-later. Components carrying their own notices retain their respective terms.

### Self-hosted

Run the application and its Story Graph in an environment you control. WordPress is the biggest web ecosystem on the planet, so lots of options.

### There Are No World Graph Studio credits

Local and open-model workflows do not require a commercial credit balance from World Graph Studio. You don't pay anything ever until you want to pay someone else.

### Model agnostic

Use supported local or hosted models—and change providers without rebuilding your project.

### Extensible

Add import and export formats, register new Connection adapters through WordPress hooks, and expand the specialist-agent team around one stable Story Graph.

### No project lock-in

World Graph Studio provides practical ways to move information into and out of the platform, always. This is your tool for improving your project, it's not owned by anyone else nor controlled by anyone else. It can work with other tools as you need.

### Privacy under your control

Keep your workspace private, share it with a team, or publish from it by configuring WordPress and your hosting environment appropriately.

### Your work remains yours

World Graph Studio does not claim ownership of your source material or generated assets. The licenses and terms of any models or external providers you choose, still apply.

### Human-directed creativity

AI specialists can propose, analyze, organize, and generate. You decide what belongs in the project.

## One studio for the world behind your work

World Graph Studio combines worldbuilding, story development, AI-assisted production, and asset management in a platform you can control and extend.

No costs for this tool - ever.
No proprietary creative suite.
No disconnected collection of prompts and files.

**Build worlds. Connect ideas. Generate anything. No credits needed.**
