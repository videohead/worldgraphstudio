# World Graph Studio Brand Guide v1.0

> Your ideas. Your assets. No credits needed.

## Brand Naming Standard

### Display Name

✅ **World Graph Studio**

Use the full three-word name, with title capitalization and spaces, in product
interfaces, documentation, marketing, and other reader-facing prose:

```text
World Graph Studio
```

Do not use these forms as the display name:

```text
WORLD GRAPH STUDIO
worldgraph
WorldGraph Studio
World GraphStudio
Worldgraph Studio
```

All-capital lettering may be used as a deliberate visual treatment in artwork,
but the underlying name remains **World Graph Studio**.

### Technical Namespace

Use `worldgraph` as the lowercase technical namespace for machine-facing
identifiers. It is not a shortened display name.

Examples include:

```text
Plugin slug and text domain: worldgraph
REST namespace: worldgraph/v1
Option and post-type prefix: worldgraph_
File format extension: .worldgraph.json
```

Follow platform casing conventions when an identifier requires them, such as
`WorldGraph` for PHP namespaces and `WORLDGRAPH_` for environment variables and
constants. In reader-facing prose, spell out **World Graph Studio** unless the
technical identifier itself is being documented.

### Product Terminology

- Use **Connection** (capitalized) for the World Graph Studio record that stores
  a provider configuration. Use lowercase “connection” only in its ordinary
  networking sense.
- Use **Connection adapter** for a provider integration registered with the
  adapter manifest; avoid hyphenating the noun in reader-facing prose.
- Use **specialist agent** as the general product term. The editor may label a
  specialist agent a **Creative Advisor** to emphasize its human-directed role;
  these names describe the same profile-driven feature.

---

# Brand Positioning

## Elevator Pitch

World Graph Studio is the extensible open-source studio for worldbuilding,
storytelling, and AI-powered creative production. It connects ideas, assets,
production decisions, portable interchange, provider Connections, and 50+
specialist agents through a Story Graph that creators control.

## Mission

Give creators control of their ideas, assets, models, and production workflow
through open, self-hosted technology.

## Vision

Become the open studio for building connected worlds and carrying them from an
idea into production.

---

# Brand Promise

World Graph Studio helps creators preserve story context across the entire creative lifecycle.

Instead of managing disconnected documents and assets, creators build stories within a shared Story Graph that powers scripts, storyboards, AI generation, production planning, and editing.

World Graph Studio does not sell creative credits or require a single model
provider. Optional hosted providers can still set their own prices, quotas,
licenses, and usage policies.

---

# Core Message

```text
Story First.
AI Assisted.
Creator Owned.
Portable by Design.
Built to Extend.
Open Source.
No Platform Credits.
```

---

# Brand Pillars

## Story First

Stories drive assets.

## Creator Ownership

Creators own their stories, workflows, prompts, and assets.

## Creative Control

Creators choose where the studio runs and which local or hosted models it can
reach.

## Open Source

Community-owned infrastructure.

## Structured Storytelling

The Story Graph is the source of truth.

## Extensible by Design

Formats, provider Connections, and specialist agents grow around the Story
Graph instead of replacing it.

---

# Tone of Voice

## We Are

- Thoughtful
- Creative
- Technical
- Practical
- Collaborative

## We Are Not

- Hype-driven
- Buzzword-heavy
- Generic AI marketing
- Human-replacement focused

---

# Visual Identity

## Primary Style

```text
Vintage Filmmaking
+
Production Notebook
+
Modern Story Graph
```

Visual inspiration:

- Storyboards
- Film reels
- Typewriters
- Corkboards
- Director's chairs
- Editing timelines
- Production planning walls

### Avoid

- Robot imagery
- Neon cyberpunk visuals
- Generic AI brains
- Floating holograms

---

# Color Palette

## Primary Colors

### Warm Ivory

```text
#F2EAD7
```

### Sepia

```text
#B68D40
```

### Dark Espresso

```text
#3A2A1D
```

### Charcoal

```text
#2B2B2B
```

## Accent Color

### Blueprint Blue

```text
#3F5D7D
```

Use for:

- Technical diagrams
- Architecture visuals
- Story Graph graphics

---

# Typography

## Headlines

Preferred:

```text
Oswald
Bebas Neue
League Gothic
```

## Body Text

Preferred:

```text
Inter
Source Sans Pro
Roboto
```

---

# Logo Design Guidelines

## Logo Philosophy

The World Graph Studio logo should communicate:

```text
Storytelling
+
Structure
+
Connection
+
Creative Production
+
Open Technology
```

The logo should feel like a connected creative production studio, not a generic AI startup.

---

## Logo Variants

### Primary Wordmark

```text
World Graph Studio
```

The wordmark uses the display name. Do not substitute the technical namespace
`worldgraph` in brand artwork.

### Icon + Wordmark

```text
[Icon] World Graph Studio
```

### Icon Only

```text
[World Graph Studio Icon]
```

Suitable for:

- Favicons
- App icons
- Social avatars
- Watermarks

---

## Primary Logo Concept

### Story Graph Mark

```text
     ●────●
      \  /
       ●
      / \
     ●──●
```

Combined with a subtle film frame motif.

Represents:

- Story entities
- Relationships
- Story Graph
- Creative continuity

---

## Secondary Logo Concept

### Director's Viewfinder

```text
Film Frame
+
Connected Nodes
+
Story Arc
```

Represents story planning and creative production.

---

## Avoid

Do not use:

- Robot heads
- Circuit brains
- Generic AI symbols
- Random gradients
- Generic film reels by themselves
- Simple clapperboards without Story Graph symbolism

---

## Clear Space

Define one clear-space unit as the capital-letter height of the wordmark. Keep
at least one unit free on every side of the complete logo. For the icon-only
mark, keep clear space equal to at least one quarter of the icon's height.

---

## Minimum Sizes

### Digital

```text
Wordmark: 120px minimum width
Icon: 32px minimum size
```

### Print

```text
1.25 inches minimum width
```

---

## Background Usage

### Light Background

Use Dark Espresso logo.

### Dark Background

Use Warm Ivory logo.

Avoid low-contrast combinations.

---

# Messaging Framework

## Problem

Storytelling workflows are fragmented.

## Solution

World Graph Studio connects storytelling, generation, production, and editorial workflows through the Story Graph.

## Benefit

Your ideas and assets remain connected, portable, and under your control.

---

# One-Sentence Description

> World Graph Studio is the extensible open-source studio for connected
> storytelling, portable production data, and AI-powered creative production,
> built around a Story Graph that creators control.

---

# GitHub Repository Description

```text
Open-source, self-hosted studio for portable story data, extensible AI agents, and provider-agnostic production in WordPress.
```

---

# Website Hero

## Headline

**Your ideas. Your assets. No credits needed.**

## Subheadline

The extensible open-source studio for worldbuilding, storytelling, and
AI-powered creative production. Import scripts, connect the tools you choose,
and grow a team of 50+ specialist agents without a World Graph Studio credit
meter.

---

# Brand Narrative

Most AI tools generate content.

World Graph Studio manages stories.

Most systems create files.

World Graph Studio creates connected knowledge.

Most platforms focus on outputs.

World Graph Studio focuses on context.

Most AI platforms meter creativity.

World Graph Studio lets creators choose the infrastructure.

## Deployment Promise

World Graph Studio runs on a WordPress.org-capable host or a local Docker/Lando
deployment. Helpful specialist agents require an API-connected LLM: a local
OpenAI-compatible server or a supported hosted API key. Generation can use
Comfy Cloud MCP, local ComfyUI HTTP workflows, fal MCP, ElevenLabs, Suno through
SunoAPI.org REST and AceData Cloud MCP,
[Seedance 2.5 through a manually configured third-party CyberBara REST
Connection](../plugins/SEEDANCE.md), or VideoDraft when the matching Connection
and Template are configured. The Seedance Connection uses a CyberBara API key.
Suno's REST and MCP services require distinct credentials. Browser-only AI
subscriptions are not a World Graph Studio server connection.

Use “no credits needed” to describe World Graph Studio itself and local/open
model workflows. Do not imply that optional hosted providers are free or lack
their own terms.
