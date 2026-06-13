# Bext for WordPress

> Make WordPress cooperate with the [bext](https://bext.dev) server instead of fighting it.

`bext-wp` is a small, dependency-free WordPress plugin (usable as a **must-use** plugin or a
normal one) for sites served behind **bext**. It turns bext's edge cache from a blunt
TTL into a precise, always-fresh cache, tames Action Scheduler, keeps personalized responses out
of the anonymous cache, and gives operators a real dashboard.

It is **safe by default**: every feature no-ops when the site isn't actually behind bext, fails
open, and never edits `wp-config.php` or disables third-party plugins on its own.

## Why

Running WordPress behind a reverse cache usually forces a bad trade-off:

- **Long TTL** → fast, but visitors see stale content after an edit.
- **Short TTL** → fresh, but the first visitor after every cycle eats a full PHP render
  (measured at ~4.4 s on a real WooCommerce site).

`bext-wp` removes the trade-off: bext keeps a **long TTL**, and WordPress **tells bext exactly
which URLs changed** the moment they change — so the cache is both fresh and fast. It also kills
Action Scheduler's loopback `admin-ajax` self-calls (measured at 5–21 s), which otherwise tie up
PHP-FPM workers.

## Features

| Module | What it does |
|---|---|
| **Cache** | Purge-on-change: hooks post/term/menu/option/WooCommerce events, computes the affected URLs (permalink + home + archives + feeds + sitemap), coalesces them, and fires **one** non-blocking purge to bext on `shutdown` (after `fastcgi_finish_request`, so editors never wait). Personalization-safe `Cache-Control` (logged-in/cart/comment → `private, no-store`). Manual purge from the admin bar + WP-CLI. |
| **Cron** | Disables Action Scheduler's async loopback runner (defers to the existing system cron), bounds batch concurrency/time. |
| **Health** | Surfaces misconfiguration and known-noisy plugins; optional capture of recent PHP warnings. |
| **Admin** | A wp-admin **Bext** dashboard + admin-bar status pill: integration status, purge log, Action Scheduler queue depth, health checks, bext server reachability. |
| **SDK** *(opt-in)* | Route `wp_mail` through bext's managed email send; enqueue background jobs onto a bext queue. Fails open to WordPress's own path. |

## Requirements

- WordPress 5.8+, PHP 7.4+
- The site must be served by **bext**. For deterministic detection, bext sends the
  `BEXT_SERVER=<version>` FastCGI param (presence ⇒ behind bext). Without it the plugin falls
  back to a sticky auto-detect (the `x-bext-cache-refresh` request header) or the
  `BEXT_WP_ASSUME_BEHIND_BEXT` constant.
- All loopback calls — cache purge (`POST /__bext/cache/purge-proxy`, which surgically evicts the
  in-memory FastCGI cache) and the SDK (`/__bext/sdk/*`) — go to the bext main listener on
  `127.0.0.1:80`. No extra port or file-system access (open_basedir-safe).

## Install

### As a must-use plugin (recommended for a fleet)

`open_basedir` prevents a shared symlink, so the package is **copied** into each site:

```bash
sudo bin/deploy-fleet.sh --list                  # see discovered WP sites
sudo bin/deploy-fleet.sh --site=example.com      # canary one site
sudo bin/deploy-fleet.sh                          # all sites
```

This writes `wp-content/mu-plugins/bext.php` (loader) + `wp-content/mu-plugins/bext-wp/` (package)
and chowns them to the site user.

### As a normal plugin

Drop the repository into `wp-content/plugins/bext-wp/` and activate **Bext for WordPress**.

## Configuration (`wp-config.php`)

All optional — sensible defaults otherwise.

```php
define( 'BEXT_WP_ENABLE', true );              // master switch (default true)
define( 'BEXT_WP_APP_ID', 'my-site' );         // X-Bext-App-Id for the SDK bridge
define( 'BEXT_WP_ASSUME_BEHIND_BEXT', true );  // force-on detection
define( 'BEXT_WP_SDK_EMAIL', true );           // wp_mail via bext (needs per-app SMTP config)
define( 'BEXT_WP_SDK_JOBS', true );            // enable bext/enqueue
define( 'BEXT_WP_CAPTURE_WARNINGS', true );    // record recent PHP warnings (dev)
// Disable a module: define( 'BEXT_WP_DISABLE_SDK', true );
```

The sticky detection flag and all options are removed on plugin deletion (`uninstall.php`) and on
deactivation (normal-plugin installs).

### Filters

```php
add_filter( 'bext/enable_cache', '__return_false' );          // disable a module
add_filter( 'bext/purge_urls_for_post', $fn, 10, 2 );         // customize purged URLs
add_filter( 'bext/anonymous_cache_control', fn() => 'public, max-age=300, stale-while-revalidate=86400' );
add_filter( 'bext/as_concurrent_batches', fn() => 2 );        // Action Scheduler concurrency
do_action( 'bext/enqueue', 'my-queue', [ 'job' => 'x' ] );    // enqueue a job (fire-and-forget)
$id = apply_filters( 'bext/enqueue', null, 'my-queue', [ 'job' => 'x' ] ); // …and get the job id
```

Other hooks: `bext/enable_{cron,health,admin,sdk}`, `bext/as_time_limit`, `bext/system_cron_expected`,
`bext/health_checks`, `bext/enable_warning_capture`, `bext/sdk_email_fallback` (fires when bext
can't take an email and WP sends it instead), and the `bext/booted` action.

## WP-CLI

```bash
wp bext status                          # integration status
wp bext purge                           # purge entire site cache
wp bext purge /blog/hello-world/        # purge one path (positional)
wp bext doctor                          # run health checks
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
