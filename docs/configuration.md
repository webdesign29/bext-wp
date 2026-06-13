# Configuration

bext-wp resolves each option with this precedence (**highest wins**):

1. **`wp-config.php` constant** — `BEXT_WP_*`
2. **Settings page** — *Bext → Settings* (stored in the `bext_wp_settings` option)
3. **Built-in default**

So you can ship a locked configuration via constants, let site admins tweak the rest in the UI,
and fall back to safe defaults otherwise. Filters apply on top where noted in [hooks.md](hooks.md).

## Settings page

*Bext → Settings* (`manage_options`):

| Field | Meaning |
|---|---|
| **Mode** | `Auto` (bext on this server, loopback), `Cloud` (remote bext endpoint), or `Off`. |
| **Cloud endpoint URL** | Cloud mode only — the bext origin serving this site. |
| **Cloud API token** | Cloud mode only — sent as `Authorization: Bearer …`. |
| **App ID** | `X-Bext-App-Id` for the SDK bridge. Defaults to the site host. |
| **Edge cache** | Cooperate with the bext cache (purge-on-change + safe headers). |
| **Purge on save** | Auto-purge changed URLs when content is edited. |
| **Anonymous Cache-Control** | Optional header for anonymous pages. Blank = defer to the vhost. |
| **Action Scheduler taming** | Disable the admin-ajax async runner; defer to system cron. |
| **Health diagnostics** | Run the dashboard config checks. |
| **Capture PHP warnings** | Record recent warnings (dev/debug). |
| **Email via bext** | Route `wp_mail()` through bext managed email. |
| **Jobs via bext** | Enable `bext/enqueue` background jobs. |

A **Test connection** button hits `<endpoint>/__bext/health` with the saved settings.

## Constants (`wp-config.php`)

```php
define( 'BEXT_WP_ENABLE', true );              // master switch (default true)
define( 'BEXT_WP_MODE', 'cloud' );             // auto | cloud | off
define( 'BEXT_WP_CLOUD_URL', 'https://…' );    // cloud endpoint
define( 'BEXT_WP_CLOUD_TOKEN', '…' );          // cloud bearer token
define( 'BEXT_WP_APP_ID', 'my-site' );         // X-Bext-App-Id
define( 'BEXT_WP_ASSUME_BEHIND_BEXT', true );  // force-on detection (auto mode)
define( 'BEXT_WP_SDK_EMAIL', true );           // wp_mail via bext
define( 'BEXT_WP_SDK_JOBS', true );            // bext/enqueue
define( 'BEXT_WP_CAPTURE_WARNINGS', true );    // record PHP warnings
define( 'BEXT_WP_DISABLE_CACHE', true );       // hard-disable a module (CACHE|CRON|HEALTH|SDK)
```

## Detection (auto mode)

The plugin only acts when it's actually behind bext. In **auto** mode it detects via the
`BEXT_SERVER` FastCGI param (set by bext), the `x-bext-cache-refresh` header, or a sticky
one-time flag. Cache **HITs never reach PHP**, so detection fires on the first cache-miss/PHP
request. In **cloud** mode, configuring an endpoint is itself the signal. The sticky flag is
cleared on deactivation/uninstall.

## Removal

- **Normal plugin:** deactivating clears the detection flag; deleting runs `uninstall.php` to
  remove all `bext_wp_*` options (multisite-aware).
- **Must-use:** `sudo bin/deploy-fleet.sh --remove [--site=…]`.
