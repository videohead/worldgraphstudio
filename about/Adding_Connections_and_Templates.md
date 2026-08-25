# Adding Connections and Templates

> Concise extension guide for plugin authors, maintainers, and coding agents.
> The exhaustive security and transport contract remains the
> [Connection Adapter Development Specification](Connection_Adapter_Development_Specification.md).

World Graph Studio separates three decisions:

- a **Connection** identifies a provider account, environment, endpoints, and
  credentials;
- a **Template** identifies one executable provider operation or model and its
  safe input contract; and
- an **adapter manifest** tells core how to load, test, provision, and dispatch
  that provider.

Do not put workflow schemas or prompt defaults on a Connection, and do not put
credentials or arbitrary endpoint URLs on a Template.

## Start with the manifest

Register an adapter on `worldgraph_conn_adapters` before World Graph Studio
needs to build its provider list. A bundled adapter may use plugin-relative
`files`; an external plugin should use a callable `loader` for its own files.

```php
add_filter(
	'worldgraph_conn_adapters',
	static function ( array $adapters ): array {
		$adapters['acme_media'] = [
			'label'        => 'Acme Media',
			'description'  => 'Generate image and video assets through Acme Media.',
			'icon'         => 'dashicons-format-video',
			'endpoint'     => 'https://api.example.com/v1',
			'mcp_endpoint' => 'https://mcp.example.com/mcp',
			'loader'       => static function ( string $_provider_type, array $_adapter ): void {
				require_once __DIR__ . '/includes/class-acme-connection.php';
				require_once __DIR__ . '/includes/class-acme-templates.php';
				require_once __DIR__ . '/includes/class-acme-generation-client.php';
			},
			'callbacks'    => [
				'test'         => [ 'Acme\\Connection', 'test' ],
				'after_save'   => [ 'Acme\\Connection', 'after_save' ],
				'render_admin' => [ 'Acme\\Connection', 'render_admin' ],
			],
			'templates'    => [
				'provision'          => [ 'Acme\\Templates', 'provision' ],
				'delay'              => 5,
				'status_meta_prefix' => 'acme_catalog',
			],
			'generation'   => [
				'client'                => 'Acme\\Generation_Client',
				'adapter'               => 'acme_api',
				'poll'                  => true,
				'poll_with_template'    => false,
				'media_inputs'           => false,
				'flatten_inputs'         => false,
				'poll_error_limit'       => 8,
				'permanent_error_codes' => [
					'acme_authentication_failed',
					'acme_job_not_found',
				],
			],
		];

		return $adapters;
	}
);
```

Use a lowercase `sanitize_key()`-compatible provider slug everywhere. The
same slug belongs in the manifest, Connection `provider_type`, Template
`provider_type`, tests, error codes, and documentation.

The runtime manifest is a PHP array and may contain closures. The portable
machine representation uses a function/class string or a two-item
`[class, method]` array for every callable. Its versioned schema is
[`worldgraph-connection-adapter.schema.json`](schemas/worldgraph-connection-adapter.schema.json).
That schema is a strict authoring profile: it requires explicit labels,
normalized objects, and documented keys even where the PHP registry still
accepts older scalar or partial shapes for backward compatibility.

### Manifest sections

| Section | Purpose |
| --- | --- |
| Top-level metadata | Label, default endpoint(s), conditional loader, initialization, and optional Setup Wizard choices |
| `callbacks.test` | Non-destructive provider readiness check |
| `callbacks.after_save` | Optional synchronous, lightweight post-save hook; normally schedule work rather than perform network discovery |
| `callbacks.render_admin` | Optional trusted wp-admin renderer for provider-specific Connection guidance or controls |
| `templates.provision` | Idempotently create or update provider-managed Templates |
| `templates.delay` | Optional delay, in seconds, before scheduled provisioning; defaults to the core delay |
| `templates.status_meta_prefix` | Optional non-secret prefix for `Template_Manager`-authoritative `_synced_at` and `_error` setup status |
| `generation.client` | One fixed generation client class |
| `generation.client_resolver` | Callable that selects a registered client class from trusted Connection/Template state |
| `generation.poll` | Whether successful submission can require later polling |
| `generation.poll_with_template` | Whether polling receives the provider Template ID as its third argument |
| `generation.adapter` | Optional fixed, sanitized adapter marker string for provider-specific dispatch metadata |
| `generation.adapter_resolver` | Optional callable that selects the adapter marker from trusted Connection/Template state |
| `generation.media_inputs` | Whether this provider accepts the worker's resolved media-input contract |
| `generation.flatten_inputs` | Merge resolved media inputs into top-level provider parameters instead of placing them under `inputs` |
| `generation.poll_error_limit` | Consecutive polling errors allowed before the worker marks the job failed; minimum 1 |
| `generation.permanent_error_codes` | Provider-prefixed `WP_Error` codes that make a poll failure immediately terminal |

Choose one of `generation.client` or `generation.client_resolver`. A resolver
has this signature and must return a trusted, loaded class name:

```php
public static function resolve_client(
	array $connection,
	string $provider_template_id,
	string $adapter
): string;
```

`generation.adapter` is always a literal marker string. When the marker must be
selected dynamically, omit it and declare `generation.adapter_resolver`:

```php
public static function resolve_adapter(
	array $connection,
	string $provider_template_id,
	string $adapter
): string;
```

The resolver returns a sanitized marker string. `generation.adapter` and
`generation.adapter_resolver` are mutually exclusive authoring choices.
Neither this resolver nor `generation.client_resolver` may accept a marker or
PHP class name from a browser request, Template JSON, MCP result, or provider
response without a trusted allowlist.

## Connection test callback

The stable test callback signature is:

```php
public static function test(
	int $connection_id,
	array $record
): array|\WP_Error;
```

Return an unpersisted result:

```php
return [
	'success' => true,
	'message' => 'Connected to Acme Media; generation capability is available.',
	'health'  => [
		'model_count' => 4,
	],
];
```

The callback verifies authentication and the smallest capability required for
the adapter's claimed scope. It must be cheap and non-destructive. Return a
`WP_Error` for a transport or configuration failure, or `success => false`
for a checked-but-not-ready provider response. This is the trusted repository
record returned by `Connection_Repository::get()`, not the decoded/filtered
`resolve()` shape. Its JSON fields remain stored strings and it can contain
sensitive credential material; never copy it into the returned message or
health data.

Core owns persistence of Connection `status`, `last_validated_at`, bounded
health data, and the post-test action. A callback must not write those fields
itself. Health and messages must be bounded, actionable, and free of
credentials, authorization headers, raw provider bodies, and sensitive
account data.

A disabled Connection cannot be health-tested. Core returns an unsuccessful
health-test result without invoking `callbacks.test`, and it preserves the
disabled status. Enable and save the Connection before testing it again.

Two optional lifecycle/UI callbacks use these signatures:

```php
public static function after_save(
	int $connection_id,
	array $record
): void;

public static function render_admin(
	\WP_Post $connection_post,
	array $adapter
): void;
```

`after_save` runs synchronously in the common published, non-disabled
Connection save lifecycle. Keep it lightweight: update local state or schedule
bounded work; do not block an editor save on catalog discovery or other slow
network calls. Common Template provisioning is scheduled from the manifest
before this callback and does not need to be duplicated here.

`render_admin` is trusted PHP output inside the administrator-only Connection
configurator. It may render provider-specific guidance and capability-
protected controls, but must escape untrusted output, use nonces for mutations,
and never print credentials. Omit it when the generic Connection UI is enough.

## Provision provider Templates

The stable provisioning callback signature is:

```php
public static function provision( int $connection_id ): array|\WP_Error;
```

Core schedules and invokes it through:

```php
\WorldGraph\Templates\Template_Manager::schedule_for_connection(
	int $connection_id,
	int $delay = 0
): bool|\WP_Error;

\WorldGraph\Templates\Template_Manager::provision_for_connection(
	int $connection_id
): array|\WP_Error;
```

A zero scheduling override uses the positive `templates.delay` from the
manifest, or the core default when it is omitted. Scheduling is deduplicated
per Connection.

Use
`WorldGraph\Templates\Template_Repository::upsert_provider_template()` for
each supported operation:

```php
use WorldGraph\Templates\Template_Repository;

public static function provision( int $connection_id ): array|\WP_Error {
	$definitions = [
		[
			'provider_type'        => 'acme_media',
			'provider_template_id' => 'generate-video-v2',
			'template_name'        => 'Acme Video v2',
			'modality'             => 'text_to_video',
			'description'          => 'Generate a video from a text prompt.',
			'input'                => [
				'aspect_ratio' => '16:9',
			],
			'provider_schema'      => [
				'type'       => 'object',
				'properties' => [
					'aspect_ratio' => [
						'type' => 'string',
						'enum' => [ '16:9', '9:16' ],
					],
				],
			],
			'configuration'        => [
				'transport' => 'api',
			],
			'status'               => 'active',
			'version'              => '2',
		],
	];

	$template_ids = [];
	foreach ( $definitions as $definition ) {
		$template_id = Template_Repository::upsert_provider_template(
			$connection_id,
			$definition
		);
		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}
		$template_ids[] = $template_id;
	}

	return [ 'template_ids' => $template_ids ];
}
```

The upsert method has this contract:

```php
Template_Repository::upsert_provider_template(
	int $connection_id,
	array $definition
): int|\WP_Error;
```

Portable definitions can be validated against
[`worldgraph-provider-template-definition.schema.json`](schemas/worldgraph-provider-template-definition.schema.json)
before they are converted to the PHP array passed to the repository.

Required definition keys are:

- `provider_type` — exactly matches the owning Connection;
- `provider_template_id` — stable remote operation, model, or tool ID;
- `template_name` — sanitized operator-facing label; and
- `modality` — a registered `Generation_Modality` key.

Optional keys are `description`, `input`, `provider_schema`, `configuration`,
`status`, and `version`. `input`, `provider_schema`, and `configuration` must
be PHP arrays; portable JSON uses objects for these keyed configuration shapes.
`status` is `draft`, `active`, or `archived`; use `active` only when the
operation is executable. A new Template defaults to `active` when `status` is
omitted, so pass `draft` explicitly for a discovered operation that is not
ready to run.

The repository validates that the Connection is published and not disabled,
checks provider identity and modality, derives `generation_structure`, and
creates or updates a published `worldgraph_template`. The idempotent identity
is the exact pair `(connection_id, provider_template_id)`, across every
Template post status.

Reuse a built-in modality whenever its input/output contract matches. If the
provider genuinely introduces a new shape, register a reviewed definition in
PHP rather than copying a remote schema directly into the runtime registry:

```php
add_filter(
	'worldgraph_generation_modalities',
	static function ( array $modalities ): array {
		$modalities['text_to_depth_map'] = [
			'label'       => 'Text to depth map',
			'description' => 'Generate an image depth map from a text prompt.',
			'output_type' => 'image',
			'inputs'      => [
				'prompt' => [ 'type' => 'text', 'required' => true ],
			],
			'nodes'       => [],
			'models'      => [],
		];

		return $modalities;
	}
);
```

The slug must be `sanitize_key()`-compatible and `output_type` must be
`image`, `video`, `audio`, or `text`. Registration makes the shape valid for
Templates; it does not add provider execution, input authorization, UI,
download, or import support. Update and test those layers whenever the new
shape requires behavior they do not already implement.

`configuration_json` is normalized from the extra `configuration` values plus
the `input` defaults and `provider_schema`. Runtime prompts and resolved Story
Graph media bindings do not belong there. An update changes the provider-owned
fields supplied by the definition without erasing unrelated operator-owned
workflow, binding, or default fields.

Provisioning is normally scheduled from the common Connection save lifecycle
through `WorldGraph\Templates\Template_Manager`; a successful Connection test
may also invoke provider provisioning when Templates are part of readiness.
The callback must remain safe to repeat and must not delete an operator-authored
Template because a remote catalog temporarily omits it.

When `templates.status_meta_prefix` is present, keep it stable and
`sanitize_key()`-compatible. `Template_Manager` centrally maintains the
authoritative `<prefix>_synced_at` and `<prefix>_error` Connection metadata on
the generic path:

- a completed provisioning pass updates `<prefix>_synced_at` and clears a stale
  `<prefix>_error`, unless the returned array contains a non-empty `warning`
  that should remain visible there;
- a scheduling or provisioning failure writes an actionable
  `<prefix>_error` without advancing `<prefix>_synced_at`; and
- successful scheduling alone does not count as a completed sync.

Return `WP_Error` when the callback cannot complete the pass; reserve the
optional result `warning` for a completed pass that still needs operator
attention. New provisioners should rely on `Template_Manager` instead of
writing these keys themselves. Bundled legacy hooks and direct health-test
entry points may mirror the same keys for backward compatibility. The values
power generic operator status and must never contain credentials, authorization
headers, remote response dumps, or provider schemas.

## Generation client contract

A synchronous or asynchronous client declares:

```php
public static function run_template(
	string $provider_template_id,
	string $prompt,
	array $parameters,
	int $connection_id = 0
): array|\WP_Error;
```

An asynchronous client also declares:

```php
public static function get_job_status(
	string $remote_job_id,
	int $connection_id = 0
): array|\WP_Error;
```

If `generation.poll_with_template` is `true`, the polling signature is:

```php
public static function get_job_status(
	string $remote_job_id,
	int $connection_id = 0,
	string $provider_template_id = ''
): array|\WP_Error;
```

Submission and polling normalize provider vocabulary to `submitted`,
`completed`, `failed`, or `cancelled`. An asynchronous submission returns at
least `job_id` and `status => submitted`. A completed media result returns
importable `output_media` entries with a supported `kind` and URL, or the
documented synchronous byte contract when the shared importer supports it.
World Graph Studio does not mark media generation complete until every final
output has crossed the WordPress Media Library boundary.

`flatten_inputs` controls only the shape passed to `run_template()`: `false`
places resolved media inputs under `parameters['inputs']`, while `true` merges
them into the top-level parameters for a legacy or provider contract that
needs that shape. It does not bypass input authorization or allowlisting.

For asynchronous work, `poll_error_limit` is the bounded number of consecutive
polling `WP_Error` results before failure. A code in
`permanent_error_codes` fails immediately. Use stable provider-prefixed codes
only; transient transport and time-limit codes should remain retryable within
the declared ceiling.

The client must resolve the saved Connection by ID, confirm its
`provider_type`, allowlist the Template operation and parameters, and return a
stable provider-prefixed `WP_Error` on failure. It must not select a class,
method, tool, URL, or authentication scheme directly from untrusted runtime
input.

## URL-only registration is deliberately non-executable

An API base URL or MCP endpoint URL identifies a possible network location. It
does **not** establish:

- the authentication scheme or credential owner;
- whether the endpoint is safe to reach from WordPress;
- an MCP protocol revision, lifecycle, headers, session rules, or tools;
- operation IDs, parameter allowlists, modalities, polling, or callbacks;
- output schemas, MIME types, download authentication, or media limits; or
- a trustworthy health test or Template catalog.

World Graph Studio may store URL-only adapter metadata or use it to scaffold
operator configuration, but it must not infer executable generation from that
URL. URLs grant no executable capability: each claimed health-test, Template-
provisioning, generation, or non-generation scope requires its applicable
explicit callback, client, transport, and output contract. In particular, a
path ending in `/mcp` is not proof of a compatible MCP server, and ordinary
ComfyUI on port 8188 is an HTTP API rather than MCP.

Automated discovery must begin with a reviewed, bounded transport client. It
must validate the configured destination, negotiate the provider's supported
protocol, treat discovered schemas as untrusted data, allowlist tools and
arguments, and require an administrator to review the resulting Connection
and Templates before billable execution.

A safe URL-assisted generator can still automate the mechanical work:

1. accept a provider slug, label, API/MCP URLs, and explicit transport/auth
   metadata;
2. emit a manifest entry plus loader, test, provisioner, and client stubs;
3. validate the portable manifest and Template definitions against the
   versioned schemas in `about/schemas/`;
4. leave generation capability absent and new Templates in `draft` until the
   protocol, operation allowlists, normalized results, and media boundary have
   deterministic fixtures and administrator review; and
5. register the reviewed code through `worldgraph_conn_adapters` rather than
   storing callable names or arbitrary executable behavior in a Connection.

This makes endpoint URLs useful scaffolding inputs without treating remote
descriptions as code or granting billable execution automatically.

## Lifecycle checklist

1. Specify the provider slug, transport, authentication, operation IDs,
   modalities, job states, outputs, and health check.
2. Register metadata, a conditional loader, and only the callbacks the adapter
   actually implements.
3. Create a published Connection through WordPress admin or the administrator-
   only REST API, then test it.
4. Provision Templates idempotently through the common Template repository.
5. Dispatch only an active Template whose provider and Connection agree.
6. Submit and poll through the manifest-selected generation client.
7. Normalize results and import every media output before completion.
8. Disable the Connection to stop new work; do not delete it until dependent
   Templates have been deliberately migrated or retired.

Guided Setup Wizard support is a separate commitment. Add it only after manual
Connection creation, testing, Template provisioning, generation, polling, and
media import work end to end.

## Security requirements

- Prefer `env://PROVIDER_API_KEY`; keep literal credentials encrypted and
  redacted at every browser/REST boundary.
- Never copy credentials into manifests, URLs, Templates, capabilities,
  health data, logs, errors, job metadata, or test fixtures.
- Use safe WordPress HTTP APIs and a reviewed scheme/host/port/DNS policy for
  configured endpoints and provider-returned download URLs. A local-network
  exception must be narrow and explicit.
- Bound timeouts, redirects, response sizes, collections, schemas, SSE events,
  retries, and media sizes.
- Treat MCP descriptions, schemas, resources, and results as untrusted data,
  never instructions or executable code.
- Keep Connection operations administrator-only; apply object capabilities and
  nonces or signatures to every state-changing surface.
- Do not automatically retry an ambiguous, non-idempotent paid submission.
- Mock all external traffic in automated tests; no live provider account is a
  unit-test prerequisite.

## Minimum tests

Add focused tests under
`wordpress/wp-content/plugins/worldgraph/tests/` for every applicable layer:

- manifest metadata, portable callable shape, lazy loading, and external
  `loader` behavior;
- test callback success, `WP_Error`, false readiness, rejection while disabled,
  status/timestamp persistence, bounded health, and secret redaction; plus
  lightweight `after_save` and escaped/authorized `render_admin` behavior when
  declared;
- Template definition validation, provider/Connection agreement, modality and
  output derivation, idempotent update, manager-maintained scheduling/provisioning
  status metadata, and preservation of unrelated fields;
- fixed adapter marker or adapter-resolver selection and client selection from
  trusted state, submit shape, polling
  signature, input nesting/flattening, normalized terminal states, bounded and
  permanent-error polling policy, and every output;
- endpoint validation, authentication headers, parameter/tool allowlists,
  malformed provider data, size bounds, and permissions; and
- compatibility for the existing bundled adapters and third-party filter
  registration.

Run the narrow adapter/module tests first, then the full suite:

```bash
lando phpunit \
  -c /app/wordpress/wp-content/plugins/worldgraph/tests/phpunit.xml \
  --filter 'Connection|Template|Acme' \
  --do-not-cache-result

lando phpunit \
  -c /app/wordpress/wp-content/plugins/worldgraph/tests/phpunit.xml \
  --testsuite "World Graph Studio" \
  --do-not-cache-result
```

Also lint every changed PHP file, validate changed JSON with `jq empty`, run
the affected JavaScript or headless build checks, and finish with
`git diff --check`. See the repository [testing guide](../.github/testing/testing.md)
for current runtime ownership and commands.

## Implementation references

- [Connection module](../wordpress/wp-content/plugins/worldgraph/includes/connections/README.md)
- [Template module](../wordpress/wp-content/plugins/worldgraph/includes/templates/README.md)
- [Connection adapter development specification](Connection_Adapter_Development_Specification.md)
- [Deployment and Connections](Deployment_and_Connections.md)
- [REST API specification](REST_API_Specification.md)
- [Integration catalog](Integration_Catalog.md)
