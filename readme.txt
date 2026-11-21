=== Stasimo - Static Site Mount ===
Contributors: jfwenisch
Tags: static site, documentation, docusaurus, mkdocs, seo
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Serve a built static site (Docusaurus, MkDocs, Hugo, plain HTML) inside your theme, with SEO tags and sitemap, and deploy it from CI.

== Description ==

Stasimo publishes a folder of static HTML at a URL of your WordPress site, for example documentation at `/docs/my-project/`. Pages are wrapped in your block theme's header and footer, so the menu, branding and light/dark switch stay consistent.

By default pages are filtered: scripts, forms, frames and event handlers are removed, the rest is shown in your theme. For sites that need their own JavaScript (Docusaurus, MkDocs) a mount can be switched to Trusted.

* Mount a folder at a URL prefix; assets are served with correct MIME types, ETag and long-lived caching for fingerprinted files.
* The generator's own navbar and footer are hidden (JavaScript apps keep working) or removed (plain HTML).
* Title, meta description, canonical URL, Open Graph and Twitter tags are written for every page; Yoast SEO's duplicate tags are suppressed for mounted pages.
* A sitemap for mounted pages is added to the Yoast sitemap index and robots.txt.
* Deploy from CI: upload a zip to a REST endpoint; the folder is replaced atomically and re-indexed. WP-CLI commands included.
* Safe by default: allow-listed file types only, no dotfiles, no path traversal, zip-slip checks, files not reachable directly.

Works with any generator that outputs HTML files. A Docusaurus preset is included.

== Installation ==

1. Upload the plugin and activate it.
2. Build your static site with the mount prefix as its base URL (Docusaurus: `baseUrl: '/docs/my-project/'`).
3. Go to Static Sites > Mounts and add a mount.
4. Deploy the build with the REST endpoint, WP-CLI, or by copying files into `wp-content/uploads/stasimo/<name>/` and pressing Re-index.

== Frequently Asked Questions ==

= Does it work with classic themes? =
Pages are served, but the header and footer wrapper needs a block theme. Use the `stasimo_header_html` and `stasimo_footer_html` filters for classic themes.

= Why does the build need the base URL? =
JavaScript apps such as Docusaurus embed their base URL in the bundle. The plugin does not rewrite bundles.

= Is it safe? =
By default a mount is filtered: pages go through wp_kses() and their scripts are removed. A Trusted mount serves the build as it is, including its JavaScript, so it should only be used for sites you built yourself. Managing mounts, trusted or not, needs the manage_options and unfiltered_html capabilities (on multisite only super admins). Uploads are limited to allow-listed file types, unsafe paths are refused, and files are served only through WordPress. On nginx add `location ^~ /wp-content/uploads/stasimo/ { deny all; }` so the raw files are not reachable directly (Apache is handled by a generated .htaccess).

= Does the plugin contact external servers? =
No. It makes no external requests and collects no personal data.

= How do I deploy from GitHub Actions? =
Create an Application Password and POST the zipped build to `/wp-json/stasimo/v1/mounts/<name>/deploy`. An example workflow is in the plugin's `examples` folder.

== Screenshots ==

1. A Docusaurus site mounted inside the theme's header and footer.
2. The Mounts list with status, page count and enable/disable.
3. Mount details: where it lives, look and feel, search engines, deploy commands and indexed pages.
4. The About page with an environment check and the REST and WP-CLI reference.

== Source code ==

The complete source, the logo and banner generators and the Docker test setup are on GitHub: https://github.com/wenisch-tech/wordpress-static-site-mount

== Changelog ==

= 0.3.0 =
* First public release.
