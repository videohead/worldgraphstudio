# Headless Parity Deliverable

**Status: active repository deliverable**

The Next.js application is optional to deploy, but it is not optional to
maintain. Delivered World Graph Studio functionality and user-facing behavior
must receive an explicit headless parity assessment. When a capability applies
to both interfaces, the WordPress and headless work form one deliverable.

Parity means equivalent capability, permissions, state transitions, validation,
failure behavior, and accessible user outcomes. It does not mean copying
wp-admin markup or reproducing WordPress chrome pixel for pixel.

Functional coverage takes precedence over visual refinement. Implement the
complete, working workflow with clear and accessible basic controls before
spending parity effort on polish. A plain button that performs the authorized
action and communicates its result is more valuable than a graceful interface
with missing, inert, or simulated behavior.

Use this priority order when parity work must be staged:

1. Capability coverage and correct state transitions.
2. Authorization, validation, error handling, and truthful status feedback.
3. Accessible loading, empty, success, retry, and long-running controls.
4. Shared design language and visual refinement.

Visual differences alone do not block functional parity. Missing behavior,
unsafe behavior, or controls that only appear to work do.

## Architecture boundary

- The Story Graph and shared PHP/domain services are the source of truth for
  product behavior.
- `/wp-json/wp/v2` is the current public-post projection used by the headless
  application.
- `worldgraph/v1` is the established REST compatibility surface. Preserve it
  for existing clients, but do not make its wire shape the product model or the
  automatic contract for new headless work.
- New headless code should use an explicit adapter/DTO boundary. A new API
  namespace or transport requires a separate architecture and specification
  decision; this document does not select one.
- Business rules must not be reimplemented independently in Next.js. WordPress
  and headless interfaces should invoke the same PHP services through their
  appropriate application boundaries.

## Default rule and exceptions

A delivered creator-facing PHP feature or UI change is headless-impacting by
default. The same change must update the applicable headless contract or
adapter, types, UI states, authorization, cache invalidation, tests, and this
ledger.

An exception must be explicit in the ledger and include its rationale. Silence,
an absent headless route, or an optional headless deployment is not an
exception. Valid exceptions are narrow WordPress-native or deployment-only
operations whose headless duplication has been deliberately approved as out of
scope.

The initial `Partial` and `Missing` rows record pre-existing debt. A new change
does not have to retire unrelated debt, but it must not widen that debt: the
specific behavior it adds or changes must reach headless in the same deliverable
unless an explicit exception or deferral is approved. Updating the ledger alone
does not satisfy parity.

Bundled prototypes, experimental integrations, and source-only scaffolds are
not parity commitments until they are promoted to delivered scope. Internal PHP
refactors still require a headless regression assessment when their observable
contract or behavior can change.

## Status legend

- **Parity** — equivalent behavior is implemented and verified across the
  supported WordPress and headless paths.
- **Partial** — a headless path exists but does not yet meet the complete parity
  acceptance standard.
- **Missing** — the delivered capability applies to headless but has no usable
  equivalent yet.
- **WP-only** — an explicit, reviewed exception with a recorded rationale.

`Partial` and `Missing` describe parity debt; they are not claims that the
headless workflow is delivered. This ledger does not redefine the delivery
status of the WordPress core; it tracks the additional headless deliverable.

## Capability ledger

| Capability | Current headless evidence | Status | Required next acceptance |
| --- | --- | --- | --- |
| Public posts | `/`, `/posts`, and `/posts/[slug]` use `wp/v2`, pagination, and WordPress-rendered HTML | Partial | Cover rendering, pagination, not-found behavior, accessibility, metadata, and automated tests |
| Published Story Graph displays | `/story`, `/story/[type]`, and `/story/[type]/[slug]` use a typed public `wp/v2` adapter for Projects, Worlds, Characters, Scenes, Props, and Sounds/Songs. Specialized cards and details consume SCF plus the read-only `worldgraph_display` projection for media, ordered published Shots, Project analysis, and the deterministic Development Compass after private nodes are filtered | Partial | Add adapter contract fixtures, browser accessibility coverage, all Story-entity cache lifecycle tests, and an agreed public preview scope |
| Pages, taxonomies, authors, media, public search, and preview | Data helpers exist for several resources, but there are no corresponding user routes or Draft Mode | Missing | Deliver the agreed public routes and authenticated preview, or record a narrower approved scope |
| Story Graph workspace | Public Story collection/detail routes browse published content, but deliberately provide no draft, create, edit, or administrative UI. WordPress now adds edit-screen publish guidance across World Graph Studio CPTs that lists exactly which required fields are missing, and auto-fills mapped required fields such as Project slug/name/owner from canonical post data before save. | Missing | Add a permission-aware creator contract and browse/edit workflows; the anonymous `wp/v2` projection must not be treated as workspace authorization |
| Relationships, traversal, and ordering | Scene details render the server-provided published Shot order read-only; there is no headless graph inspection, relationship editing, administrative drag-and-drop reorder, or Sequence workflow | Partial | Match supported graph and creator ordering outcomes with authorization and cross-stack tests |
| Asset generation | WordPress now exposes Template-conditional sanitized `run_controls`, an effective bounded prompt preview/policy, and explicit Connection+Template-keyed defaults with Template → Project → item → one-run precedence. It validates direct/batch image, video, and audio values and provides a Project-only demonstration plan with dependency-aware generation, cancellation, and rough-cut assembly status. Headless has no authenticated plan, prompt preview, defaults editor, generate, run-control, batch progress, cancel, history, assembly, or provenance path | Missing — authentication-blocked | First deliver browser-user authentication and object-level authorization, then define creator adapter/DTOs for prompt preview, `run_controls`, `run_defaults`, `run_values`, `image_run_values`, `video_run_values`, `audio_run_values`, demonstration tasks, and assembly progress/results; invoke the shared PHP validation/freeze/idempotency behavior; cover complete job and assembly states, accessibility, and cross-stack tests |
| Connections and Templates | `/connections` provides part of the ComfyUI catalog workflow through a server-side v1 adapter. The wp-admin Template Workflow Test (prompt-driven test run returning an Asset number, plus a prompt assistant) has no headless route, but it adds no new API contract: it composes the existing `worldgraph/v1/generation` submit/status and `worldgraph/v1/ai/chat` endpoints | Partial — production-blocked | Authenticate and authorize the browser user, define stable DTOs, preserve upstream errors, cover generic agreed workflows including the Template test run, and add contract/E2E tests |
| Provider OAuth authorization and Higgsfield setup | Higgsfield is configured on a saved wp-admin Connection. The shared profile broker starts an administrator-only external authorization-code + PKCE redirect, consumes its fixed admin-post callback, and stores/refreshes the encrypted token envelope server-side. Headless has no safe browser-user authorization or secret-management control | WP-only | Explicit narrow exception: account authorization, disconnect, and provider credential setup remain WordPress/deployment administration because WordPress owns the Connection record, capability/nonce boundary, callback URI, and encryption key. This does not exempt creator generation, job state, Templates, or general Connection visibility from the existing parity debt |
| Story intelligence | WordPress and published headless Project details now render the same deterministic Development Compass from the shared graph analyzer, including a next-change question when coverage checks are clear. WordPress adds normal edit/create navigation; headless uses the privacy-filtered public projection. Search, continuity, summary, dramaturgy, authenticated creator actions, and interactive analytics remain absent in headless. The WordPress-only admin analytics tables link entity and character names to their native edit screens; this is a narrow wp-admin navigation exception, not a headless capability. | Partial | Add runtime adapter fixtures and browser coverage for the Compass, then deliver equivalent authorized outcomes for the remaining shipped workflows or record explicit exceptions |
| Story import and export | The default-enabled WordPress feature plugin exposes administrator-only `worldgraph/v1/import/validate`, `/import`, `/import/decompose`, and `/export/{project_id}` routes for canonical JSON, selected-Connection story decomposition preview, and JSON/Markdown export. The Next.js app has no creator adapter, browser-user authorization, upload/retention controls, preview/confirm UI, or export download flow | Partial — authentication-blocked | Authenticate the browser user, map equivalent WordPress and object capabilities, define typed DTOs for persisted attachment preview/confirmation and all three export formats, expose retained-source deletion guidance, implement accessible long-running/error/cancel/success states, and add cross-stack tests |
| Production, editorial, and logs | No headless production, editorial, or generation-log workflow | Missing | Prioritize delivered workflows; exclude scaffolds until their delivery status changes |
| AI Editor and advisors | No headless interface | WP-only | The current project architecture keeps the AI Editor, production advisors, and Story Graph intelligence advisor profiles inside WordPress; changing that boundary requires an explicit architecture decision |
| Setup and plugin administration | No headless interface | Missing | Deliver the agreed administration outcomes or approve narrow deployment-only exceptions |
| Design system and content rendering | Design tokens are manually mirrored; posts use rendered WordPress HTML; Story routes add content-specific cards, a reduced-motion Character flip state, responsive galleries, and native accessible audio/video controls | Partial | Establish canonical token/style ownership and test supported static, dynamic, interactive, keyboard, and reduced-motion output |
| Cache invalidation | Story fetches use `story`, type, ID, and slug tags, the shared-secret handler accepts the matching Story webhook shape, attachment lifecycle changes invalidate media projections, and a five-minute refresh bounds staleness after an isolated webhook failure | Partial | Add delivery/retry telemetry, verify old/new slug invalidation, and cover related Scene/Project aggregate invalidation with integration tests |
| Headless authentication and authorization | The Connections adapter uses a server-side Application Password without independent browser-user authorization | Missing — production blocker | Authenticate the person, authorize each operation, and never treat the server credential as user authority |
| Cross-stack verification | The production headless build currently passes, but there is no checked-in headless test suite or CI parity gate | Missing | Add contract, type/build, and browser tests including anonymous public projection checks, anonymous denial, and authorized success |

The WordPress run-control and Project demonstration-video delivery does not move
Asset generation to `Partial`: there is still no usable authenticated headless
creator transport or UI, and the anonymous `wp/v2` projection cannot safely
provide one. The requested creator control is deliberately placed in the
WordPress Project editor; equivalent headless generation and assembly remain
explicit parity debt blocked by the browser-user authentication and
authorization gap, not a claim of headless parity or a broad `WP-only`
exception.

The provider OAuth row is intentionally narrower than the Connections and
Templates row. It approves WordPress-only administration of third-party account
consent, callback state, encrypted secret storage, refresh, and disconnect. It
does not make Higgsfield generation or generic Connection/Template management a
WP-only product capability; those creator-facing outcomes remain subject to the
existing authentication-blocked parity requirements.

Likewise, the Story Import & Export REST routes move the transport evidence out
of `Missing`, but they do not deliver creator parity by themselves. The current
server-side Application Password pattern cannot stand in for the browser
user's authority, and the Next.js application does not yet provide the
persisted-upload, selected-Connection preview, explicit confirmation, source
retention, or download outcomes. Interchange therefore remains explicitly
authentication-blocked parity debt.

## Parity acceptance standard

A capability reaches **Parity** only when all applicable requirements are met:

1. WordPress and headless use the same Story Graph/domain behavior rather than
   divergent implementations.
2. The headless adapter has an explicit request/response contract, typed client
   boundary, runtime failure handling, and compatible error semantics.
3. The actual browser user is authenticated and authorized with equivalent
   WordPress capability and object-level checks.
4. Loading, empty, validation, error, success, retry, and long-running states are
   represented accessibly.
5. Publish, unpublish, deletion, slug, taxonomy, relationship, generated-media,
   and aggregate-cache effects are covered where relevant.
6. Narrow PHP tests, headless type/build checks, and cross-stack behavioral or
   browser tests verify the shared outcome.
7. The capability ledger and affected specifications describe what is actually
   delivered.

Visual parity means a consistent product language, design tokens, information
hierarchy, accessibility, and interaction outcome. It does not require the
headless application to imitate wp-admin layout, and it must not delay delivery
of a complete functional workflow.

## Change checklist

For every functional or user-facing WordPress change:

- [ ] Classify the headless impact as `contract`, `behavior`, `visual`, or
      `not applicable`.
- [ ] Identify the shared PHP/domain service and avoid duplicating its rules in
      Next.js.
- [ ] Update the headless adapter/DTO and UI states when parity applies.
- [ ] Deliver working, accessible controls before optional visual polish; do not
      ship inert or simulated actions as parity.
- [ ] Check browser-user authorization, error propagation, and cache or preview
      effects.
- [ ] Add or update PHP, headless, and cross-stack verification in proportion to
      the change.
- [ ] Update this ledger whenever coverage, known gaps, exceptions, or
      validation status changes.
- [ ] Treat an applicable touched behavior as incomplete until its headless path
      is delivered. A deferral or `WP-only` exception requires explicit approval
      and a recorded rationale; changing the ledger alone does not satisfy the
      deliverable.
