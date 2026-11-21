# Changelog

## 0.3.0
- File handling now uses WordPress APIs: `WP_Filesystem` (direct method, own folder in uploads only) for reads, writes, copies and the atomic folder swap, `wp_delete_file()`, `wp_tempnam()`, `wp_is_writable()`, and `ZipArchive::extractTo()` for extraction. Only `readfile()` remains, for streaming large assets.
- Zip archives are read once without writing before they are extracted; an archive whose real file sizes differ from its headers is refused (PHP's zip library does not enforce the header sizes). Entries with backslashes in their names are refused.
- The attributes of the uploaded `<html>` and `<body>` tags are rebuilt and escaped with `esc_attr()` instead of being printed as found.
- Fix: deploying a raw request body (not multipart) caused a fatal error in the new temp-file code; found in testing before release.
- Review hardening: request values are sanitized (`esc_url_raw`, `sanitize_text_field`) instead of blanket `phpcs:ignore`; admin form fields and badges go through `wp_kses`; the admin form input is sanitized with `map_deep()`; inline JavaScript and CSS on mounted pages are printed with `wp_print_inline_script_tag()` and `wp_add_inline_style()`; every remaining unescaped output carries a written reason.
- Mounts are stored in the uploads folder (`uploads/static-sites`, per site on multisite). An existing `wp-content/static-sites` folder keeps being used while the new one does not exist.
- The internal prefix changed from `ssm` to `stasimo`: options, hooks (`stasimo_presets`, `stasimo_base_dir` ...), CSS classes (`.stasimo-host`), the sitemap URL (`/stasimo-pages.xml`) and the test setup. Stored settings are migrated automatically on the first request.
- Renamed to "Stasimo - Static Site Mount". Plugin folder, main file and text domain are now `stasimo-static-site-mount`. The REST namespace is `stasimo/v1`, the WP-CLI command is `wp stasimo`, and the admin pages are `admin.php?page=stasimo` and `page=stasimo-about`.

## 0.2.0
- Security: managing mounts and deploying need `manage_options` and `unfiltered_html` (multisite site admins no longer can mount scripts).
- Security: a mount's folder is confined to `wp-content/static-sites/` (no absolute paths or `.`/`..` segments; filter `stasimo_allow_external_dirs` re-enables absolute paths). Deploys never rename or delete anything outside it.
- Security: unpacked size is limited by the bytes really written, not the zip header; names such as `shell.php.png` are refused; the deploy lock is atomic.
- More reserved URL prefixes (sitemaps, robots.txt, wp-cron.php ...); overlapping mount prefixes are refused.
- Own top-level admin menu "Static Sites" (Mounts, About) instead of a Settings page.
- Mounts list with status, Add mount, Enable/Disable; detail view per mount (settings, status, deploy commands, indexed pages, remove).
- Disabled mounts are not routed and not in the sitemap; files and settings are kept.
- About page with how it works, environment check, REST and WP-CLI reference.

## 0.1.3
- Fix: the mount root without a trailing slash (/docs) is redirected to /docs/, so relative asset URLs resolve inside the mount.

## 0.1.1
- MkDocs Material preset (hides header/footer, follows host light/dark), PUT /mounts/<slug> to create a mount, .gz files allowed, sitemap now /ssm-pages.xml (renamed to /stasimo-pages.xml in 0.3.0).

## 0.1.0
- First working version: mounts, theme wrapper (block themes), SEO tags, sitemap, REST/CLI deploy with atomic swap, Docusaurus preset, light/dark bridge.
