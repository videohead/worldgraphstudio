## Recommended fix list (before submitting)

1. Harden the public `/search` + `/search/suggest` endpoints (rate-limit, throttle/denylist, switch result `url` to `get_permalink()`).
2. Load the `worldgraph` text domain on an `init` hook.
3. Consolidate or legitimately split the `plugins/` submodules and remove nested `Plugin Name` headers; ideally rename anything colliding with existing plugin names (esp. `web-stories`).
4. Surface Terms/Privacy links per provider on the connector/settings screens (not only the readme).
5. Add `uninstall.php`/register an opt-in uninstall hook, or document the purge tool as the supported path.
6. Where you keep raw `$wpdb` queries and curl, retain/substantiate the `phpcs:ignore` notes plus explicit justification comments; the WordPress team will read them.
7. Keep the readme's Privacy/Service Disclosure section intact at every release — it is currently the single strongest compliance asset.

__Net:__ this is at the "very close to submission-ready, expect revision comments" end of the spectrum. With the delegate endpoints hardened, the i18n fix, a de-conflicted folder layout, and unhidden service links on the settings screens, it should comfortably pass a guidance-compliant plugin review.
