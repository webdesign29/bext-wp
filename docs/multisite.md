# Multisite

`bext-wp` is fully WordPress-Multisite-aware.

## Network Admin

Network-activate the plugin (or deploy it as a must-use plugin) and a **Bext** menu appears in
**Network Admin** (super-admins, `manage_network_options`):

- **Network defaults** — the same settings as the per-site page, applied network-wide. Turn on
  **Enforce on all sites** to make them override each site's own settings.
- **Sites** — a table of every site (bext status, mode, last purge) with **Purge** per site and
  **Purge all sites**.

## Settings precedence

Each setting resolves as (**highest wins**):

```
wp-config constant  >  network (Enforce on)  >  site setting  >  network (default)  >  built-in default
```

So you can:
- set sensible **network defaults** that new sites inherit, while letting each site override; or
- **Enforce** to lock configuration network-wide.

> **Note:** once a site **saves** its own *Bext → Settings* page, its choices are stored
> explicitly and take precedence over non-enforced network defaults (a saved setting is a real
> value, not "inherit"). To push a value to sites that have already configured themselves, turn on
> **Enforce**.

When enforcement is on, the per-site **Bext → Settings** page shows a notice that some values are
managed at the network level.

## App ID & cache keys

bext keys its cache by **host**. That means:

- **Subdomain multisite** (`a.example.com`, `b.example.com`) — each blog has a unique host, so
  purges and the SDK App ID are naturally isolated.
- **Subdirectory multisite** (`example.com/a`, `example.com/b`) — blogs share a host. The plugin:
  - scopes a **site-wide purge** to the blog's path prefix (e.g. `/a/`) so it doesn't wipe sibling
    blogs, and
  - disambiguates the default **App ID** as `host-path` (e.g. `example.com-a`) so the SDK
    queue/email config doesn't collide between blogs.

## Cross-blog correctness

Per-request caches (settings, detection, host) are reset on `switch_blog`, so network operations
that loop over blogs always read the correct blog's configuration.

## Purging from the network via WP-CLI

```bash
# Purge every site:
for url in $(wp site list --field=url); do wp --url="$url" bext purge; done

# Purge one path on one site:
wp --url=https://example.com/blog-a/ bext purge /hello/
```

(`--url` here is WP-CLI's global flag selecting the site; the bext purge target is the positional
path — see [WP-CLI](https://github.com/webdesign29/bext-wp/wiki/WP-CLI).)

## Uninstall

`uninstall.php` removes all `bext_wp_*` options on **every** site in the network. The
`bext_wp_network_settings` site-option is also removed.
