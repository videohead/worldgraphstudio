# World Graph Studio Testing Guide

This guide lists the validation surfaces that exist in the repository today.
PHPUnit, PHP lint, JavaScript syntax checks, JSON validation, and shell syntax
are the current automated gates. The repository includes Playwright packages
but does not yet contain a Playwright configuration or spec suite.

## PHP unit and contract tests

The plugin test suite lives in
`wordpress/wp-content/plugins/worldgraph/tests/` and uses
`tests/phpunit.xml` plus `tests/bootstrap.php`.

Run it from the repository root through the `wordpress` service:

```bash
docker compose exec wordpress /opt/worldgraph/vendor/bin/phpunit \
  -c /app/wordpress/wp-content/plugins/worldgraph/tests/phpunit.xml \
  --testsuite "World Graph Studio" \
  --do-not-cache-result
```

Keep `--do-not-cache-result`: PHPUnit's result cache is generated state and is
ignored by the repository.

Run a focused file or method by appending the path or filter:

```bash
docker compose exec wordpress /opt/worldgraph/vendor/bin/phpunit \
  -c /app/wordpress/wp-content/plugins/worldgraph/tests/phpunit.xml \
  --filter test_method_name \
  --do-not-cache-result
```

The suite covers CPT and taxonomy contracts, SCF alignment, relationships,
import/export, generation modalities and providers, REST contracts, WordPress
Abilities, schema alignment, and admin behavior. Use `rg --files
wordpress/wp-content/plugins/worldgraph/tests` for the current file list rather
than maintaining a duplicate list here.

## Static validation

### PHP syntax

```bash
docker compose exec wordpress sh -lc \
  'find /app/wordpress/wp-content/plugins/worldgraph -type f -name "*.php" -exec php -l {} \;'
```

### JavaScript syntax

Node.js belongs to the Docker Compose `node` service:

```bash
docker compose exec node sh -lc \
  'find /app/wordpress/wp-content/plugins/worldgraph/assets -type f -name "*.js" -exec node --check {} \;'
```

### SCF Local JSON

```bash
docker compose exec node sh -lc \
  'for file in /app/wordpress/wp-content/plugins/worldgraph/acf-json/*.json; do node -e "JSON.parse(require(\"fs\").readFileSync(process.argv[1], \"utf8\"))" "$file"; done'
```

### Shell syntax

```bash
docker compose exec node bash -n /app/scripts/interactive-start.sh /app/scripts/setup-db.sh
```

### Patch hygiene

```bash
git diff --check
```

## WordPress runtime smoke checks

WP-CLI runs in the `wordpress` service:

```bash
docker compose exec wordpress wp core is-installed
docker compose exec wordpress wp plugin list
docker compose exec wordpress wp plugin status worldgraph
docker compose exec wordpress wp post-type list --fields=name,public,show_in_rest
docker compose exec wordpress sh -lc 'wp rest route list --fields=route | grep worldgraph'
```

When testing a database upgraded from the old product namespace, also verify
that the active plugin basename is `worldgraph/worldgraph.php`, content counts
are preserved, and the new CPT and taxonomy keys resolve. Do not validate a
serialized WordPress migration with raw SQL replacement.

Useful content commands include:

```bash
docker compose exec wordpress wp post list --post_type=worldgraph_project
docker compose exec wordpress wp post list --post_type=worldgraph_character
docker compose exec wordpress wp option get siteurl
docker compose exec wordpress wp cron event list
```

## Headless parity validation

For a change classified as a headless `contract`, `behavior`, or `visual`
impact, validate the affected PHP contracts and the Next.js consumer in the
same change. The minimum headless gate is a production build:

```bash
docker compose --profile headless run --rm headless npm run build
```

A successful build proves compilation, not behavioral parity. Add and run the
narrowest applicable contract or browser test for the shared outcome,
authorization boundary, error states, and cache invalidation. Update
`headless/PARITY.md` whenever coverage, exceptions, or validation status
changes. Until a checked-in headless test suite exists, report that limitation
rather than treating the build as complete parity evidence.

## External connections

Tests should mock LLM, ComfyUI, Comfy Cloud, fal, ElevenLabs, SunoAPI.org REST,
AceData Cloud Suno MCP, midjourney-api.com REST, Ace Data Cloud MidJourney MCP,
Higgsfield REST/OAuth/MCP, CyberBara Seedance 2.5 REST, VideoDraft MCP, Celtx, and Web
Stories traffic unless a test is explicitly an environment-specific smoke
test. A valid credential or reachable model is deployment state, not a unit
test prerequisite. Suno tests must keep the REST and MCP credentials separate
and cover the `text_to_lyrics` modality. MidJourney tests must keep the
midjourney-api.com `API-KEY` separate from the Ace Data Cloud Bearer token,
cover both transport lifecycles, and import every final image. Higgsfield tests
must keep the combined
REST key ID/secret separate from the MCP OAuth credential, exercise only the
three reviewed REST operation references, and prove MCP discovery does not
expose arbitrary `tools/call`.

Seedance 2.5 tests must mock every CyberBara request and use no live account or
key. Cover the exact `https://cyberbara.com` origin, Bearer-only credential
placement, bounded model readiness check, the fixed
`seedance-2.5:text-to-video` and `seedance-2.5:image-to-video` references,
strict parameter normalization, queued attachment reauthorization, bounded
image upload, asynchronous state mapping, and import of every distinct safe
final video. Include malformed, unauthorized, rate-limited, service-failure,
and ambiguous-submit fixtures; an ambiguous paid submit must not be retried.
Assert that provider errors and results never expose the captured test secret;
keep test-only placeholder credentials confined to fixtures and the captured
CyberBara authorization header.

Manifest-profile OAuth tests must mock authorization, registration, and token
responses. Cover S256 PKCE, nonce/capability checks, one-time state
expiry/replay/user/config binding, public client resolution or dynamic
registration, provider/profile/token-endpoint envelope binding, encrypted
storage, refresh-token rotation under the bounded lock, forced refresh,
disconnect, and credential redaction. Never put a live access token, refresh
token, client credential, API key, or provider account in a fixture.

For a configured environment, verify each enabled connection through **World
Graph Studio > Connections** and exercise one non-destructive request before a
release.

## Playwright status

`package.json` contains Playwright and WordPress E2E dependencies, and the
`node` image installs Chromium. There is currently no checked-in
`playwright.config.*` file or `*.spec.*` suite, so
`docker compose exec node npx playwright test` is not a release gate yet. When
a browser suite is added, document its fixtures, credentials, database reset
strategy, and exact command here.

## Troubleshooting

If PHP appears stale after an edit, clear OPcache without restarting WordPress:

```bash
docker compose exec wordpress php -r 'opcache_reset();'
```

If `docker compose exec wordpress wp` reports that `wp` is missing, the image
predates the WP-CLI build step. Run
`docker compose up -d --build wordpress`, then retry from the `wordpress`
runtime. Do not move WordPress commands into the Node service.

If a test changes the working tree, first check that it did not write
`tests/.phpunit.result.cache`, generated media, browser reports, or a database
dump. Those artifacts do not belong in a source change.
