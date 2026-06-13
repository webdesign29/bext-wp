# Contributing to bext-wp

Thanks for helping improve the WordPress ↔ bext integration!

## Development setup

The plugin is dependency-free at runtime. For tooling:

```bash
composer install      # phpcs (WordPress Coding Standards) + PHP-compat
```

## Before you push

```bash
# 1. Lint every PHP file (must pass on PHP 7.4+).
find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l

# 2. Run the unit tests (no WordPress runtime required).
for t in tests/unit/*.php; do php "$t" || break; done

# 3. Coding standards.
vendor/bin/phpcs
```

CI runs the same lint + unit tests on PHP 7.4 / 8.1 / 8.3.

## Guidelines

- **PHP 7.4 compatible.** No `match`, nullsafe `?->`, `str_contains`/`str_starts_with`,
  named args, constructor promotion, or enums. (`php -l` on 8.x will *not* catch 8.0-only
  APIs — keep it in mind.)
- **Fail open.** Every call to bext is wrapped so a failure can never break a WordPress
  request. New network calls must be `is_wp_error`-checked and time-bounded.
- **Escape on output, sanitize on input.** Admin HTML uses `esc_html`/`esc_url`/`esc_attr`;
  handlers verify a nonce and `current_user_can`.
- **No secrets in the repo.** Tokens/keys are configured via the Settings page or
  `wp-config.php` constants, never committed.
- **Match the surrounding style** (WordPress-ish, tabs, Yoda not required but consistent).

## Architecture

| File | Responsibility |
|---|---|
| `bext-wp.php` / `mu-loader.php` | Bootstrap (dual-mode: normal or must-use plugin) |
| `src/Plugin.php` | Singleton; boots modules |
| `src/Env.php` | Config resolution (constant > setting > default) + loopback/cloud transport |
| `src/Settings.php` | wp-admin settings page (`bext_wp_settings` option) |
| `src/Cache.php` | Purge-on-change + personalization-safe headers |
| `src/Cron.php` | Action Scheduler taming |
| `src/Health.php` | Config checks + warning capture |
| `src/Admin.php` | Dashboard + admin-bar pill |
| `src/SDK.php` | wp_mail + job bridge |
| `cli/Commands.php` | `wp bext …` |

## Releasing

Bump the version in `bext-wp.php` (header + `BEXT_WP_VERSION`) and `mu-loader.php`, add a
`CHANGELOG.md` entry, tag `vX.Y.Z`. The fleet deploy (`bin/deploy-fleet.sh`) reads the version
from the plugin header.
