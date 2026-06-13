# Changelog

All notable changes to **Bext for WordPress** are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/) and the project adheres to semantic versioning.

## [0.4.1] - 2026-06-13

Audit follow-up — bug fixes & hardening from a multi-agent review.

### Fixed
- **`bext/enqueue` double-fire**: actions and filters share WordPress's callback registry, so
  registering the fire-and-forget action and the id-returning filter on the same tag made
  `do_action('bext/enqueue', …)` enqueue a second, malformed job. The filter form is now
  **`bext/enqueue_job`** (the `bext/enqueue` action is unchanged).
- **Network cross-site purge**: "Purge all sites" is now non-blocking (no N×5s stall on large
  networks); `blog_id` is validated against `get_site()`; `switch_to_blog()` is exception-safe
  (`try/finally`); per-row purge URLs are built in the network context, not inside the switch.
- **uninstall.php** now also removes `bext_wp_settings` and the `bext_wp_network_settings` network
  option, and cleans **all** sites (no 100-site cap).
- SDK email: bound total attachment size (default 15 MB, filterable) — falls back to native
  `wp_mail` rather than risk an OOM on the `pre_wp_mail` path.
- Post-save purge only queues Yoast/Rank Math sitemaps when those plugins are active.
- Corrected a stale dashboard string and the integration test (both referenced the abandoned
  cache-purge port instead of `/__bext/cache/purge-proxy`); fixed a broken docs link.

### Changed
- `bin/deploy-fleet.sh`: excludes docs/dev files from deployed sites (keeps `LICENSE`); refuses a
  fleet-wide `--remove` without `--site=` or `--all`.
- CI: the WPCS job is honestly `continue-on-error` (advisory) instead of masking failures.
- More tests: auto-mode bext detection (param, sticky flag, refresh header).

## [0.4.0] - 2026-06-13

WordPress Multisite features & compatibility.

### Added
- **Network Admin → Bext** page (super-admin): network-wide settings (`bext_wp_network_settings`)
  that act as defaults for every site, with an **Enforce** toggle to override per-site settings.
- **Cross-site cache management**: a Sites table (per-site bext status, mode, last purge) with
  per-site **Purge** and **Purge all sites** actions.
- Per-site Settings shows a notice when settings are network-enforced.
- `src/Network.php` (loads only on multisite).

### Changed
- `Env` now resolves every setting with multisite layering: **constant > network (enforced) >
  site > network (default) > built-in default** (`resolved()`). On single-site this is exactly
  "site > default" — behavior is unchanged.
- **App ID** is disambiguated on subdirectory multisite (blogs sharing a host get `host-path`) so
  the SDK queue/email config doesn't collide between blogs. Subdomain multisite + single-site keep
  the host as before.
- Per-request caches are flushed on `switch_blog` so network operations read blog-scoped values.

### Tests
- Unit tests for multisite resolution (network default, enforce, site override) + subdir app-id.

## [0.3.0] - 2026-06-13

Configurable integration + bext cloud support.

### Added
- **Settings page** (*Bext → Settings*): configure the whole integration from wp-admin — no
  `wp-config` editing required. Connection mode, cloud endpoint + token, app id, per-module
  toggles, purge-on-save, anonymous Cache-Control, SDK bridge, plus a **Test connection** button.
- **Cloud mode** (`BEXT_WP_MODE=cloud`): talk to a remote bext endpoint with a bearer token
  instead of loopback, so WordPress served by bext cloud can integrate off-box. See
  [docs/cloud.md](docs/cloud.md).
- bext-side: opt-in `BEXT_PURGE_TOKEN` authorizes remote `POST /__bext/cache/purge-proxy`
  (constant-time compare, scoped to that endpoint; dormant unless the env var is set).
- Docs: `docs/configuration.md`, `docs/cloud.md`, `docs/hooks.md`, `CONTRIBUTING.md`.
- More unit tests (config precedence + transport selection); CI runs all unit tests.
- `.github/FUNDING.yml` + README **Sponsors** (webdesign29, Inklura).

### Changed
- Configuration now layers **constant > setting > default** uniformly (`Env`).
- README overhauled with badges, modes, and docs links.

## [0.2.0] - 2026-06-13

Post-review hardening. The purge transport in 0.1.0 targeted the wrong endpoint and silently
no-opped — this release makes purge-on-change actually work, plus security and correctness fixes.

### Fixed
- **Cache purge now works.** Purges go to the bext main-listener endpoint
  `POST /__bext/cache/purge-proxy` (honors `paths`/`prefixes`, evicts the in-memory FastCGI cache
  serving WP pages) instead of the cache-purge port's disk-substring handler, which ignored
  `paths` and silently did nothing for WordPress sites. Verified surgical (one URL → one eviction).
- **Subdirectory & multisite correctness.** Home, feeds, sitemaps and the REST collection are now
  derived from WordPress (`get_feed_link`, `get_sitemap_url`, `rest_url`) and the install base
  path, so sites under `/blog/` purge the right URLs; a subdirectory-multisite full purge is scoped
  to the blog's path instead of wiping the whole network.
- **Reflected-XSS hardening** in the admin-bar "Purge this URL" link (no longer derives the href
  from the raw request URI; `esc_url()` on all admin-bar hrefs).
- `bext_version` and host/app-id dashboard fields are now correctly escaped (no double-encoding).
- `on_comment` no longer risks a fatal on a missing comment; `before_delete_post` purges regardless
  of status (trashed-then-deleted posts).

### Changed
- No purge port / discovery file / `BEXT_WP_PURGE_PORT` constant needed — everything is on
  `127.0.0.1:80`.
- `bext/enqueue` is now available as a filter (`apply_filters('bext/enqueue', null, …)`) to
  retrieve the job id, in addition to the fire-and-forget action.
- `bext/sdk_email_fallback` action fires when bext can't take an email and WP sends it instead.

### Added
- `uninstall.php` + deactivation cleanup remove all plugin options (incl. the sticky detection flag).
- Full GPL-2.0 license text. CI now runs the unit test.

## [0.1.0] - 2026-06-13

Initial release.

### Added
- **Cache** module: purge-on-change for posts, terms, menus, watched options, comments, and
  WooCommerce stock/product events; coalesced single non-blocking purge on `shutdown`;
  personalization-safe `Cache-Control`; manual purge from the admin bar.
- **Cron** module: disables Action Scheduler's async loopback runner (defers to system cron),
  bounds queue concurrency/time, with a fallback when no system cron is detected.
- **Health** module: configuration checks, known-problematic-plugin detection, opt-in capture of
  recent PHP warnings.
- **Admin** module: a wp-admin "Bext" dashboard and admin-bar status pill.
- **SDK** bridge (opt-in): `wp_mail` via bext's managed email send; `do_action('bext/enqueue')`
  background jobs onto a bext queue. Both fail open.
- WP-CLI: `wp bext status|purge|doctor`.
- `bin/deploy-fleet.sh` to install/update across every WordPress site on a host (copy-per-site,
  required under `open_basedir`).
- Dual-mode loading: works as a must-use plugin or a normal activatable plugin.
