# World Graph Studio — Headless Frontend (optional deployment)

The current implementation is a minimal Next.js App Router frontend for the
WordPress site, modeled on
[9d8dev/next-wp](https://github.com/9d8dev/next-wp). Deploying it is optional;
the WordPress site and `worldgraph` plugin work fully without it.

## Parity commitment

Optional deployment does not make headless maintenance optional. Applicable
delivered functionality and user-facing behavior must be assessed and delivered
for both WordPress and headless as one repository change. The tracked scope,
current gaps, exception rules, and definition of done live in
[Headless Parity Deliverable](PARITY.md).

Functional coverage comes first: a simple, accessible control that completes
the real workflow is preferable to a polished interface with missing or inert
actions.

## What's here

- `lib/wordpress.ts` — typed wrapper around the WP REST API (`/wp-json/wp/v2`)
- `lib/worldgraph.ts` — server-only public Story display adapter over native `wp/v2`, SCF, and the read-only `worldgraph_display` projection
- `lib/worldgraph-admin.ts` — server-only admin wrapper for protected World Graph endpoints (`/wp-json/worldgraph/v1`)
- `app/` — homepage, posts list/detail, published Story collection/detail routes, and a `/api/revalidate` webhook route
- `app/story` — public Projects, Worlds, Characters, Scenes, Props, and Sounds/Songs with galleries and native media players; Project details include the visibility-filtered Development Compass
- `app/connections` — headless ComfyUI catalog manager (sync, prepare, materialize, download)
- `site.config.ts` / `menu.config.ts` — site metadata and nav links

## Setup

Use the repository-root Docker Compose services, which provide the required
Node/npm runtime. Do not install or upgrade Node on the host for repository
work.

The Compose service sets `WORDPRESS_URL` to the internal service URL
`http://wordpress`. It keeps `WORDPRESS_HOSTNAME` as `localhost` because media
URLs returned to the browser use the public WordPress origin at
`http://localhost:8080`.

For the headless Connections manager, also configure these values in the
repository-root `.env` using a WordPress Application Password:

```dotenv
WORLDGRAPH_ADMIN_USER="admin-username"
WORLDGRAPH_ADMIN_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx"
```

These are consumed server-side by Next API routes under
`/api/worldgraph/connections/*` and are never sent to the browser.

The current Connections interface is a development prototype and remains
production-blocked until it authenticates and authorizes the browser user
independently of these server credentials. See the parity ledger.

Start the headless service from the repository root:

```bash
docker compose --profile headless up -d --build headless
docker compose --profile headless run --rm headless npm run build
```

The dev server is available at `http://localhost:3000`.

## Cache revalidation

The optional WordPress module at
`wordpress/wp-content/plugins/worldgraph/plugins/headless-revalidate/` posts a
webhook to `/api/revalidate` whenever supported public content changes,
using the same `WORDPRESS_WEBHOOK_SECRET` configured here and under
Settings → Headless Revalidation in wp-admin.

Story requests use broad, type, ID, and slug cache tags. The matching webhook
shape is `{ contentType: "story", storyType, contentId, slug }`; `storyType` is
one of `projects`, `worlds`, `characters`, `scenes`, `props`, or `sounds`.
Story fetches also refresh after five minutes as a bounded fallback when an
individual webhook cannot be delivered.

The sender retains WordPress safe-HTTP validation. Inside Docker Compose,
configure the webhook with the internal service URL `http://headless:3000` and
use the exact `headless` hostname that Compose automatically allows through
`WORLDGRAPH_HEADLESS_LOCAL_HOSTS`. Set that environment value explicitly only
when adding a different private development hostname. Production hosts need no
local-host exception.

## License

This package is distributed as part of World Graph Studio under
`GPL-2.0-or-later`. Portions adapted from `9d8dev/next-wp` retain the upstream
copyright and license notice in [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
