# Changelog

All notable changes to **Bext for WordPress** are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/) and the project adheres to semantic versioning.

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
