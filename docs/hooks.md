# Hooks reference

All hooks are namespaced `bext/`.

## Filters

| Filter | Args | Default | Purpose |
|---|---|---|---|
| `bext/enable_cache` | `bool` | setting/true | Enable the Cache module. |
| `bext/enable_cron` | `bool` | setting/true | Enable the Cron module. |
| `bext/enable_health` | `bool` | setting/true | Enable the Health module. |
| `bext/enable_sdk_email` | `bool` | `true` | Allow the wp_mail bridge (also needs the setting/constant). |
| `bext/enable_sdk_jobs` | `bool` | `true` | Allow the job bridge. |
| `bext/enable_warning_capture` | `bool` | `true` | Allow PHP-warning capture (when capture is on). |
| `bext/purge_urls_for_post` | `string[] $paths, int $post_id` | computed set | Customize which relative paths are purged for a post. |
| `bext/anonymous_cache_control` | `string` | the setting | `Cache-Control` for anonymous pages (empty = defer to vhost). |
| `bext/as_concurrent_batches` | `int` | `1` | Action Scheduler concurrent batches. |
| `bext/as_time_limit` | `int` | `min(20,…)` | Action Scheduler per-run time limit (s). |
| `bext/system_cron_expected` | `bool` | `DISABLE_WP_CRON` | Whether a system cron drives Action Scheduler. |
| `bext/health_checks` | `array $checks` | built-in | Add/modify dashboard health checks. |
| `bext/enqueue_job` | `mixed $default, string $name, mixed $payload, int|null $delay` | `$default` | **Filter form** — returns the job id. |

```php
// Only purge the permalink + home for a post, nothing else:
add_filter( 'bext/purge_urls_for_post', function ( $paths, $post_id ) {
    return array( '/', wp_make_link_relative( get_permalink( $post_id ) ) );
}, 10, 2 );

// Enqueue a job and get its id:
$id = apply_filters( 'bext/enqueue_job', null, 'emails', array( 'to' => 'a@b.co' ) );
```

## Actions

| Action | Args | Fires |
|---|---|---|
| `bext/booted` | `Plugin $plugin` | After all modules boot. |
| `bext/enqueue` | `string $name, mixed $payload, int|null $delay` | **Action form** — fire-and-forget enqueue. |
| `bext/sdk_email_fallback` | `mixed $res, array $atts` | When bext can't take an email and WordPress sends it instead. |

```php
// Log when the bext email bridge falls back to WP:
add_action( 'bext/sdk_email_fallback', function ( $res, $atts ) {
    error_log( 'bext email fallback: ' . wp_json_encode( $res ) );
}, 10, 2 );

// Fire-and-forget background job:
do_action( 'bext/enqueue', 'thumbnails', array( 'attachment' => 42 ) );
```

## WP-CLI

```bash
wp bext status              # integration status
wp bext purge              # purge the whole site cache
wp bext purge /about/      # purge one path (positional; --url/--path are WP-CLI globals)
wp bext doctor             # run health checks
```
