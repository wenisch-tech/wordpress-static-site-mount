<?php
/**
 * File helpers on top of WP_Filesystem, for the folders this plugin owns inside the uploads directory.
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

/**
 * File helpers on top of WP_Filesystem, for the folders this plugin owns inside the uploads directory.
 */
class Stasimo_Files {

	/**
	 * The filesystem object, created on first use.
	 *
	 * @var WP_Filesystem_Base|null
	 */
	private static $fs = null;

	/**
	 * WP_Filesystem with the "direct" method. The plugin only touches its own folder in uploads, which PHP can already write,
	 * and it also runs from REST and WP-CLI where no one can type FTP credentials.
	 *
	 * @return WP_Filesystem_Base
	 */
	public static function fs() {
		if ( null === self::$fs ) {
			global $wp_filesystem;
			require_once ABSPATH . 'wp-admin/includes/file.php';
			add_filter( 'filesystem_method', array( __CLASS__, 'direct' ), 99 );
			WP_Filesystem();
			remove_filter( 'filesystem_method', array( __CLASS__, 'direct' ), 99 );
			self::$fs = $wp_filesystem;
		}
		return self::$fs;
	}

	/**
	 * Filter callback: use the direct filesystem method.
	 *
	 * @return string
	 */
	public static function direct() {
		return 'direct';
	}

	/**
	 * Delete a folder with everything in it. Symbolic links are removed themselves, never followed.
	 *
	 * @param string $dir Absolute folder path.
	 */
	public static function remove_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			if ( $f->isDir() && ! $f->isLink() ) {
				self::fs()->rmdir( $f->getPathname(), false );
			} else {
				wp_delete_file( $f->getPathname() );
			}
		}
		self::fs()->rmdir( $dir, false );
	}
}
