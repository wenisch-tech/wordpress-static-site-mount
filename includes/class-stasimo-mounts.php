<?php
/**
 * Mount configuration: storage, sanitizing, presets, paths.
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

class Stasimo_Mounts {

	const OPTION = 'stasimo_mounts';

	/** Prefixes that must never be mounted. */
	const RESERVED = array(
		'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login.php', 'wp-cron.php', 'wp-signup.php', 'wp-activate.php',
		'wp-comments-post.php', 'wp-trackback.php', 'wp-links-opml.php', 'wp-register.php', 'wp-mail.php', 'wp-load.php',
		'wp-sitemap.xml', 'sitemap.xml', 'sitemap_index.xml', 'robots.txt', 'favicon.ico', 'stasimo-pages.xml', 'index.php',
		'feed', 'comments', 'embed', 'xmlrpc.php',
	);

	/**
	 * Who may create mounts and deploy builds. A build is raw HTML and JavaScript served from this site's own origin,
	 * so this needs the same trust as unfiltered_html (multisite site admins do not have it).
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' ) && current_user_can( 'unfiltered_html' );
	}

	/**
	 * Why a mount cannot use this prefix: another mount (same or nested prefix) or a WordPress page. Empty string if free.
	 */
	public static function conflict( array $m ) {
		foreach ( self::all() as $slug => $other ) {
			if ( $slug === $m['slug'] || empty( $other['prefix'] ) ) {
				continue;
			}
			$a = $other['prefix'];
			$b = $m['prefix'];
			if ( $a === $b || 0 === strpos( $b, $a . '/' ) || 0 === strpos( $a, $b . '/' ) ) {
				/* translators: %s name of the other mount */
				return sprintf( __( 'The URL prefix overlaps with the mount "%s".', 'static-site-mount' ), $slug );
			}
		}
		if ( get_page_by_path( $m['prefix'] ) ) {
			/* translators: %s URL prefix */
			return sprintf( __( 'A WordPress page already uses /%s/. Choose another prefix.', 'static-site-mount' ), $m['prefix'] );
		}
		return '';
	}

	/**
	 * Presets describe how a given generator's output is wrapped.
	 *
	 * hide   CSS selectors hidden (keeps JS apps like Docusaurus working, because the DOM is untouched).
	 * remove selectors removed from the HTML on the server (for plain static sites without JS).
	 * theme_key localStorage key the generator uses for light/dark, so both sides agree.
	 * css    extra CSS; {offset} is replaced by the header offset in px.
	 */
	public static function presets() {
		return apply_filters(
			'stasimo_presets',
			array(
				'docusaurus' => array(
					'label'     => 'Docusaurus',
					'hide'      => 'nav.navbar, footer.footer',
					'remove'    => '',
					'theme_key' => 'theme',
					'css'       => ':root{--ifm-navbar-height:{offset}px!important}body.stasimo-page{color:var(--ifm-font-color-base);background-color:var(--ifm-background-color)}',
				),
				'mkdocs-material' => array(
					'label'     => 'MkDocs Material',
					// Keep Material's own header as a slim bar under the theme header: it carries search and the mobile menu button.
					'hide'      => '.md-footer-meta',
					'remove'    => '',
					'theme_key' => '',
					'css'       => '.md-header{top:{offset}px!important}.md-sidebar{top:calc(2.4rem + {offset}px)!important}@media (max-width:76.1875em){.md-sidebar--primary{top:{offset}px!important;height:calc(100% - {offset}px)!important}}body.stasimo-page{color:var(--md-default-fg-color);background-color:var(--md-default-bg-color)}',
					'body_attr' => array(
						'name'  => 'data-md-color-scheme',
						'dark'  => 'slate',
						'light' => 'default',
					),
				),
				'generic'    => array(
					'label'     => 'Generic static site (plain HTML)',
					'hide'      => '',
					'remove'    => '',
					'theme_key' => '',
					'css'       => '',
				),
			)
		);
	}

	public static function defaults() {
		return array(
			'slug'          => '',
			'enabled'       => true,
			'prefix'        => '',
			'dir'           => '',
			'preset'        => 'docusaurus',
			'hide'          => '',
			'remove'        => '',
			'title_suffix'  => '',
			'sitemap'       => true,
			'noindex'       => false,
			'header_offset' => 0,
			'host_theme_key' => '',
		);
	}

	public static function all() {
		$mounts = get_option( self::OPTION, array() );
		return is_array( $mounts ) ? $mounts : array();
	}

	/** Only mounts that are switched on take part in routing and sitemaps. */
	public static function active() {
		$out = array();
		foreach ( self::all() as $slug => $m ) {
			$m = wp_parse_args( $m, self::defaults() );
			if ( $m['enabled'] ) {
				$out[ $slug ] = $m;
			}
		}
		return $out;
	}

	public static function get( $slug ) {
		$all = self::all();
		return isset( $all[ $slug ] ) ? wp_parse_args( $all[ $slug ], self::defaults() ) : null;
	}

	public static function save_all( array $mounts ) {
		update_option( self::OPTION, $mounts, false );
		update_option( 'stasimo_rules_hash', '', false ); // force a rewrite flush on the next request.
	}

	public static function rules_hash() {
		$parts = array();
		foreach ( self::all() as $slug => $m ) {
			$parts[] = $slug . '=' . ( isset( $m['prefix'] ) ? $m['prefix'] : '' ) . ( isset( $m['enabled'] ) && ! $m['enabled'] ? ':off' : '' );
		}
		return md5( implode( '|', $parts ) . '|' . STASIMO_VERSION );
	}

	/**
	 * Sanitize one mount from untrusted input. Returns array or WP_Error.
	 */
	public static function sanitize( array $raw ) {
		$m          = wp_parse_args( $raw, self::defaults() );
		$m['slug']  = sanitize_title( $m['slug'] );
		if ( false !== strpos( (string) $m['prefix'], '..' ) ) {
			return new WP_Error( 'stasimo_prefix', __( 'The URL prefix must not contain "..".', 'static-site-mount' ) );
		}
		$prefix     = strtolower( trim( (string) $m['prefix'], " \t\n\r\0\x0B/" ) );
		$prefix     = preg_replace( '#[^a-z0-9/_-]#', '', $prefix );
		$prefix     = preg_replace( '#/+#', '/', $prefix );
		$prefix     = trim( $prefix, '/' );

		if ( '' === $m['slug'] ) {
			return new WP_Error( 'stasimo_slug', __( 'A short name (slug) is required.', 'static-site-mount' ) );
		}
		if ( '' === $prefix || false !== strpos( $prefix, '..' ) ) {
			return new WP_Error( 'stasimo_prefix', __( 'A URL prefix such as docs/my-project is required.', 'static-site-mount' ) );
		}
		$first = explode( '/', $prefix )[0];
		if ( in_array( $first, self::RESERVED, true ) ) {
			return new WP_Error( 'stasimo_reserved', __( 'That URL prefix is reserved by WordPress.', 'static-site-mount' ) );
		}
		$m['prefix'] = $prefix;

		// The folder is always inside the base folder (uploads/static-sites): a deploy replaces it, so it must never point elsewhere.
		// Use the stasimo_base_dir filter to move the base folder, or stasimo_allow_external_dirs to allow absolute paths (WP-CLI use).
		$dir = trim( (string) $m['dir'], " \t\n\r\0\x0B/" );
		if ( '' !== $dir ) {
			$absolute = 0 === strpos( (string) $m['dir'], '/' ) || preg_match( '#^[A-Za-z]:[\\\\/]#', (string) $m['dir'] );
			if ( $absolute && apply_filters( 'stasimo_allow_external_dirs', false ) ) {
				$dir = untrailingslashit( (string) $m['dir'] );
			} elseif ( $absolute || ! preg_match( '#^[A-Za-z0-9_-][A-Za-z0-9._-]*(/[A-Za-z0-9_-][A-Za-z0-9._-]*)*$#', $dir ) || false !== strpos( $dir, '..' ) ) {
				return new WP_Error( 'stasimo_dir', __( 'The folder must be a plain name inside the static-sites folder (letters, digits, dash, underscore).', 'static-site-mount' ) );
			}
		}
		$m['dir'] = $dir;

		$presets      = self::presets();
		$m['preset']  = isset( $presets[ $m['preset'] ] ) ? $m['preset'] : 'generic';
		$m['hide']    = self::clean_selectors( $m['hide'] );
		$m['remove']  = self::clean_selectors( $m['remove'] );
		$m['title_suffix'] = sanitize_text_field( $m['title_suffix'] );
		$m['enabled'] = ! isset( $raw['enabled'] ) || filter_var( $raw['enabled'], FILTER_VALIDATE_BOOLEAN );
		$m['sitemap'] = ! empty( $m['sitemap'] );
		$m['noindex'] = ! empty( $m['noindex'] );
		$m['header_offset'] = max( 0, min( 400, (int) $m['header_offset'] ) );
		$m['host_theme_key'] = preg_replace( '/[^A-Za-z0-9_.:-]/', '', (string) $m['host_theme_key'] );
		return $m;
	}

	public static function clean_selectors( $value ) {
		$value = str_replace( array( '</', '/*', '*/' ), '', (string) $value );
		$value = preg_replace( '/[^\w\s,.#:>\-\[\]="\'*+~()]/u', '', $value );
		return trim( preg_replace( '/\s+/', ' ', $value ) );
	}

	/**
	 * Effective config: preset defaults plus the user's additions.
	 */
	public static function effective( array $m ) {
		$presets = self::presets();
		$p       = isset( $presets[ $m['preset'] ] ) ? $presets[ $m['preset'] ] : $presets['generic'];
		$join    = function ( $a, $b ) {
			return trim( implode( ', ', array_filter( array( trim( $a ), trim( $b ) ) ) ) );
		};
		$m['hide_all']   = $join( $p['hide'], $m['hide'] );
		$m['remove_all'] = $join( $p['remove'], $m['remove'] );
		$m['theme_key']  = $p['theme_key'];
		$m['body_attr']  = isset( $p['body_attr'] ) ? $p['body_attr'] : null;
		$m['css']        = str_replace( '{offset}', (string) (int) $m['header_offset'], $p['css'] );
		return $m;
	}

	/**
	 * Base folder for all mounts: <uploads>/static-sites (per site on multisite).
	 * Versions before 0.3.0 used wp-content/static-sites; that folder keeps being used while it exists and the new one does not.
	 */
	public static function base_dir() {
		static $default = null;
		if ( null === $default ) {
			$up      = wp_upload_dir( null, false );
			$new     = untrailingslashit( empty( $up['basedir'] ) ? WP_CONTENT_DIR . '/uploads' : $up['basedir'] ) . '/static-sites';
			$legacy  = WP_CONTENT_DIR . '/static-sites';
			$default = ( is_dir( $legacy ) && ! is_dir( $new ) ) ? $legacy : $new;
		}
		return untrailingslashit( apply_filters( 'stasimo_base_dir', $default ) );
	}

	/**
	 * Absolute folder of a mount's files.
	 */
	public static function dir( array $m ) {
		$dir = isset( $m['dir'] ) ? trim( $m['dir'] ) : '';
		if ( '' === $dir ) {
			return self::base_dir() . '/' . $m['slug'];
		}
		if ( 0 === strpos( $dir, '/' ) || preg_match( '#^[A-Za-z]:[\\\\/]#', $dir ) ) {
			// Absolute folders only when explicitly allowed; otherwise fall back to the default location.
			return apply_filters( 'stasimo_allow_external_dirs', false ) ? untrailingslashit( $dir ) : self::base_dir() . '/' . $m['slug'];
		}
		if ( preg_match( '#(^|/)\.#', $dir ) ) {
			return self::base_dir() . '/' . $m['slug'];
		}
		return self::base_dir() . '/' . trim( $dir, '/' );
	}

	public static function url( array $m, $path = '' ) {
		return home_url( '/' . $m['prefix'] . '/' . ltrim( $path, '/' ) );
	}

	/**
	 * Create the base folder and keep it from being served directly by the web server.
	 */
	public static function prepare_base_dir() {
		$base = self::base_dir();
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
		}
		$ht = $base . '/.htaccess';
		if ( is_dir( $base ) && ! file_exists( $ht ) ) {
			// Files are served through WordPress so the theme wrapper, SEO and caching rules always apply.
			file_put_contents( $ht, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$ix = $base . '/index.php';
		if ( is_dir( $base ) && ! file_exists( $ix ) ) {
			file_put_contents( $ix, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
}
