<?php
/**
 * Mount configuration: storage, sanitizing, presets, paths.
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mount configuration: storage, sanitizing, presets and paths.
 */
class Stasimo_Mounts {

	const OPTION = 'stasimo_mounts';

	/** Name of the plugin's folder inside the uploads directory (the plugin slug). */
	const FOLDER = 'stasimo-static-site-mount';

	/** Prefixes that must never be mounted. */
	const RESERVED = array(
		'wp-admin',
		'wp-content',
		'wp-includes',
		'wp-json',
		'wp-login.php',
		'wp-cron.php',
		'wp-signup.php',
		'wp-activate.php',
		'wp-comments-post.php',
		'wp-trackback.php',
		'wp-links-opml.php',
		'wp-register.php',
		'wp-mail.php',
		'wp-load.php',
		'wp-sitemap.xml',
		'sitemap.xml',
		'sitemap_index.xml',
		'robots.txt',
		'favicon.ico',
		'stasimo-pages.xml',
		'index.php',
		'feed',
		'comments',
		'embed',
		'xmlrpc.php',
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
	 *
	 * @param array $m Mount to check.
	 * @return string
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
				return sprintf( __( 'The URL prefix overlaps with the mount "%s".', 'stasimo-static-site-mount' ), $slug );
			}
		}
		if ( get_page_by_path( $m['prefix'] ) ) {
			/* translators: %s URL prefix */
			return sprintf( __( 'A WordPress page already uses /%s/. Choose another prefix.', 'stasimo-static-site-mount' ), $m['prefix'] );
		}
		return '';
	}

	/**
	 * Presets describe how a given generator's output is wrapped.
	 *
	 * Every preset has these keys. The hide key lists CSS selectors that are hidden, which keeps JS apps like Docusaurus
	 * working because the DOM is untouched. The remove key lists selectors that are removed from the HTML on the server
	 * (for plain static sites without JS). The scripts key says whether the generator's output needs its own JavaScript, which
	 * only works in a trusted mount. The theme_key is the localStorage key the generator uses for light and dark,
	 * so both sides agree. The css key holds extra CSS, where {offset} is replaced by the header offset in px.
	 *
	 * @return array[]
	 */
	public static function presets() {
		return apply_filters(
			'stasimo_presets',
			array(
				'docusaurus'      => array(
					'label'     => 'Docusaurus',
					'scripts'   => true,
					'hide'      => 'nav.navbar, footer.footer',
					'remove'    => '',
					'theme_key' => 'theme',
					'css'       => ':root{--ifm-navbar-height:{offset}px!important}body.stasimo-page{color:var(--ifm-font-color-base);background-color:var(--ifm-background-color)}',
				),
				'mkdocs-material' => array(
					'label'     => 'MkDocs Material',
					'scripts'   => true,
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
				'generic'         => array(
					'label'     => 'Generic static site (plain HTML)',
					'scripts'   => false,
					'hide'      => '',
					'remove'    => '',
					'theme_key' => '',
					'css'       => '',
				),
			)
		);
	}

	/**
	 * Default values of a mount.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'slug'           => '',
			'enabled'        => true,
			'prefix'         => '',
			'preset'         => 'docusaurus',
			'hide'           => '',
			'remove'         => '',
			'title_suffix'   => '',
			'sitemap'        => true,
			'noindex'        => false,
			'trusted'        => false,
			'header_offset'  => 0,
			'host_theme_key' => '',
		);
	}

	/**
	 * All stored mounts, including disabled ones.
	 *
	 * @return array
	 */
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

	/**
	 * One mount with defaults filled in.
	 *
	 * @param string $slug Mount name.
	 * @return array|null
	 */
	public static function get( $slug ) {
		$all = self::all();
		return isset( $all[ $slug ] ) ? wp_parse_args( $all[ $slug ], self::defaults() ) : null;
	}

	/**
	 * Store all mounts and schedule a rewrite rules flush.
	 *
	 * @param array $mounts All mounts, keyed by name.
	 */
	public static function save_all( array $mounts ) {
		update_option( self::OPTION, $mounts, false );
		update_option( 'stasimo_rules_hash', '', false ); // force a rewrite flush on the next request.
	}

	/**
	 * Fingerprint of everything the rewrite rules depend on.
	 *
	 * @return string
	 */
	public static function rules_hash() {
		$parts = array();
		foreach ( self::all() as $slug => $m ) {
			$parts[] = $slug . '=' . ( isset( $m['prefix'] ) ? $m['prefix'] : '' ) . ( isset( $m['enabled'] ) && ! $m['enabled'] ? ':off' : '' );
		}
		return md5( implode( '|', $parts ) . '|' . STASIMO_VERSION );
	}

	/**
	 * Sanitize one mount from untrusted input. Returns array or WP_Error.
	 *
	 * @param array $raw Untrusted mount data.
	 */
	public static function sanitize( array $raw ) {
		$m         = wp_parse_args( $raw, self::defaults() );
		$m['slug'] = sanitize_title( $m['slug'] );
		if ( false !== strpos( (string) $m['prefix'], '..' ) ) {
			return new WP_Error( 'stasimo_prefix', __( 'The URL prefix must not contain "..".', 'stasimo-static-site-mount' ) );
		}
		$prefix = strtolower( trim( (string) $m['prefix'], " \t\n\r\0\x0B/" ) );
		$prefix = preg_replace( '#[^a-z0-9/_-]#', '', $prefix );
		$prefix = preg_replace( '#/+#', '/', $prefix );
		$prefix = trim( $prefix, '/' );

		if ( '' === $m['slug'] ) {
			return new WP_Error( 'stasimo_slug', __( 'A short name (slug) is required.', 'stasimo-static-site-mount' ) );
		}
		if ( '' === $prefix || false !== strpos( $prefix, '..' ) ) {
			return new WP_Error( 'stasimo_prefix', __( 'A URL prefix such as docs/my-project is required.', 'stasimo-static-site-mount' ) );
		}
		$first = explode( '/', $prefix )[0];
		if ( in_array( $first, self::RESERVED, true ) ) {
			return new WP_Error( 'stasimo_reserved', __( 'That URL prefix is reserved by WordPress.', 'stasimo-static-site-mount' ) );
		}
		$m['prefix'] = $prefix;
		unset( $m['dir'] ); // Removed option: builds are always stored by the plugin.

		$presets           = self::presets();
		$m['preset']       = isset( $presets[ $m['preset'] ] ) ? $m['preset'] : 'generic';
		$m['hide']         = self::clean_selectors( $m['hide'] );
		$m['remove']       = self::clean_selectors( $m['remove'] );
		$m['title_suffix'] = sanitize_text_field( $m['title_suffix'] );
		$m['enabled']      = ! isset( $raw['enabled'] ) || filter_var( $raw['enabled'], FILTER_VALIDATE_BOOLEAN );
		$m['sitemap']      = ! empty( $m['sitemap'] );
		$m['noindex']      = ! empty( $m['noindex'] );
		// Trusted mounts run the site's own JavaScript and print its markup as it is. Everything else is filtered with wp_kses.
		$m['trusted']        = ! empty( $m['trusted'] ) && filter_var( $m['trusted'], FILTER_VALIDATE_BOOLEAN );
		$m['header_offset']  = max( 0, min( 400, (int) $m['header_offset'] ) );
		$m['host_theme_key'] = preg_replace( '/[^A-Za-z0-9_.:-]/', '', (string) $m['host_theme_key'] );
		return $m;
	}

	/**
	 * Reduce a selector list to characters that are safe inside a stylesheet.
	 *
	 * @param string $value Comma separated selectors.
	 * @return string
	 */
	public static function clean_selectors( $value ) {
		$value = str_replace( array( '</', '/*', '*/' ), '', (string) $value );
		$value = preg_replace( '/[^\w\s,.#:>\-\[\]="\'*+~()]/u', '', $value );
		return trim( preg_replace( '/\s+/', ' ', $value ) );
	}

	/**
	 * Effective config: preset defaults plus the user's additions.
	 *
	 * @param array $m Mount.
	 */
	public static function effective( array $m ) {
		$presets         = self::presets();
		$p               = isset( $presets[ $m['preset'] ] ) ? $presets[ $m['preset'] ] : $presets['generic'];
		$join            = function ( $a, $b ) {
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
	 * Folder for the media files (images, fonts ...) of all mounts: <uploads>/stasimo-static-site-mount (per site on multisite),
	 * resolved with wp_upload_dir(). Code files are never written there; they are stored in the database (see Stasimo_Store).
	 *
	 * @return string Absolute path without trailing slash, or '' if the uploads directory is unknown.
	 */
	public static function base_dir() {
		$up = wp_upload_dir( null, false );
		if ( empty( $up['basedir'] ) ) {
			return '';
		}
		return untrailingslashit( $up['basedir'] ) . '/' . self::FOLDER;
	}

	/**
	 * Public URL of a mount, or of a path inside it.
	 *
	 * @param array  $m    Mount.
	 * @param string $path Path inside the mount.
	 * @return string
	 */
	public static function url( array $m, $path = '' ) {
		return home_url( '/' . $m['prefix'] . '/' . ltrim( $path, '/' ) );
	}

	/**
	 * Create the plugin's media folder in the uploads directory. Only folders are created here: no helper files are written.
	 * The folder holds public media (images, fonts, video) only; code is stored in the database.
	 */
	public static function prepare_base_dir() {
		$base = self::base_dir();
		if ( '' !== $base && ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
		}
	}
}
