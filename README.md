# WP Media Cleanup

Find dead weight in your WordPress media library and reclaim disk space — in bulk, with a confirm step.

## What it finds

| Section | Meaning |
|---|---|
| **Unattached media** | Attachments with no parent post |
| **Duplicate filenames** | Same filename uploaded more than once |
| **Large files** | Uploads ≥ 2 MB (threshold filterable) |
| **Orphaned files on disk** | Files under `wp-content/uploads` not referenced by any attachment (skips generated thumbnails) |

Everything is listed with a checkbox; nothing is deleted until you hit **Delete selected** and confirm. Library items are force-deleted via `wp_delete_attachment()`, orphaned files are `unlink()`ed (path safety-checked to stay inside the uploads dir).

## Filters

```php
// Scan this many attachments per page (default 500):
add_filter( 'wmc_scan_limit', function() { return 1000; } );

// Change the "large file" threshold (default 2 MB):
add_filter( 'wmc_large_file_bytes', function() { return 5 * 1024 * 1024; } );
```

## Install

Upload to `wp-content/plugins/`, activate, go to **Tools → Media Cleanup**.

## License

MIT
