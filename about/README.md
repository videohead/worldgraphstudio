# World Graph Studio Documentation

This documentation describes the shipped World Graph Studio system, including
delivered script interchange, extension surfaces for provider Connections, and
50+ profile-driven specialist agents. Start with the product overview and
delivery status, then follow the path that matches what you are trying to do.

## Start here

- [Product overview](marketing/overview.md) — positioning, audience, and
  principles.
- [Delivery status](Delivery_Status.md) — the authoritative inventory of
  shipped capabilities, optional integrations, interchange directions, and
  remaining boundaries.
- [Product requirements](World_Graph_Studio_PRD.md) — current product scope and
  success criteria.
- [Architecture](World_Graph_Studio_Architecture.md) — system components and
  ownership boundaries.
- [User guide](example-workflow/USER_GUIDE.md) — install, configure, and work
  through the sample project.

## Build and operate

- [Deployment and connections](Deployment_and_Connections.md)
- [Adding Connections and Templates](Adding_Connections_and_Templates.md) —
  concise human and coding-agent quickstart for adapter registration,
  provisioning, and generation dispatch; includes versioned
  [adapter](schemas/worldgraph-connection-adapter.schema.json) and
  [provider Template](schemas/worldgraph-provider-template-definition.schema.json)
  authoring schemas.
- [Connection adapter development specification](Connection_Adapter_Development_Specification.md) —
  implementation contract for REST API, MCP, and hybrid provider Connections.
- [Plugin setup guide](../wordpress/wp-content/plugins/worldgraph/documentation/SETUP_GUIDE.md)
- [Setup wizard guide](../wordpress/wp-content/plugins/worldgraph/documentation/SETUP_WIZARD_GUIDE.md)
- [Contributor guide](CONTRIBUTING_World_Graph_Studio.md)
- [Development instructions](../.github/instructions/instructions.md)
- [Testing guide](../.github/testing/testing.md)

## Understand the data model

- [Content model](Content_Model_Specification.md)
- [CPT and SCF schema](CPT_and_SCF_Schema.md)
- [Story Graph specification](Story_Graph_Specification.md)
- [Schema.org minimum surface](SchemaOrg_Minimum_Surface.md)
- [Schema.org interoperability review](SchemaOrg_Interoperability_Review.md)

## Use and extend the APIs, intelligence, and agents

- [REST API](REST_API_Specification.md)
- [AI Editor](AI_Editor.md)
- [Agent architecture](Agent_Architecture.md) — how the 50+ bundled profiles
  are loaded, selected, routed, and extended.
- [Story Graph intelligence](Story_Graph_Intelligence.md)
- [API-key wizard integration](API_KEY_WIZARD_INTEGRATION.md)

## Import, export, synchronize, and connect

- [Integration catalog](Integration_Catalog.md) — table view of bundled
  plugins, executable Connection adapters, AI backends, and extension
  placeholders.
- [Generation engine](plugins/GENERATION_ENGINE.md)
- [Suno integration](plugins/SUNO.md)
- [VideoDraft connection and sync](plugins/VIDEODRAFT.md)
- [Descript connection and exchange](plugins/DESCRIPT.md)
- [Comfy template catalog](plugins/COMFY_TEMPLATE_CATALOG.md)
- [Comfy and prompt advisors](plugins/COMFY_AND_PROMPT_AGENTS.md)
- [Generation preferences](plugins/GENERATE_PREFERENCES.md)
- [Script and EDL integration](Script_EDL_Integration.md) — delivered JSON,
  FDX, Markdown, and VideoDraft directions plus Fountain, Celtx, Descript, and
  EDL scaffold boundaries.
- [EDL plugin](plugins/EDL_IMPORT_AND_EXPORT.md)
- [Celtx integration](plugins/CELTX.md)
- [Web Stories connector prototype](plugins/WEB_STORIES.md)
- [Web generation providers](WEB_GENAI.md)

## Learn from the sample project

- [Example workflow guide](example-workflow/USER_GUIDE.md)
- [JSON import contract](example-workflow/JSON_import_spec.md)
- [Sample project JSON](example-workflow/little-red-riding-hood.worldgraph.json)
- [Sample Markdown export](example-workflow/Little-Red-Riding-Hood-Screenplay-Example-Export.md)

## Project and community

- [Roadmap and release scope](ROADMAP_World_Graph_Studio.md)
- [Brand guide](marketing/World_Graph_Studio_Brand_Guide.md)
- [Governance](GOVERNANCE_World_Graph_Studio.md)
- [Code of conduct](CODE_OF_CONDUCT_World_Graph_Studio.md)

## Status language

Documentation uses these terms consistently:

- **Delivered** means the capability exists in the current repository.
- **Optional** means it is delivered but requires a connection, dependency, or
  separately enabled bundled integration.
- **Script and project interchange** includes delivered JSON and FDX import,
  Markdown export, and VideoDraft structural sync. Fountain, Celtx, Descript,
  and the EDL admin workflow remain scaffold or experimental surfaces; the EDL
  PHP parsing, timecode, and formatting functions can support custom adapter
  work. Delivery status defines the supported boundary of each.
- **Extensible** means the current architecture exposes a supported addition
  point; it does not imply that every possible adapter is already bundled.
- **Extension point** describes a possible integration boundary, not a roadmap
  promise.
- **Prototype** means source or design work exists but is not loaded or
  supported as a current-release workflow.

Historical phase numbers may appear where they help locate older design work,
but delivery status is defined by the current implementation rather than the
old phase plan.
