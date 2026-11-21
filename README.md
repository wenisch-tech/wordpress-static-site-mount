# Stasimo - Static Site Mount

[![License: GPL v2+](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

![Stasimo - Static Site Mount](assets/banner-1544x500.png)

A WordPress plugin that mounts static HTML files in your existing WordPress site. A folder of HTML, CSS, JavaScript and images is served at a path of your choice and shown between your theme's header and footer. Where the files come from does not matter: a documentation generator (for example Docusaurus, MkDocs or Hugo), a landing page, an exported report, or HTML you wrote by hand.

I wrote it because static sites, project docs in particular, usually end up on a subdomain with their own look, their own menu and no connection to the main site's SEO. With this plugin the build lives at something like `example.com/docs/my-project/`, looks like the rest of the site, and is updated by a single request from CI.

## What happens to a page

You deploy a folder of HTML files. When someone opens one of its URLs, the plugin reads the file, takes your block theme's header and footer, and prints the page's own `<body>` between them. If the files come with their own navbar and footer, those are hidden with CSS, or removed on the server for plain HTML. Hiding is the safer choice for JavaScript-driven sites (Docusaurus and MkDocs Material are examples), because their scripts expect the original markup to be there.

The plugin writes the head itself: title, description, canonical URL, Open Graph and Twitter tags, `noindex` if the page asks for it. If Yoast SEO is active, its tags are switched off on these pages so nothing is duplicated. All pages go into `/stasimo-pages.xml`, which is added to Yoast's sitemap index and to `robots.txt`.

Other things it does:

- serves assets with the right MIME type, `ETag`, and long caching for fingerprinted files
- follows the theme's light/dark choice, if you tell it the localStorage key
- replaces a mount's folder atomically on every deploy
- has its own admin menu (Static Sites) and WP-CLI commands

## Requirements

WordPress 5.9, PHP 7.4 with the zip extension (needed for deploys), pretty permalinks, and a block theme. With a classic theme the page is served without header and footer, unless you supply them through the `stasimo_header_html` and `stasimo_footer_html` filters.

## Install

Download the zip from the releases page, upload it under Plugins, Add New, Upload Plugin, and activate it. There is no release yet, so for now: copy this folder to `wp-content/plugins/stasimo-static-site-mount/`.

## Set up a mount

Build your site with the mount's URL as its base path. The plugin does not rewrite URLs inside JavaScript bundles, so this has to be right at build time. Hand-written HTML with relative links needs nothing special. Two examples of the setting:

Docusaurus:

```js
// docusaurus.config.js
url: 'https://example.com',
baseUrl: '/docs/my-project/',
trailingSlash: true,
```

MkDocs: `site_url: https://example.com/docs/my-project/`

Other generators have an equivalent (Hugo: `baseURL`). Then go to Static Sites, Mounts, Add mount, and fill in a name (`my-project`), the URL prefix (`docs/my-project`) and the type. Choose a generator's type if there is one (Docusaurus, MkDocs Material), otherwise "Generic static site". Two optional fields are worth knowing about: the localStorage key your theme uses for light/dark, and the height of your header if it is fixed at the top (the content is pushed down by that much).

## Deploy from CI

Create an Application Password for an administrator account and keep it as a secret. After the build:

```bash
cd build && zip -qr ../build.zip . && cd ..
curl -fsS -u "$WP_USER:$WP_APP_PASSWORD" \
  -F "file=@build.zip" \
  "https://example.com/wp-json/stasimo/v1/mounts/my-project/deploy"
```

There is a complete GitHub Actions job in [`examples/github-action-deploy.yml`](examples/github-action-deploy.yml). Send the file as multipart; PHP then keeps it on disk instead of in memory. A raw request body also works for small sites.

The zip can have its files at the top level or inside one folder. If the build has no top-level `index.html`, or contains a file type that is not allowed (`.php`, for example), the deploy is refused and the live site stays as it was.

| Endpoint | What it does |
|---|---|
| `GET /wp-json/stasimo/v1/mounts` | list mounts with page counts |
| `PUT /wp-json/stasimo/v1/mounts/<name>` | create or update a mount (JSON) |
| `POST /wp-json/stasimo/v1/mounts/<name>/deploy` | replace the folder with a zip, re-index |
| `POST /wp-json/stasimo/v1/mounts/<name>/reindex` | re-index after changing files some other way |

WP-CLI: `wp stasimo list`, `add`, `deploy`, `reindex`.

## Mount settings

| Setting | Meaning |
|---|---|
| Name | short name; also the folder name under `wp-content/uploads/static-sites/` |
| URL prefix | where the site appears, e.g. `docs/my-project`; must match the build's base URL |
| Type | a preset for a known generator (Docusaurus, MkDocs Material) or generic static site |
| Hide elements | extra CSS selectors to hide (safe for JavaScript apps) |
| Remove elements | simple selectors (`tag`, `#id`, `.class`, spaces) removed on the server |
| Space for a fixed header | pixels of padding for a fixed theme header |
| Light/dark key | localStorage key of the theme's light/dark choice |
| Title suffix | appended to every page title |
| Sitemap, noindex | list the pages in the sitemap, or ask search engines to skip the mount |

A disabled mount is not routed and not in the sitemap. Its files and settings stay.

For anything else, pick "Generic static site" and list what to remove, for example `header.site-header, footer.site-footer`. To reuse the same settings for other mounts, add a preset with the `stasimo_presets` filter:

```php
add_filter( 'stasimo_presets', function ( $presets ) {
    $presets['hugo-book'] = array(
        'label' => 'Hugo Book', 'hide' => '.book-header, .book-footer',
        'remove' => '', 'theme_key' => '', 'css' => '',
    );
    return $presets;
} );
```

Other hooks: `stasimo_title`, `stasimo_description`, `stasimo_header_html`, `stasimo_footer_html`, `stasimo_host_css`, `stasimo_mime_types`, `stasimo_base_dir`, `stasimo_allow_external_dirs`, and the action `stasimo_deployed`.

## Security

A mounted build is HTML and JavaScript served from your own domain. Whoever can deploy can run script on your site, so only deploy builds you trust. Managing mounts therefore needs both `manage_options` and `unfiltered_html`. On multisite, site admins don't have the second one, so only super admins can use the plugin. For CI, use an Application Password of a dedicated administrator.

Uploads are limited to a list of file types. Names that look executable (`shell.php.png`), paths with `..` or absolute paths, and archives larger than 512 MB unpacked or 50,000 files are refused. Mount folders always sit inside `wp-content/uploads/static-sites/`.

On Apache the plugin writes an `.htaccess` that blocks direct access to that folder. On nginx you need to add `location ^~ /wp-content/uploads/static-sites/ { deny all; }` yourself, otherwise the raw files can also be fetched without the theme around them.

The plugin makes no outgoing requests and stores no personal data.

If you upgrade from 0.2.x, settings are migrated on the first request. A `wp-content/static-sites/` folder from the old version keeps being used until you move it.

Report vulnerabilities as a [private security advisory](https://github.com/wenisch-tech/wordpress-static-site-mount/security/advisories/new), not as a public issue.

## Limitations

- The static site's CSS and your theme's CSS end up on one page and can collide (headings, buttons, fonts). Check a few pages after the first deploy and add overrides where needed.
- Search boxes or version menus that live in a navbar that gets hidden are hidden with it. Keep the navbar and hide only parts of it if you need them.
- One server only. There is no CDN purge, and multisite is only lightly tested.
- Yoast SEO is handled. Other SEO plugins may still print their own tags.

## Development

```bash
tests/run.sh up            # WordPress + MariaDB in Docker, plugin mounted
tests/smoke.sh http://localhost:8089 docs/mini
bin/build-zip.sh           # release zip without tests and dev files
```

`tests/fixtures/` holds a small Docusaurus-like site and a deliberately hostile zip fixture. The release build is checked with [Plugin Check](https://wordpress.org/plugins/plugin-check/) (start the environment with `STASIMO_SRC=<built folder> tests/run.sh up`, install `plugin-check`, run `wp plugin check stasimo-static-site-mount`).

The logo and icons are generated by [`assets/src/make_logo.py`](assets/src/make_logo.py), the banner is [`assets/src/banner.html`](assets/src/banner.html). Pull requests are welcome; follow the WordPress coding standards.

## License

GPL v2 or later, like WordPress. See [LICENSE](LICENSE).
