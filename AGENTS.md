# AGENTS.md

## Scope
- This repository is a standalone WordPress plugin. There is no `composer.json`, `package.json`, CI workflow, or test runner here; do not invent Composer/npm tasks.

## Entry Points
- `wp-gemini-watermark-remover.php` is the bootstrap: it defines `DGZ_*` constants, requires both classes, and instantiates `DGZ_Plugin` on `plugins_loaded`.
- `includes/class-plugin.php` owns the WordPress wiring: admin asset enqueue, attachment panel UI, AJAX remove/restore handlers, bulk actions, cache-busting filters, backup/version post meta, and thumbnail regeneration.
- `includes/class-watermark-remover.php` owns the GD-based image logic: corner detection, adaptive mask + inpainting, and clone-stamp fallback.
- `assets/js/media-button.js` handles the admin AJAX buttons and forces preview refreshes after success.

## Verification
- Available automated check: `php -l wp-gemini-watermark-remover.php && php -l includes/class-plugin.php && php -l includes/class-watermark-remover.php`
- Real behavior must be verified in `wp-admin`:
- individual flow: open an image in the attachment details/modal and test `De-Geminizer` plus `Restaurar original`
- bulk flow: use Media Library list view (`upload.php`); bulk actions are registered on `bulk_actions-upload`, so grid view is not the source of truth

## Preserve
- `enqueue_admin_assets()` intentionally loads on all admin screens because the media modal can be opened from many places. Do not scope it down without testing modal usage outside the Media Library screen.
- Successful remove/restore flows depend on three coupled behaviors: backup file handling, `bump_version()`, and `regenerate_metadata()`. Keep them together.
- Persistent cache-busting comes from the PHP filters adding `?dgz=N`; the JS `?t=` query only refreshes currently visible previews.
- `regenerate_metadata()` deletes existing generated thumbnails before `wp_generate_attachment_metadata()` and updates `post_modified`; skipping this leaves stale thumbnails and caches behind.
- Original backups are sibling files named `*.dgz-backup.ext` and tracked in `_dgz_backup_path`; attachment cache versions live in `_dgz_version`.
- Image processing requires PHP GD and preserves the original format on save. If you touch image I/O, keep PNG alpha handling and the current JPEG/PNG/WebP/GIF support intact.
- User-facing strings are Spanish in both docs and admin UI; keep new UI copy consistent unless the task explicitly changes locale.
