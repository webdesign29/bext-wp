# Changelog

All notable changes to **Bext for WordPress** are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/) and the project adheres to semantic versioning.

## [0.5.0] - 2026-06-13

Extensibility, a broader purge set, a `wp bext flush` command, richer diagnostics — and a much
deeper test suite.

### Added
- **`bext/after_purge` action** — fires after every purge (auto, manual admin-bar, network
  cross-site, and WP-CLI) with the host and exactly what was purged:
  `do_action( 'bext/after_purge', string $host, string[] $paths, string[] $prefixes )`. Lets you
  mirror purges to a second CDN, log them, ping a webhook, etc.
- **Broader default post-purge set** (still behind the `bext/purge_urls_for_post` filter): the
  post-type archive's **page 2** (`/post/page/2/`) and any **attachment pages** the post owns are
  now purged alongside the permalink/home/archives/feeds. Attachment-page count is bounded
  (`bext/purge_max_attachment_pages`, default 20) so a media-heavy post can't balloon a purge.
- **`wp bext flush`** — the "big hammer": flushes the WordPress **object cache** (`wp_cache_flush()`,
  e.g. Redis/Memcached) *and* the bext edge cache for the whole site, in one command. Use after a
  deploy or bulk import.
- **Dashboard diagnostics**: the *bext server* card now surfaces the last health probe's
  `x-bext-cache` / `x-bext-php` (+ `x-bext-wp` / `server`) response headers as small badges, when
  bext returns them. `Env::bext_get()` now returns a `headers` map; `Env::bext_response_headers()`
  extracts just the diagnostic ones (sanitized).

### Tests
- Roughly tripled the suite: **230 assertions across 10 files** (was 84 across 6).
- New `CronTest` (taming filters + `stats()` against a stubbed Action Scheduler store, incl.
  exception-safe counts and the oldest-due path), `NetworkTest` (`purge_blog` blog-id validation,
  exception-safe `switch_to_blog`, blocking-vs-non-blocking contract, all-sites loop, network
  `after_purge`), `HealthTest` (`checks()` decision logic + the `bext/health_checks` filter), and
  `SettingsTest` (`sanitize()` whitelist/coercion + the cloud-without-endpoint guard).
- `CachePathsTest` greatly expanded: the full post purge set (incl. the broadened paths), dedup/set
  semantics, the `flush()` body shape, `after_purge` args, and purge-on-save gating.
- `EnvSettingsTest` adds `bext_response_headers()` + host/path normalization coverage.
- `tests/bootstrap.php` gained stubs for posts/permalinks/terms/attachments, multisite
  (`get_site`/`switch_to_blog`), the object cache, and a `do_action` call log + small test helpers.

### Notes
- All changes are escaped/nonce'd, multisite-safe, PHP 7.4-compatible, and fail open. The Updater
  is untouched.

## [0.4.3] - 2026-06-13

Self-hosted auto-updates.

### Added
- **Automatic updates** for normal-plugin installs. The plugin checks a self-hosted manifest
  (`https://wp-plugins.inklura.fr/api/update?slug=bext-wp`) and, when a newer release is published,
  shows "update available" on the Plugins screen and supports one-click update — just like a
  wordpress.org plugin. Powers the "View details" modal too. The remote check is cached 12 h.
- Update packages are GitHub release assets (`releases/latest/download/bext-wp.zip`); the updater
  normalises the extracted folder so updates install back into `wp-content/plugins/bext-wp/`.
- `bin/build-zip.sh` packages a release ZIP. Manifest URL is overridable via
  `BEXT_WP_UPDATE_URL` / the `bext/update_manifest_url` filter.
- Unit tests for the version-compare + transient/`plugins_api` logic.

### Notes
- Must-use installs are excluded (they update via `bin/deploy-fleet.sh`).

## [0.4.2] - 2026-06-13

Performance + tests.

### Performance
- The admin-only modules (Settings, Network, and Health when warning-capture is off) are no longer
  booted on **front-end requests** — the hot path bext serves on a cache miss. They load in
  admin/CLI contexts (where they're needed; `wp bext doctor` still works under WP-CLI). Saves
  object construction + hook registration on every anonymous render.
- `Env::mode()` is memoized per request (it's consulted by several modules at boot).

### Tests
- New `tests/bootstrap.php` with a faithful action/filter registry (actions + filters share one
  registry, like WordPress).
- `SdkEnqueueTest` locks the v0.4.1 fix: `do_action('bext/enqueue')` enqueues exactly once (no
  double-fire) and the filter form lives on `bext/enqueue_job`.
- `CachePathsTest` (url_to_path / normalize_path / home_path incl. subdirectory installs),
  `PersonalizationTest` (cookie-based personalization detection), and a `mode()` memoization check.
- 72 assertions across 5 files (was ~22 across 2).

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
