=== jPlugin Formbuilder ===
Contributors: jirath
Tags: forms, contact form, survey, turnstile, private upload
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later

Secure, theme-adaptive WordPress forms with a visual builder, private uploads, Cloudflare Turnstile, and a standalone submission inbox.

== Description ==

jPlugin Formbuilder stores form definitions and responses in dedicated plugin tables. Public file uploads are kept out of the WordPress Media Library and are downloaded through a capability-protected endpoint.

Features:

* Visual single-page form builder with accessible field ordering controls.
* Contact, opinion survey, satisfaction, and event registration templates.
* Dynamic Gutenberg block and `[jplugin_form id="UUID"]` shortcode.
* Cloudflare Turnstile, honeypot, timing checks, and hashed-IP rate limiting.
* Private uploads with MIME/content checks and a direct-access Site Health test.
* Submission inbox, retention, trash, secure download, email notification, and CSV export.
* Theme-adaptive CSS variables and per-form color palette.

== Installation ==

1. Copy this directory to `wp-content/plugins/jPlugin-Formbuilder`.
2. Activate jPlugin Formbuilder.
3. Open Formbuilder > Settings and configure Turnstile and appearance.
4. Create a form from a template, review the fields, and publish it.
5. Insert the jPlugin Form block or use the shortcode displayed by the form UUID.

For stronger private storage, define an absolute path outside the public document root:

`define( 'JFB_PRIVATE_UPLOAD_DIR', '/secure/persistent/path/jfb-private' );`

The directory must be writable by PHP and persistent across deployments.

== Security Notes ==

* Turnstile secrets can be set with `JFB_TURNSTILE_SITE_KEY` and `JFB_TURNSTILE_SECRET_KEY`; constants override database values.
* Do not expose the origin server around Cloudflare. The plugin trusts `CF-Connecting-IP` only when the immediate peer matches cached Cloudflare CIDR ranges.
* Plugin rate limiting runs after the request reaches PHP and does not replace Cloudflare WAF or edge rate limiting.
* Public uploads reject SVG, HTML, executable files, archives, double extensions, MIME mismatches, and files above the configured limit.

== Uninstall and Manual Data Cleanup ==

Uninstall is intentionally non-destructive. Deleting the plugin does NOT delete forms, submissions, settings, rate-limit data, or private files.

After confirming that backups are complete, a database administrator may manually remove these tables (replace `wp_` with the site's real prefix):

* `wp_jfb_forms`
* `wp_jfb_submissions`
* `wp_jfb_submission_files`
* `wp_jfb_rate_limits`

The following WordPress options/transients may also be removed:

* `jfb_settings`
* `jfb_schema_version`
* `_transient_jfb_cf_ranges` and its timeout
* `_transient_jfb_vault_probe` and its timeout

Private files are stored in `JFB_PRIVATE_UPLOAD_DIR` when defined, otherwise in `wp-content/jfb-private`. Remove this directory manually only after confirming that retained submissions no longer need their files.

== Changelog ==

= 1.0.0 =
* Initial secure standalone release.

