# Story Graph Intelligence

> Your ideas. Your assets. No credits needed.

**Status: Complete**

## Implementation Update

Story Graph Intelligence runs inside the World Graph Studio WordPress plugin. Story data,
relationships, continuity findings, search configuration, and cached results
are owned by WordPress. The feature is implemented within the World Graph Studio plugin
and its WordPress data and API surfaces.

## Objective

Make the Story Graph useful as a queryable narrative model rather than only a
collection of WordPress posts. The delivered feature provides four related
capabilities:

1. **Story search** - Find characters, scenes, locations, shots, props, assets,
   storyboard frames, and editorial artifacts by title, content, metadata, and
   entity filters.
2. **Continuity validation** - Detect inconsistencies in entity relationships,
   scene membership, references, and asset lineage.
3. **Relationship analytics** - Summarize the network of Story Graph entities,
   including co-occurrence, density, relationship types, and isolated entities.
4. **Development guidance** - Translate bounded graph facts into creative
   questions about missing foundations, elements without Scene exposure, and
   Scenes without Character or Location context.

These capabilities are distributed across WordPress search, admin panels,
WordPress REST resources, and the WordPress Abilities API. The Development
Compass specifically ships in the WordPress Analytics panel and the read-only
Project display projection; it is not registered as a search mode or Ability.

## Architecture

```text
WordPress Admin and Gutenberg
        |
        v
World Graph Studio WordPress plugin
        |
        +--> Story Graph CPTs, SCF, post metadata, relationships
        +--> Story search and entity filters
        +--> Continuity rules and persisted issues
        +--> Relationship analytics and admin panels
        +--> Deterministic Development Compass prompts
        +--> WordPress REST API
        +--> WordPress Abilities API
        |
        v
Optional API-connected LLM for explanation and editorial assistance
```

The Story Graph remains the canonical source of truth. Intelligence code reads
WordPress entities and relationships and writes only WordPress-owned results,
metadata, notices, and audit information.

## Design Principles

### WordPress-Native First

Use `WP_Query`, registered post types, SCF values, post metadata, WordPress
admin screens, and WordPress REST controllers. Extend existing WordPress
workflows instead of creating a parallel search or graph application.

### Deterministic Core Logic

Search filtering, relationship traversal, continuity rules, scoring, and
aggregation should be deterministic and testable without an LLM. An LLM may
explain findings or help a user explore the graph, but it must not be the sole
source of truth for a continuity error or relationship.

### Graceful Search Fallback

Keyword and metadata search must remain available when optional semantic
matching or an LLM is unavailable. Search results should identify their source
and use bounded, explainable scores.

### Incremental Work

Run inexpensive checks during normal saves and reserve larger graph analyses
for explicit admin actions or bounded WordPress background work. Do not block a
web request on a large graph scan.

### Privacy and Permissions

Only authorized users may inspect private Story Graph entities, continuity
findings, or analytics. LLM requests must include only the minimum approved
context and must never include credentials or unrelated private posts.

## Search

### WordPress Search Enhancement

Implementation:

`wordpress/wp-content/plugins/worldgraph/includes/utils/story-search.php`

The search helper extends the normal WordPress search experience with World Graph Studio
entity filters. Supported entity types currently include:

- Characters
- Scenes
- Locations
- Shots
- Props
- Assets
- Storyboard frames
- Editorial artifacts

The search configuration supports keyword, semantic, and hybrid modes. The
WordPress implementation must always retain a keyword path so a site remains
usable without an embedding service, GPU, or remote model.

Search responsibilities:

- Map entity filters to registered WordPress post types.
- Sanitize search terms and query parameters.
- Query titles, content, SCF values, and approved metadata.
- Return entity type, post ID, title, score, snippet, and edit URL.
- Deduplicate results by entity type and post ID.
- Merge scores using explicit configured weights.
- Preserve WordPress permissions and post visibility rules.

The search UI may show an entity label, a bounded confidence indicator, related
entities, and continuity status. It must not imply that a score is a factual
probability.

### Search and Abilities

The `worldgraph/analyze` ability may use the same context and search helpers as the
admin UI. It should return structured results that identify matching entities
and the fields that contributed to the match. Do not expose an internal search
implementation as a second external protocol.

## Continuity Validation

### WordPress Continuity Checker

Implementation:

`wordpress/wp-content/plugins/worldgraph/includes/utils/continuity-checker.php`

The checker runs local rules against WordPress entities and stores sanitized
issues in post metadata for display in the admin panel and REST responses.

Supported save triggers should remain narrow and predictable:

| Event | Checks |
| --- | --- |
| Scene saved | Character associations, location, prop references, scene context |
| Character saved | Scene appearances and relationship consistency |
| Shot saved | Scene membership and asset references |
| Asset saved | Derivation lineage and scene/shot references |
| Project saved | A larger scan only when explicitly requested or scheduled |

Each issue should contain:

- Stable rule identifier.
- Severity: `error`, `warning`, or `info`.
- Human-readable description.
- Affected entity IDs and types.
- Optional actionable suggestion.
- Detection timestamp and source revision where useful.

### Continuity Rules

Initial rules include:

| Rule | Severity | Description |
| --- | --- | --- |
| `character_not_associated` | error | A character is referenced by scene content but is not related to the scene. |
| `location_mismatch` | warning | A scene references a location that does not match its relationship field. |
| `prop_undefined` | warning | A referenced prop has no corresponding Story Graph entity. |
| `timeline_overlap` | warning | Ordered scenes contain conflicting timing metadata. |
| `relationship_conflict` | error | Stored relationship data conflicts with the current entity context. |
| `orphaned_asset` | info | An asset has no scene, shot, project, or other approved parent. |
| `missing_visual_ref` | info | A visual entity has no linked reference asset. |
| `duplicate_name` | warning | Ambiguous duplicate names need editorial resolution. |

Rules should report evidence and should not silently modify Story Graph data.
Any fix action must be an explicit, permission-checked WordPress operation.

### Admin Experience

The continuity panel is implemented in:

`wordpress/wp-content/plugins/worldgraph/includes/admin/continuity-panel.php`

It should provide:

- Issue counts by severity.
- Filtering by rule and entity.
- Links to affected WordPress edit screens.
- A manual validation action.
- Clear distinction between stored findings and resolved findings.

## Relationship Analytics

### Local Graph Calculations

Implementation:

`wordpress/wp-content/plugins/worldgraph/includes/utils/relationship-graph.php`

Relationship analytics are calculated from the World Graph Studio relationship helpers and
registered post data. The current calculations include:

- Relationship type distribution.
- Character co-occurrence.
- Location and scene usage.
- Entity connectivity and centrality-like counts.
- Network density.
- Isolated or unused entities.

Analytics are summaries of stored relationships. They must not invent edges
based only on textual similarity. Any inferred relationship must be labeled as
inferred and must not be persisted as canonical graph data without editorial
confirmation.

### Development Compass

The relationship analyzer also produces a nested `development` result from the
same canonical graph. It assigns a descriptive phase, reports the complete
opportunity count, returns at most twelve ordered opportunities, and indexes
the existing entities named by those opportunities. Initial rules cover:

- A graph with no Character, Location, or Scene foundation.
- A Character, Location, or Prop not represented in a Scene, either directly
  or through a Shot owned by that Scene.
- A Scene with no Character connection directly or through one of its Shots.
- A Scene with no direct Location connection.
- When those coverage checks are clear, a next-event prompt asks what changes,
  who experiences it, and which new Scene or element would make it visible.

Every opportunity contains a stable ID, rule type, priority, factual evidence,
an open creative question, an optional existing entity reference, and the type
of Story Graph element that could be created next. These are development
prompts, not continuity errors or measures of story quality. Analysis never
creates an entity, adds an inferred edge, or requires an LLM.

The next-event framing borrows only the general narrative-design insight in
[`storygraph/story-graph`](https://github.com/storygraph/story-graph) that story
progress can be considered as events changing a prior state. World Graph Studio
does not vendor, call, depend on, or adopt the repository's Go implementation,
service, or separate state schema. The Compass remains a loose guide over the
existing WordPress Story Graph.

The WordPress Analytics panel renders edit and normal draft-creation links from
these descriptors; those links never create graph relationships automatically.
The public headless Project detail renders the same guidance from the
visibility-filtered `worldgraph_display` projection; private and draft nodes
are removed before the rules run.

### Analytics Admin Panel

Implementation:

`wordpress/wp-content/plugins/worldgraph/includes/admin/analytics-panel.php`

The panel presents:

- Total entity and relationship counts.
- Relationship type distribution.
- Character co-occurrence summaries.
- Most and least connected entities.
- Isolated entity reports.
- Network density and related filters.

The JavaScript panel should load data through WordPress REST or admin AJAX,
respect nonces, handle empty graphs, and show an actionable error when a
request fails.

## Story Graph Data Surface

Story Graph Intelligence works with the existing Story Graph custom post types
and relationships.
Indexed or inspected fields should be explicit and documented per entity type.

| Entity | Useful fields |
| --- | --- |
| Character | Title, biography, appearance, motivation, backstory, personality |
| Location | Title, description, mood, geography, environment type |
| Scene | Title, summary, script content, production notes, relationships |
| Shot | Description, visual description, notes, scene relationship |
| Asset | Title, description, generation prompt, lineage, parent entity |
| Prop | Title, description, purpose, scene relationships |
| Project | Title, description, genre, world and entity relationships |
| Story World | Title, description, timeline, rules, themes |

Use registered SCF and relationship definitions rather than hard-coding
unvalidated metadata keys in new features.

## Abilities API Integration

Story Graph Intelligence is available to the WordPress AI surface through the
Abilities API registered in:

`wordpress/wp-content/plugins/worldgraph/includes/ai-editor/class-ai-abilities.php`

Relevant abilities include:

- `worldgraph/analyze` for structured analysis of current content and context.
- `worldgraph/continuity-check` for a permission-checked continuity request.
- `worldgraph/post-context`, `worldgraph/character-context`, and
  `worldgraph/scene-context` for contextual resources.
- `worldgraph/story-review-prompt` and `worldgraph/continuity-prompt` for reusable
  prompt templates.

The WordPress MCP Adapter may expose these abilities as MCP tools, resources,
and prompts. Abilities must define schemas, permission callbacks, and accurate
readonly/destructive/idempotent annotations. The Abilities layer should call
the same WordPress services used by admin and REST surfaces.

## REST and Admin Contracts

Intelligence data is exposed through WordPress-owned REST resources under the
project namespace:

`/api/worldgraph/v1`

Potential resource groups include:

- Search results and entity filters.
- Continuity issues for a post or project.
- Relationship summaries and graph data.
- Analytics summaries and isolated entities.

Controllers must:

- Enforce WordPress capabilities.
- Validate and sanitize all input.
- Escape rendered output.
- Use nonces for state-changing admin requests.
- Return structured `WP_Error` responses.
- Avoid leaking private entity content or implementation details.

The REST API exposes World Graph Studio resources and calculations. It is not a proxy for
another application or a remote intelligence service.

## Implementation Surfaces

- Search: `includes/utils/story-search.php`
- Continuity rules: `includes/utils/continuity-checker.php`
- Relationship traversal: `includes/utils/relationships.php`
- Relationship analytics: `includes/utils/relationship-graph.php`
- Continuity admin UI: `includes/admin/continuity-panel.php`
- Analytics admin UI: `includes/admin/analytics-panel.php`
- Graph REST controller: `includes/rest-api/graph-controller.php`
- AI and MCP abilities: `includes/ai-editor/class-ai-abilities.php`
- Intelligence UI scripts: `assets/js/continuity-panel.js` and
  `assets/js/analytics-panel.js`

## Testing Strategy

### Search Tests

- Entity filters map to the expected post types.
- Keyword search returns matching Story Graph entities.
- Empty and unavailable optional semantic paths fall back cleanly.
- Hybrid results are deduplicated and ordered by configured scores.
- Search results respect post visibility and permissions.

### Continuity Tests

- A missing character relationship produces the correct issue and severity.
- A valid scene produces no false positive for that rule.
- Issue metadata is persisted and can be retrieved for the correct post.
- Manual checks require the appropriate WordPress capability.
- Saving unrelated post types does not trigger scene continuity work.

### Relationship Tests

- Relationship edges are read from the canonical relationship helpers.
- Co-occurrence counts are deterministic.
- Isolated entities are identified correctly.
- Empty graphs return valid zero-value summaries.
- Analytics do not create or mutate canonical relationships.
- Development opportunities have stable ordering and identifiers.
- Missing foundations and unexposed elements produce factual, question-led
  prompts without graph mutation.

### Abilities and REST Tests

- Ability schemas register on supported WordPress versions.
- Public search results are limited to published content; private intelligence
  data and state-changing checks require WordPress permissions.
- Ability callbacks return structured success and error responses.
- REST responses contain stable entity IDs and types.
- Nonces and capability checks protect state-changing operations.

## Definition of Done

- [x] Story search is integrated with WordPress entity filters.
- [x] Keyword fallback works without an external model service.
- [x] Continuity findings are computed and stored in WordPress.
- [x] Relationship analytics are calculated from canonical graph data.
- [x] Relationship analytics provide deterministic next-step development
      questions in WordPress and published headless Project views.
- [x] Admin panels display continuity and relationship results.
- [x] Story Graph context is available through WordPress abilities.
- [x] REST and admin surfaces enforce WordPress permissions.

The release requirements above are complete. Large-site performance tuning and
end-to-end browser coverage are ongoing quality work, not pending product
deliverables.

## Relationship to Other Phases

| Capability | Relationship |
| --- | --- |
| Story Core | Supplies the canonical entities, SCF fields, and relationships. |
| Generation Engine | Stores generated assets and provenance in the Story Graph. |
| Script Ecosystem | Imported scripts create searchable scenes, characters, and locations. |
| Editorial Ecosystem | EDL and timeline artifacts become searchable Story Graph entities. |
| AI Editor | Uses context, continuity findings, and abilities for editor assistance. |

## Long-Term Direction

Keep intelligence close to the Story Graph and make every result explainable,
permission-aware, and useful in ordinary WordPress workflows. Future work may
improve local indexing, search ranking, visualization, and narrative reasoning,
but those improvements must preserve WordPress as the system boundary and must
not introduce a second intelligence runtime.
