<?php
/**
 * Scans a mount's folder and stores title, description and modification time per page.
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scans a mount's folder and stores title, description and modification time per page.
 */
class Stasimo_Indexer {

	const MAX_FILES = 20000;

	/**
	 * Name of the option that holds the page index of a mount.
	 *
	 * @param string $slug Mount name.
	 * @return string
	 */
	public static function option_name( $slug ) {
		return 'stasimo_index_' . $slug;
	}

	/**
	 * URL path (relative to the mount) for an HTML file. foo/index.html => foo/ , foo.html => foo.html.
	 *
	 * @param string $relative File path relative to the mount folder.
	 * @return string
	 */
	public static function url_path( $relative ) {
		$relative = str_replace( '\\', '/', $relative );
		if ( 'index.html' === $relative ) {
			return '';
		}
		if ( '/index.html' === substr( $relative, -11 ) ) {
			return substr( $relative, 0, -10 );
		}
		return $relative;
	}

	/**
	 * Rebuild the index for one mount.
	 *
	 * @param array $m Mount.
	 * @return array|WP_Error {count:int, time:int}
	 */
	public static function reindex( array $m ) {
		$dir = Stasimo_Mounts::dir( $m );
		if ( ! is_dir( $dir ) ) {
			return new WP_Error( 'stasimo_nodir', sprintf( /* translators: %s folder */ __( 'Folder not found: %s', 'stasimo-static-site-mount' ), $dir ) );
		}
		$real = realpath( $dir );
		$list = array();
		$n    = 0;
		$it   = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $it as $file ) {
			if ( ++$n > self::MAX_FILES * 5 ) {
				break;
			}
			if ( ! $file->isFile() || 'html' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$rel = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $real ) ) ), '/' );
			if ( preg_match( '#(^|/)\.#', $rel ) || '404.html' === basename( $rel ) ) {
				continue;
			}
			if ( count( $list ) >= self::MAX_FILES ) {
				break;
			}
			$head = substr( (string) Stasimo_Files::fs()->get_contents( $file->getPathname() ), 0, 65536 );
			if ( preg_match( '/<head[^>]*>(.*?)<\/head>/is', $head, $hm ) ) {
				$head = $hm[1];
			}
			$seo                            = Stasimo_HTML::seo( $head );
			$list[ self::url_path( $rel ) ] = array(
				't' => $seo['title'],
				'd' => $seo['description'],
				'm' => (int) $file->getMTime(),
				'n' => false !== strpos( $seo['robots'], 'noindex' ) ? 1 : 0,
			);
		}
		ksort( $list );
		update_option(
			self::option_name( $m['slug'] ),
			array(
				'time'  => time(),
				'pages' => $list,
			),
			false
		);
		return array(
			'count' => count( $list ),
			'time'  => time(),
		);
	}

	/**
	 * Stored page index of a mount.
	 *
	 * @param string $slug Mount name.
	 * @return array
	 */
	public static function get( $slug ) {
		$data = get_option( self::option_name( $slug ), array() );
		return is_array( $data ) && isset( $data['pages'] ) ? $data : array(
			'time'  => 0,
			'pages' => array(),
		);
	}
}
