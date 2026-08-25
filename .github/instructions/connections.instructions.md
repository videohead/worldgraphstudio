---
name: World Graph Studio Connections
description: Required architecture for creating or changing REST API, MCP, and hybrid provider Connections.
applyTo: "wordpress/wp-content/plugins/worldgraph/**,about/**"
---

# Provider Connection Instructions

Before planning, implementing, reviewing, testing, or documenting a provider
Connection, start with
[Adding Connections and Templates](../../about/Adding_Connections_and_Templates.md)
and read and follow the
[Provider Connection Adapter Development Specification](../../about/Connection_Adapter_Development_Specification.md).

- Treat the Connection record, adapter manifest, provider transport,
  Template/catalog, and execution wiring as separate contracts.
- A manifest entry alone is not executable. Verify testing, setup,
  catalog/Templates, submission, polling or callback handling, and media import
  wherever the claimed provider workflow requires them.
- Keep outbound provider MCP, inbound WordPress Abilities/MCP exposure,
  repository coding-agent MCP configuration, and runtime creative-advisor
  profiles distinct.
- Follow the [project build instructions](./instructions.md) and
  [testing guide](../testing/testing.md).
- Keep credentials out of logs, URLs, fixtures, browser responses, Templates,
  job metadata, and capability snapshots. Mock provider traffic in tests.
