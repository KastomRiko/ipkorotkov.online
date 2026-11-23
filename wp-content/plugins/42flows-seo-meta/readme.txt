=== 42flows SEO Meta ===
Contributors: 42flows
Tags: seo, rest-api, yoast, rank-math, aioseo
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Registers SEO meta fields for WordPress REST API write access. Required for 42flows content delivery.

== Description ==

42flows SEO Meta enables REST API write access to SEO meta fields for posts and pages. It auto-detects your active SEO plugin (Yoast SEO, Rank Math, or All in One SEO) and registers only the relevant meta fields with `show_in_rest`.

This plugin is required if you use [42flows](https://42flows.com) to publish SEO-optimized content to your WordPress site. Without it, SEO titles, meta descriptions, and focus keyphrases cannot be set via the REST API.

= Supported SEO plugins =

* **Yoast SEO** — `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw`
* **Rank Math** — `rank_math_title`, `rank_math_description`, `rank_math_focus_keyword`
* **All in One SEO** — `_aioseo_title`, `_aioseo_description`, `_aioseo_keywords`

= What it does =

* Detects which SEO plugin is active on your site
* Registers that plugin's meta fields with `show_in_rest => true` for posts and pages
* Adds `sanitize_callback` (sanitize_text_field) and `auth_callback` (requires edit_posts capability)
* Does nothing if no supported SEO plugin is detected

= What it does NOT do =

* Does not modify your existing SEO data
* Does not add any admin pages, settings, or UI
* Does not load any JavaScript or CSS
* Does not store any data

= Companion plugin installation and upgrades =

When your site is connected to [42flows](https://42flows.com), 42flows may request that this plugin install, activate, or upgrade a companion plugin (`42flows-content`) that enables render-time features for posts delivered by the 42flows pipeline (schema markup, attribution footer, page-optimizer REST surface). The companion plugin is downloaded from `https://42flows.com/plugins/42flows-content-latest.zip` via the `/wp-json/42flows/v1/install-companion` REST route.

The route requires the requesting user to hold:

* `install_plugins` and `activate_plugins` for fresh installs (typically Administrator role)
* `delete_plugins` additionally when a `min_version` parameter is supplied that exceeds the currently-installed companion version, because the upgrade path deletes the existing companion folder before reinstalling

The companion zip URL is hard-coded in the plugin source — it is never user-supplied — so the route presents no SSRF surface. No installation, upgrade, or deletion occurs unless your site is connected to 42flows and the request is authenticated by an administrator-capability user.

== Installation ==

1. Install the plugin from the WordPress plugin directory, or upload the ZIP file via Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. No configuration needed — the plugin auto-detects your SEO plugin.

== Frequently Asked Questions ==

= Do I need this plugin? =

Only if you use 42flows to publish content to your WordPress site and want SEO meta fields (title, description, focus keyphrase) set automatically via the REST API.

= What if I don't have an SEO plugin? =

The plugin does nothing if no supported SEO plugin (Yoast, Rank Math, or AIOSEO) is detected. It is safe to keep installed.

= Is it safe? =

Yes. It only registers existing meta fields for REST API access with proper sanitization and authentication. Only users with the `edit_posts` capability can write to these fields. All input is sanitized with `sanitize_text_field`.

The companion-install REST route requires `install_plugins` + `activate_plugins` capabilities, and additionally `delete_plugins` for in-place upgrades. The companion plugin source URL is hard-coded — never accepted from request input — so there is no SSRF surface. The optional `min_version` parameter is validated against a strict `X.Y.Z` regex before being passed to PHP's `version_compare`.

= Does it work with custom post types? =

Currently it registers meta fields for posts and pages. Support for custom post types can be added in a future version.

== Changelog ==

= 1.2.1 =
* **Auto-enable WordPress plugin auto-updates for 42flows plugins.** On every successful `/install-companion` call, both `42flows-seo-meta` and `42flows-content` are added to WordPress's `auto_update_plugins` site option so future feature/security/fix releases reach the customer site without a manual click. Idempotent — safe to call repeatedly. Customers retain control: the WP admin Plugins page still shows a "Disable auto-updates" link.
* Why: customers connect their site to 42flows specifically so 42flows manages the meta + render layer; asking them to also manually toggle plugin auto-updates per plugin is onboarding friction with no upside (every WP-side plugin update of these two plugins comes from us anyway).

= 1.2.0 =
* Add optional `min_version` parameter to `/wp-json/42flows/v1/install-companion`. When supplied (semver `X.Y.Z`), the route compares the installed companion version against `min_version` and performs an in-place upgrade (deactivate → delete → reinstall → activate) if the installed version is older. Required because WordPress's `Plugin_Upgrader::install` refuses to overwrite an existing plugin folder. Without this parameter, behavior is unchanged: idempotent ensure-installed-and-active.
* Upgrade path additionally requires the authenticated user to hold the `delete_plugins` capability, in line with the principle of least privilege.
* `min_version` is validated as `^\d+\.\d+\.\d+$` before reaching `version_compare()` to reject arbitrary input.
* Code refactored: install logic deduplicated into a shared helper used by both fresh-install and upgrade paths.

= 1.1.0 =
* Add REST route `/wp-json/42flows/v1/install-companion` for authenticated companion-plugin installation (requires `install_plugins` capability). Used only when the site is connected to 42flows.

= 1.0.0 =
* Initial release.
* Support for Yoast SEO, Rank Math, and All in One SEO.
* Meta fields registered for posts and pages.
