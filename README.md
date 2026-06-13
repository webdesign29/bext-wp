# Bext for WordPress

[![CI](https://github.com/webdesign29/bext-wp/actions/workflows/ci.yml/badge.svg)](https://github.com/webdesign29/bext-wp/actions/workflows/ci.yml)
![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)
![PHP 7.4+](https://img.shields.io/badge/php-7.4%2B-8892bf.svg)
![WordPress 5.8+](https://img.shields.io/badge/wordpress-5.8%2B-21759b.svg)

> Make WordPress cooperate with the [bext](https://bext.dev) server — local or **bext cloud** —
> instead of fighting it.

`bext-wp` is a small, dependency-free WordPress plugin (usable as a **must-use** plugin or a normal
one) for sites served behind **bext**. It turns bext's edge cache from a blunt TTL into a precise,
always-fresh cache, tames Action Scheduler, keeps personalized responses out of the anonymous
cache, and gives operators a real dashboard — all configurable from **Bext → Settings**.

Safe by default: every feature no-ops when the site isn't behind bext, fails open, and never edits
`wp-config.php` or disables third-party plugins on its own.

## Why

Running WordPress behind a reverse cache usually forces a bad trade-off:

- **Long TTL** → fast, but visitors see stale content after an edit.
- **Short TTL** → fresh, but the first visitor after each cycle eats a full PHP render
  (measured at ~4.4 s on a real WooCommerce site).

`bext-wp` removes the trade-off: bext keeps a **long TTL**, and WordPress **tells bext exactly
which URLs changed** the moment they change — so the cache is both fresh and fast. It also kills
Action Scheduler's loopback `admin-ajax` self-calls (measured at 5–21 s) that tie up PHP-FPM
workers.

## Features

| Module | What it does |
|---|---|
| **Cache** | Purge-on-change: hooks post/term/menu/option/comment/WooCommerce events, computes the affected URLs (permalink + home + archives + feeds + sitemap), coalesces them, and fires **one** non-blocking surgical purge on `shutdown`. Personalization-safe `Cache-Control`. Manual purge from the admin bar + WP-CLI. |
| **Cron** | Disables Action Scheduler's async loopback runner (defers to the existing system cron), bounds batch concurrency/time. |
| **Health** | Config checks, known-noisy-plugin detection, optional capture of recent PHP warnings. |
| **Admin** | A wp-admin **Bext** dashboard + admin-bar status pill: integration status, purge log, Action Scheduler queue depth, health checks, server reachability. |
| **Settings** | Configure everything from the UI — connection mode (local / **cloud**), endpoint + token, modules, cache behavior, SDK — no `wp-config` editing needed. |
| **Multisite** | Network-admin settings (defaults + **Enforce**), a cross-site dashboard with per-site & all-sites purge, and subdirectory-aware purge/App-ID. See [docs/multisite.md](docs/multisite.md). |
| **SDK** *(opt-in)* | Route `wp_mail` through bext's managed email send; enqueue background jobs onto a bext queue. Both fail open. |
| **Auto-update** | Self-hosted updates for normal-plugin installs — "update available" + one-click update from a manifest, like a wordpress.org plugin (must-use installs update via `deploy-fleet.sh`). |

## How it connects

- **Auto** (default) — bext runs on the same server; everything uses the loopback main listener
  (`127.0.0.1:80`). No credentials, open_basedir-safe. bext is detected via the `BEXT_SERVER`
  FastCGI param.
- **Cloud** — WordPress talks to a **remote** bext endpoint with a bearer token. See
  [docs/cloud.md](docs/cloud.md).

## Install

### As a must-use plugin (recommended for a fleet)

`open_basedir` prevents a shared symlink, so the package is **copied** into each site:

```bash
sudo bin/deploy-fleet.sh --list                  # see discovered WP sites
sudo bin/deploy-fleet.sh --site=example.com      # canary one site
sudo bin/deploy-fleet.sh                          # all sites
```

### As a normal plugin

Put the repository in `wp-content/plugins/bext-wp/` and activate **Bext for WordPress**.

## Configure

Open **Bext → Settings** in wp-admin and pick a mode. Or lock it down in `wp-config.php`:

```php
define( 'BEXT_WP_MODE', 'auto' );        // auto | cloud | off
// Cloud mode:
define( 'BEXT_WP_CLOUD_URL',   'https://www.example.com' );
define( 'BEXT_WP_CLOUD_TOKEN', 'a-long-random-secret' );
```

Full reference: **[docs/configuration.md](docs/configuration.md)** · Hooks: **[docs/hooks.md](docs/hooks.md)**.

## WP-CLI

```bash
wp bext status                 # integration status
wp bext purge                  # purge entire site cache
wp bext purge /blog/hello/     # purge one path (positional)
wp bext doctor                 # run health checks
```

## Testing

```bash
for t in tests/unit/*.php; do php "$t"; done   # WP-free unit tests
```

CI lints on PHP 7.4/8.1/8.3 and runs the unit tests. See [CONTRIBUTING.md](CONTRIBUTING.md).

## Sponsors

bext-wp is built and maintained with the support of:

- **[webdesign29](https://webdesign29.net)** — web agency (Brest, France).
- **[Inklura](https://inklura.fr)** — WordPress-compatible CMS & hosting.

Interested in sponsoring? Use the **Sponsor** button or open an issue.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
