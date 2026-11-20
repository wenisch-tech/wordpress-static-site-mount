<?php
/**
 * Mount configuration: storage, sanitizing, presets, paths.
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

class SSM_Mounts {

	const OPTION = 'ssm_mounts';

	/** Prefixes that must never be mounted. */
	const RESERVED = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login.php', 'feed', 'xmlrpc.php' );

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
			'ssm_presets',
			array(
				'docusaurus' => array(
					'label'     => 'Docusaurus',
					'hide'      => 'nav.navbar, footer.footer',
					'remove'    => '',
					'theme_key' => 'theme',
					'css'       => ':root{--ifm-navbar-height:{offset}px!important}body.ssm-page{color:var(--ifm-font-color-base);background-color:var(--ifm-background-color)}',
				),
				'mkdocs-material' => array(
					'label'     => 'MkDocs Material',
					// Keep Material's own header as a slim bar under the theme header: it carries search and the mobile menu button.
					'hide'      => '.md-footer-meta',
					'remove'    => '',
					'theme_key' => '',
					'css'       => '.md-header{top:{offset}px!important}.md-sidebar{top:calc(2.4rem + {offset}px)!important}@media (max-width:76.1875em){.md-sidebar--primary{top:{offset}px!important;height:calc(100% - {offset}px)!important}}body.ssm-page{color:var(--md-default-fg-color);background-color:var(--md-default-bg-color)}',
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
		update_option( 'ssm_rules_hash', '', false ); // force a rewrite flush on the next request.
	}

	public static function rules_hash() {
		$parts = array();
		foreach ( self::all() as $slug => $m ) {
			$parts[] = $slug . '=' . ( isset( $m['prefix'] ) ? $m['prefix'] : '' ) . ( isset( $m['enabled'] ) && ! $m['enabled'] ? ':off' : '' );
		}
		return md5( implode( '|', $parts ) . '|' . SSM_VERSION );
	}

	/**
	 * Sanitize one mount from untrusted input. Returns array or WP_Error.
	 */
	public static function sanitize( array $raw ) {
		$m          = wp_parse_args( $raw, self::defaults() );
		$m['slug']  = sanitize_title( $m['slug'] );
		if ( false !== strpos( (string) $m['prefix'], '..' ) ) {
			return new WP_Error( 'ssm_prefix', __( 'The URL prefix must not contain "..".', 'static-site-mount' ) );
		}
		$prefix     = strtolower( trim( (string) $m['prefix'], " \t\n\r\0\x0B/" ) );
		$prefix     = preg_replace( '#[^a-z0-9/_-]#', '', $prefix );
		$prefix     = preg_replace( '#/+#', '/', $prefix );
		$prefix     = trim( $prefix, '/' );

		if ( '' === $m['slug'] ) {
			return new WP_Error( 'ssm_slug', __( 'A short name (slug) is required.', 'static-site-mount' ) );
		}
		if ( '' === $prefix || false !== strpos( $prefix, '..' ) ) {
			return new WP_Error( 'ssm_prefix', __( 'A URL prefix such as docs/my-project is required.', 'static-site-mount' ) );
		}
		$first = explode( '/', $prefix )[0];
		if ( in_array( $first, self::RESERVED, true ) ) {
			return new WP_Error( 'ssm_reserved', __( 'That URL prefix is reserved by WordPress.', 'static-site-mount' ) );
		}
		$m['prefix'] = $prefix;

		$dir = trim( (string) $m['dir'] );
		if ( '' !== $dir && ( false !== strpos( $dir, '..' ) || false !== strpos( $dir, "\0" ) ) ) {
			return new WP_Error( 'ssm_dir', __( 'The folder must not contain "..".', 'static-site-mount' ) );
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

	public static function base_dir() {
		return untrailingslashit( apply_filters( 'ssm_base_dir', WP_CONTENT_DIR . '/static-sites' ) );
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
			return untrailingslashit( $dir );
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
