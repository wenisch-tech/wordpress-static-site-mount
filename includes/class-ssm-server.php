<?php
/**
 * Routing: maps /<prefix>/... to files in the mount folder and serves them safely.
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

class SSM_Server {

	/** Extensions that may be served. Anything else (php, htaccess, sh ...) never leaves the folder. */
	public static function mime_types() {
		return apply_filters(
			'ssm_mime_types',
			array(
				'html' => 'text/html; charset=UTF-8',
				'htm'  => 'text/html; charset=UTF-8',
				'css'  => 'text/css; charset=UTF-8',
				'js'   => 'application/javascript; charset=UTF-8',
				'mjs'  => 'application/javascript; charset=UTF-8',
				'json' => 'application/json; charset=UTF-8',
				'map'  => 'application/json; charset=UTF-8',
				'xml'  => 'application/xml; charset=UTF-8',
				'txt'  => 'text/plain; charset=UTF-8',
				'svg'  => 'image/svg+xml',
				'png'  => 'image/png',
				'jpg'  => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'gif'  => 'image/gif',
				'webp' => 'image/webp',
				'avif' => 'image/avif',
				'ico'  => 'image/x-icon',
				'woff' => 'font/woff',
				'woff2' => 'font/woff2',
				'ttf'  => 'font/ttf',
				'otf'  => 'font/otf',
				'eot'  => 'application/vnd.ms-fontobject',
				'webmanifest' => 'application/manifest+json',
				'pdf'  => 'application/pdf',
				'mp4'  => 'video/mp4',
				'webm' => 'video/webm',
				'wasm' => 'application/wasm',
				'gz'   => 'application/gzip',
			)
		);
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rewrites' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 0 );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
	}

	public static function query_vars( $vars ) {
		$vars[] = 'ssm_mount';
		$vars[] = 'ssm_sitemap';
		return $vars;
	}

	public static function register_rewrites() {
		foreach ( SSM_Mounts::active() as $slug => $m ) {
			if ( empty( $m['prefix'] ) ) {
				continue;
			}
			add_rewrite_rule( '^' . preg_quote( $m['prefix'], '#' ) . '(/.*)?$', 'index.php?ssm_mount=' . rawurlencode( $slug ), 'top' );
		}
		add_rewrite_rule( '^ssm-pages\.xml$', 'index.php?ssm_sitemap=1', 'top' );
	}

	/** Flush rules once whenever the set of mounts changed (e.g. after a settings save or an import). */
	public static function maybe_flush() {
		if ( get_option( 'ssm_rules_hash' ) !== SSM_Mounts::rules_hash() ) {
			flush_rewrite_rules( false );
			update_option( 'ssm_rules_hash', SSM_Mounts::rules_hash(), false );
		}
	}

	/**
	 * Path relative to the mount, taken from the real request URI so a trailing slash is not lost.
	 */
	private static function requested_path( array $m ) {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = '/' . ltrim( substr( $path, strlen( $home ) ), '/' );
		}
		$path   = rawurldecode( $path );
		$prefix = '/' . $m['prefix'];
		if ( 0 !== strpos( $path, $prefix ) ) {
			return null;
		}
		return ltrim( substr( $path, strlen( $prefix ) ), '/' );
	}

	/**
	 * Resolve a request path to a file inside the mount folder.
	 *
	 * @return array|null {file:string, dir_index:bool}
	 */
	public static function resolve( $base_dir, $rel ) {
		$real_base = realpath( $base_dir );
		if ( false === $real_base ) {
			return null;
		}
		if ( false !== strpos( $rel, "\0" ) || false !== strpos( $rel, '\\' ) ) {
			return null;
		}
		foreach ( explode( '/', $rel ) as $seg ) {
			if ( '..' === $seg || ( '' !== $seg && '.' === $seg[0] ) ) {
				return null; // no traversal, no dotfiles.
			}
		}
		$rel_trim = trim( $rel, '/' );
		$cands    = array();
		if ( '' === $rel || '/' === substr( $rel, -1 ) ) {
			$cands[] = array( ( '' === $rel_trim ? '' : $rel_trim . '/' ) . 'index.html', true );
		} else {
			$cands[] = array( $rel_trim, false );
			$cands[] = array( $rel_trim . '.html', false );
			$cands[] = array( $rel_trim . '/index.html', true );
		}
		$types = self::mime_types();
		foreach ( $cands as $c ) {
			$full = realpath( $real_base . '/' . $c[0] );
			if ( false === $full || 0 !== strpos( $full, $real_base . DIRECTORY_SEPARATOR ) || ! is_file( $full ) ) {
				continue;
			}
			$ext = strtolower( pathinfo( $full, PATHINFO_EXTENSION ) );
			if ( ! isset( $types[ $ext ] ) ) {
				continue;
			}
			return array(
				'file'      => $full,
				'dir_index' => $c[1],
			);
		}
		return null;
	}

	public static function handle() {
		$slug = get_query_var( 'ssm_mount' );
		if ( '' === $slug || null === $slug ) {
			return;
		}
		$m = SSM_Mounts::get( $slug );
		if ( ! $m || ! $m['enabled'] ) {
			return;
		}
		$rel = self::requested_path( $m );
		if ( null === $rel ) {
			return;
		}
		$uri_path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security
		$slash    = '/' === substr( $uri_path, -1 );
		$rel_req  = $rel . ( $slash && '' !== $rel ? '/' : '' );

		$dir   = SSM_Mounts::dir( $m );
		$found = self::resolve( $dir, $rel_req );

		if ( ! $found ) {
			$nf = self::resolve( $dir, '404.html' );
			global $wp_query;
			if ( $nf ) {
				SSM_Renderer::render( $m, $nf['file'], $rel_req, 404 );
				exit;
			}
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		$ext = strtolower( pathinfo( $found['file'], PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'html', 'htm' ), true ) ) {
			// Folder pages live at ".../name/": redirect "name" to "name/" (keeps relative links working).
			// (This includes the mount root itself: relative URLs such as assets/x.css break without the slash.)
			if ( $found['dir_index'] && ! $slash ) {
				$to = SSM_Mounts::url( $m, '' === $rel ? '' : $rel . '/' );
				if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
					$to .= '?' . wp_unslash( $_SERVER['QUERY_STRING'] ); // phpcs:ignore WordPress.Security
				}
				wp_safe_redirect( $to, 301 );
				exit;
			}
			$url_rel = $found['dir_index'] ? ( '' === trim( $rel, '/' ) ? '' : trim( $rel, '/' ) . '/' ) : trim( $rel, '/' );
			SSM_Renderer::render( $m, $found['file'], $url_rel, 200 );
			exit;
		}

		$types = self::mime_types();
		self::send_file( $found['file'], $types[ $ext ] );
	}

	private static function send_file( $file, $mime ) {
		$mtime = (int) filemtime( $file );
		$size  = (int) filesize( $file );
		$etag  = '"' . md5( $mtime . '-' . $size ) . '"';
		// Build tools fingerprint assets (main.3e1a2b9c.js), so those can be cached "forever".
		$hashed = (bool) preg_match( '/\.[0-9a-f]{6,}\.[a-z0-9]+$/i', basename( $file ) );

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: ' . $mime );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'ETag: ' . $etag );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
		header( 'Cache-Control: public, max-age=' . ( $hashed ? '31536000, immutable' : '3600' ) );

		$inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : ''; // phpcs:ignore WordPress.Security
		$ims = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) : false; // phpcs:ignore WordPress.Security
		if ( $inm === $etag || ( '' === $inm && false !== $ims && $ims >= $mtime ) ) {
			status_header( 304 );
			exit;
		}
		status_header( 200 );
		header( 'Content-Length: ' . $size );
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'] ) {
			exit;
		}
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
