<?php
/**
 * Routing: maps /<prefix>/... to files in the mount folder and serves them safely.
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routing: maps /<prefix>/... to files in the mount folder and serves them safely.
 */
class Stasimo_Server {

	/** Extensions that may be served. Anything else (php, htaccess, sh ...) never leaves the folder. */
	public static function mime_types() {
		return apply_filters(
			'stasimo_mime_types',
			array(
				'html'        => 'text/html; charset=UTF-8',
				'htm'         => 'text/html; charset=UTF-8',
				'css'         => 'text/css; charset=UTF-8',
				'js'          => 'application/javascript; charset=UTF-8',
				'mjs'         => 'application/javascript; charset=UTF-8',
				'json'        => 'application/json; charset=UTF-8',
				'map'         => 'application/json; charset=UTF-8',
				'xml'         => 'application/xml; charset=UTF-8',
				'txt'         => 'text/plain; charset=UTF-8',
				'svg'         => 'image/svg+xml',
				'png'         => 'image/png',
				'jpg'         => 'image/jpeg',
				'jpeg'        => 'image/jpeg',
				'gif'         => 'image/gif',
				'webp'        => 'image/webp',
				'avif'        => 'image/avif',
				'ico'         => 'image/x-icon',
				'woff'        => 'font/woff',
				'woff2'       => 'font/woff2',
				'ttf'         => 'font/ttf',
				'otf'         => 'font/otf',
				'eot'         => 'application/vnd.ms-fontobject',
				'webmanifest' => 'application/manifest+json',
				'pdf'         => 'application/pdf',
				'mp4'         => 'video/mp4',
				'webm'        => 'video/webm',
				'wasm'        => 'application/wasm',
				'gz'          => 'application/gzip',
			)
		);
	}

	/**
	 * Register the rewrite rules, the query variables and the request handlers.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rewrites' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 0 );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
	}

	/**
	 * Add this plugin's query variables.
	 *
	 * @param string[] $vars Registered query variables.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = 'stasimo_mount';
		$vars[] = 'stasimo_sitemap';
		return $vars;
	}

	/**
	 * Add one rewrite rule per active mount, and one for the sitemap.
	 */
	public static function register_rewrites() {
		foreach ( Stasimo_Mounts::active() as $slug => $m ) {
			if ( empty( $m['prefix'] ) ) {
				continue;
			}
			add_rewrite_rule( '^' . preg_quote( $m['prefix'], '#' ) . '(/.*)?$', 'index.php?stasimo_mount=' . rawurlencode( $slug ), 'top' );
		}
		add_rewrite_rule( '^stasimo-pages\.xml$', 'index.php?stasimo_sitemap=1', 'top' );
	}

	/** Flush rules once whenever the set of mounts changed (e.g. after a settings save or an import). */
	public static function maybe_flush() {
		if ( get_option( 'stasimo_rules_hash' ) !== Stasimo_Mounts::rules_hash() ) {
			flush_rewrite_rules( false );
			update_option( 'stasimo_rules_hash', Stasimo_Mounts::rules_hash(), false );
		}
	}

	/**
	 * The request URI with percent-encoding intact (sanitize_text_field() would strip it), cleaned with esc_url_raw().
	 */
	private static function request_uri() {
		return isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	}

	/**
	 * Path relative to the mount, taken from the real request URI so a trailing slash is not lost.
	 *
	 * @param array $m Mount.
	 */
	private static function requested_path( array $m ) {
		$path = (string) wp_parse_url( self::request_uri(), PHP_URL_PATH );
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
	 * @param string $base_dir Absolute mount folder.
	 * @param string $rel      Path relative to the mount, as requested.
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

	/**
	 * Serve the requested page or file of a mount, or let WordPress show its 404.
	 */
	public static function handle() {
		$slug = get_query_var( 'stasimo_mount' );
		if ( '' === $slug || null === $slug ) {
			return;
		}
		$m = Stasimo_Mounts::get( $slug );
		if ( ! $m || ! $m['enabled'] ) {
			return;
		}
		$rel = self::requested_path( $m );
		if ( null === $rel ) {
			return;
		}
		$uri_path = (string) wp_parse_url( self::request_uri(), PHP_URL_PATH );
		$slash    = '/' === substr( $uri_path, -1 );
		$rel_req  = $rel . ( $slash && '' !== $rel ? '/' : '' );

		$dir   = Stasimo_Mounts::dir( $m );
		$found = self::resolve( $dir, $rel_req );

		if ( ! $found ) {
			$nf = self::resolve( $dir, '404.html' );
			global $wp_query;
			if ( $nf ) {
				Stasimo_Renderer::render( $m, $nf['file'], $rel_req, 404 );
				exit;
			}
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		$ext = strtolower( pathinfo( $found['file'], PATHINFO_EXTENSION ) );
		if ( in_array( $ext, array( 'html', 'htm' ), true ) ) {
			// Folder pages live at ".../name/": redirect "name" to "name/", which keeps relative links working.
			// This includes the mount root itself, because relative URLs such as assets/x.css break without the slash.
			if ( $found['dir_index'] && ! $slash ) {
				$to    = Stasimo_Mounts::url( $m, '' === $rel ? '' : $rel . '/' );
				$query = (string) wp_parse_url( self::request_uri(), PHP_URL_QUERY );
				if ( '' !== $query ) {
					$to .= '?' . $query;
				}
				wp_safe_redirect( $to, 301 );
				exit;
			}
			$url_rel = $found['dir_index'] ? ( '' === trim( $rel, '/' ) ? '' : trim( $rel, '/' ) . '/' ) : trim( $rel, '/' );
			Stasimo_Renderer::render( $m, $found['file'], $url_rel, 200 );
			exit;
		}

		$types = self::mime_types();
		self::send_file( $found['file'], $types[ $ext ] );
	}

	/**
	 * Send a file with caching headers and answer conditional requests.
	 *
	 * @param string $file Absolute path.
	 * @param string $mime Content type.
	 */
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

		$inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ) : '';
		$ims = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) ) : false;
		if ( $inm === $etag || ( '' === $inm && false !== $ims && $ims >= $mtime ) ) {
			status_header( 304 );
			exit;
		}
		status_header( 200 );
		header( 'Content-Length: ' . $size );
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
			exit;
		}
		// WordPress has no function that streams a file to the client (core itself calls readfile() in ms-files.php), and
		// WP_Filesystem::get_contents() would load the whole file into memory. SplFileObject sends it in constant memory.
		$handle = new SplFileObject( $file, 'rb' );
		$handle->fpassthru();
		exit;
	}
}
