# Using bext-wp with bext cloud

bext-wp has two transport modes:

- **Auto** — WordPress is served by a bext server on the same machine. All calls go to the
  loopback main listener (`http://127.0.0.1:80`) and are gated by bext's loopback check. No
  credentials needed. This is the default.
- **Cloud** — WordPress talks to a **remote** bext endpoint (e.g. your site behind bext cloud).
  Calls go to the configured URL with a bearer token.

## When to use Cloud mode

Use Cloud mode when the PHP process is **not** on the same host as the bext edge — for example a
managed-WordPress box behind a bext cloud edge, or any split where loopback can't reach bext.

## WordPress side

*Bext → Settings*:

1. **Mode** → `Cloud`.
2. **Cloud endpoint URL** → the bext origin that serves your site, e.g. `https://www.example.com`.
3. **Cloud API token** → the purge token (see below).
4. **Test connection** to confirm `<endpoint>/__bext/health` is reachable.

…or in `wp-config.php`:

```php
define( 'BEXT_WP_MODE', 'cloud' );
define( 'BEXT_WP_CLOUD_URL', 'https://www.example.com' );
define( 'BEXT_WP_CLOUD_TOKEN', 'a-long-random-secret' );
```

## bext side

Remote purges are **opt-in**. Set a token on the bext server and the
`POST /__bext/cache/purge-proxy` endpoint will accept requests carrying a matching
`Authorization: Bearer <token>` (constant-time compared), in addition to the existing loopback
and admin-JWT paths:

```bash
# /etc/default/nginx (or the service EnvironmentFile), then full restart:
BEXT_PURGE_TOKEN=a-long-random-secret
```

The same value goes in the plugin's **Cloud API token**. Without the env var set, remote purges
are rejected (the endpoint stays loopback/admin-only).

> **Security:** the token authorizes purges for the `host` in the request body. Use a long random
> secret over HTTPS, and rotate it by updating both sides. Per-tenant scoping (a token limited to
> one host) is a planned enhancement; today a token is trusted for any host it names.

## What works in each mode

| Feature | Auto | Cloud |
|---|---|---|
| Purge-on-change / manual purge | ✅ loopback | ✅ bearer token |
| Personalization-safe headers | ✅ | ✅ |
| Action Scheduler taming | ✅ | ✅ |
| Dashboard / health | ✅ | ✅ (health via the endpoint) |
| SDK email / jobs | ✅ loopback app-id | ⚠️ requires the endpoint to authorize the SDK; falls back to WP otherwise |
