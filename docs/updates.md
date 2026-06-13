# Auto-updates

Bext for WordPress ships outside the wordpress.org directory, so it brings its own update channel —
giving normal-plugin installs the same "update available" badge and one-click update as a
wordpress.org plugin.

## How it works

1. The plugin polls a self-hosted **manifest** (JSON) — by default
   `https://wp-plugins.inklura.fr/api/update?slug=bext-wp` — and caches the result for 12 hours
   (a site transient).
2. If the manifest's `version` is newer than the installed one, the plugin injects an entry into
   WordPress's `update_plugins` transient, so **Plugins → Installed Plugins** shows the update and
   the normal one-click update works. The "View details" modal is populated too.
3. The update **package** is a GitHub release asset
   (`https://github.com/webdesign29/bext-wp/releases/latest/download/bext-wp.zip`). The updater
   normalises the extracted folder name so the update installs back into
   `wp-content/plugins/bext-wp/`.

```
WordPress (cron / Plugins screen)
   │  GET https://wp-plugins.inklura.fr/api/update?slug=bext-wp   (cached 12 h)
   ▼
{ "version": "0.4.3", "download_url": ".../releases/latest/download/bext-wp.zip", … }
   │  newer than installed?
   ▼
update_plugins transient ──► one-click update ──► download ZIP from GitHub ──► installed
```

## Must-use installs

Must-use plugins can't be updated through wp-admin, so the updater **does not** register for them.
Update a fleet of mu-plugin installs with the deploy script instead:

```bash
sudo bin/deploy-fleet.sh        # re-copy the latest into every site
```

## Configuration

```php
// Point at a different manifest (e.g. your own mirror):
define( 'BEXT_WP_UPDATE_URL', 'https://updates.example.com/bext-wp.json' );
```
```php
add_filter( 'bext/update_manifest_url', fn() => 'https://updates.example.com/bext-wp.json' );
```

## Releasing (maintainers)

1. Bump the version (`bext-wp.php` header + `BEXT_WP_VERSION`, `mu-loader.php`) and update
   `CHANGELOG.md`.
2. Commit, push, then build + publish:
   ```bash
   bin/build-zip.sh /tmp/bext-wp.zip
   gh release create vX.Y.Z /tmp/bext-wp.zip --title "vX.Y.Z" --notes "…"
   ```
3. Bump `version` (and `changelog`) in the manifest source
   (`wp-plugins.inklura.fr` → `src/lib/manifest.ts`) and redeploy that site.

The manifest's `download_url` is the stable `releases/latest/download/bext-wp.zip`, so it never
changes between releases.
