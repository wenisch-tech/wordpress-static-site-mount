# Changelog

## 0.3.0
- Mounts are stored in the uploads folder (`uploads/static-sites`, per site on multisite). An existing `wp-content/static-sites` folder keeps being used while the new one does not exist.
- The internal prefix changed from `ssm` to `stasimo`: options, hooks (`stasimo_presets`, `stasimo_base_dir` ...), CSS classes (`.stasimo-host`), the sitemap URL (`/stasimo-pages.xml`) and the test setup. Stored settings are migrated automatically on the first request.
- The name, text domain, REST namespace (`static-site-mount/v1`) and WP-CLI command (`wp static-site-mount`) are unchanged.

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
