=== Ultimizer ===
Contributors: kerackdiaz
Tags: image optimization, compress images, avif, webp, bulk optimize
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Compress JPEG, PNG, GIF and WebP images, generate AVIF and WebP sidecars, and serve them automatically — all on your server, no external APIs.

== Description ==

Ultimizer optimizes your WordPress media library directly on your server using Imagick (with GD as fallback). No files are sent to external services.

**Features:**

* Compress JPEG, PNG, GIF and WebP on upload or in bulk
* Generate AVIF and WebP sidecar files automatically
* Serve modern formats via `<picture>` tags on the frontend
* Back up originals before any modification — restore with one click
* Strip image metadata to reduce file size further
* Bulk optimizer with real-time progress and savings stats
* Optimization log with per-image details
* No API keys, no external services, no subscriptions

**Requirements:**

* PHP extension `imagick` (recommended) or `gd`

== Installation ==

1. Upload the `ultimizer` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Settings → Ultimizer** to configure and run the bulk optimizer

== Frequently Asked Questions ==

= Does it require an account or API key? =
No. All processing happens server-side using PHP extensions.

= What happens to my original images? =
Before optimizing, Ultimizer creates a backup. You can restore any image to its original from the Backups tab.

= Which PHP extension do I need? =
Imagick is recommended for best results. GD is used as a fallback if Imagick is not available.

= Does it work with existing images? =
Yes. Use the bulk optimizer in the plugin panel to process your entire media library.

== Screenshots ==

1. Main panel with bulk optimizer and real-time progress
2. Settings tab for compression quality
3. Optimization log
4. Backups tab with restore and delete options

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release.
