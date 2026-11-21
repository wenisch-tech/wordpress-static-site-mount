<?php
/**
 * Replaces a mount's folder with the contents of a zip file or folder, atomically, then re-indexes.
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

/**
 * Replaces a mount's folder with the contents of a zip file or a folder, atomically, then re-indexes.
 */
class Stasimo_Deployer {

	const MAX_FILES = 50000;
	const MAX_BYTES = 536870912; // 512 MB unpacked.

	/**
	 * Delete a folder with everything in it.
	 *
	 * @param string $dir Absolute folder path.
	 */
	private static function rrmdir( $dir ) {
		Stasimo_Files::remove_dir( $dir );
	}

	/**
	 * Executable-looking names are refused even with an allowed last extension (shell.php.png, x.phtml.css ...).
	 *
	 * @param string $name File name or path from an archive.
	 * @return bool
	 */
	private static function is_dangerous_name( $name ) {
		return (bool) preg_match( '/\.(php[0-9]*|phtml|pht|phar|cgi|pl|py|rb|sh|asp|aspx|jsp|htaccess)(\.|$)/i', basename( $name ) );
	}

	/**
	 * The mount folder must sit inside the base folder: a deploy renames and deletes it.
	 *
	 * @param string $dir Absolute mount folder.
	 * @return bool
	 */
	private static function dir_is_safe( $dir ) {
		if ( apply_filters( 'stasimo_allow_external_dirs', false ) ) {
			return '' !== $dir && '/' !== $dir;
		}
		$base = Stasimo_Mounts::base_dir();
		return 0 === strpos( $dir, $base . '/' ) && false === strpos( $dir, '..' ) && strlen( $dir ) > strlen( $base ) + 1;
	}

	/**
	 * Normalize a path from an archive. Returns null for entries that must be skipped, WP_Error for hostile ones.
	 *
	 * @param string $name Path of an entry in the archive.
	 * @return string|WP_Error
	 */
	private static function safe_name( $name ) {
		if ( false !== strpos( $name, "\0" ) || false !== strpos( $name, '\\' ) || preg_match( '#^([A-Za-z]:|/)#', $name ) ) {
			return new WP_Error( 'stasimo_zip_path', sprintf( /* translators: %s file name */ __( 'Unsafe path in archive: %s', 'stasimo-static-site-mount' ), $name ) );
		}
		foreach ( explode( '/', $name ) as $seg ) {
			if ( '..' === $seg ) {
				return new WP_Error( 'stasimo_zip_path', sprintf( /* translators: %s file name */ __( 'Unsafe path in archive: %s', 'stasimo-static-site-mount' ), $name ) );
			}
		}
		return $name;
	}

	/**
	 * Deploy a zip file into a mount.
	 *
	 * @param array  $m        Mount.
	 * @param string $zip_path Absolute path of the zip file.
	 * @return array|WP_Error
	 */
	public static function deploy_zip( array $m, $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'stasimo_nozip', __( 'The PHP zip extension is not available on this server.', 'stasimo-static-site-mount' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'stasimo_badzip', __( 'The uploaded file is not a valid zip archive.', 'stasimo-static-site-mount' ) );
		}

		$types   = Stasimo_Server::mime_types();
		$entries = array();
		$total   = 0;
		$count   = $zip->count();
		for ( $i = 0; $i < $count; $i++ ) {
			$st   = $zip->statIndex( $i );
			$name = self::safe_name( $st['name'] );
			if ( is_wp_error( $name ) ) {
				$zip->close();
				return $name;
			}
			if ( '/' === substr( $name, -1 ) ) {
				continue; // directory entry.
			}
			$total += (int) $st['size'];
			if ( count( $entries ) >= self::MAX_FILES || $total > self::MAX_BYTES ) {
				$zip->close();
				return new WP_Error( 'stasimo_toobig', __( 'The archive is too large.', 'stasimo-static-site-mount' ) );
			}
			$entries[ $i ] = $name;
		}

		// A zip of a "build/" folder has one common top-level folder: unwrap it.
		$roots = array();
		foreach ( $entries as $n ) {
			$roots[ explode( '/', $n )[0] ] = true;
		}
		$strip = '';
		if ( 1 === count( $roots ) ) {
			$only = (string) key( $roots );
			if ( ! in_array( $only, $entries, true ) ) { // a single root *file* (e.g. only index.html) is not a folder.
				$strip = $only . '/';
			}
		}

		// Decide what is kept before anything is written: no dotfiles, only allowed types, nothing executable-looking.
		$keep = array();
		foreach ( $entries as $i => $name ) {
			$rel = ( '' !== $strip && 0 === strpos( $name, $strip ) ) ? substr( $name, strlen( $strip ) ) : $name;
			if ( '' === $rel || preg_match( '#(^|/)\.#', $rel ) ) {
				continue; // dotfiles such as .nojekyll or .DS_Store are ignored.
			}
			$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
			if ( ! isset( $types[ $ext ] ) || self::is_dangerous_name( $rel ) ) {
				$zip->close();
				return new WP_Error( 'stasimo_ext', sprintf( /* translators: %s file name */ __( 'File type not allowed: %s', 'stasimo-static-site-mount' ), $rel ) );
			}
			$keep[ $i ] = $name;
		}

		// The sizes in a zip header can be forged and PHP's zip library does not enforce them. Read every kept entry once, without
		// writing, and refuse the archive if its real size differs from the header or exceeds the limit.
		$actual = 0;
		foreach ( $keep as $i => $name ) {
			$stream = $zip->getStream( $zip->getNameIndex( $i ) );
			if ( ! $stream ) {
				$zip->close();
				return new WP_Error( 'stasimo_badzip', __( 'The uploaded file is not a valid zip archive.', 'stasimo-static-site-mount' ) );
			}
			$real = 0;
			while ( ! feof( $stream ) ) {
				$chunk = stream_get_contents( $stream, 1048576 );
				if ( false === $chunk ) {
					break;
				}
				$real += strlen( $chunk );
				if ( $actual + $real > self::MAX_BYTES ) {
					$zip->close();
					return new WP_Error( 'stasimo_toobig', __( 'The archive is too large.', 'stasimo-static-site-mount' ) );
				}
			}
			$declared = $zip->statIndex( $i );
			if ( $real !== (int) $declared['size'] ) {
				$zip->close();
				return new WP_Error( 'stasimo_badzip', __( 'The archive is damaged: a file is not the size its header says.', 'stasimo-static-site-mount' ) );
			}
			$actual += $real;
		}

		$dir = Stasimo_Mounts::dir( $m );
		if ( ! self::dir_is_safe( $dir ) ) {
			$zip->close();
			return new WP_Error( 'stasimo_dir', __( 'The mount folder is outside the allowed location.', 'stasimo-static-site-mount' ) );
		}
		Stasimo_Mounts::prepare_base_dir();
		wp_mkdir_p( dirname( $dir ) );
		$work = $dir . '.stasimo-new-' . wp_generate_password( 6, false );
		$old  = $dir . '.stasimo-old-' . wp_generate_password( 6, false );
		if ( ! wp_mkdir_p( $work ) ) {
			$zip->close();
			return new WP_Error( 'stasimo_mkdir', __( 'Could not create a temporary folder.', 'stasimo-static-site-mount' ) );
		}
		$done = $zip->extractTo( $work, array_map( array( $zip, 'getNameIndex' ), array_keys( $keep ) ) );
		$zip->close();
		if ( ! $done ) {
			self::rrmdir( $work );
			return new WP_Error( 'stasimo_write', __( 'Could not write extracted files.', 'stasimo-static-site-mount' ) );
		}
		$src = '' !== $strip ? $work . '/' . rtrim( $strip, '/' ) : $work;
		return self::swap( $m, $dir, $src, $old, count( $keep ), $work );
	}

	/**
	 * Deploy from a folder on the same server (used by WP-CLI).
	 *
	 * @param array  $m   Mount.
	 * @param string $src Absolute path of the source folder.
	 */
	public static function deploy_dir( array $m, $src ) {
		$src = realpath( $src );
		if ( ! $src || ! is_dir( $src ) ) {
			return new WP_Error( 'stasimo_src', __( 'Source folder not found.', 'stasimo-static-site-mount' ) );
		}
		$types = Stasimo_Server::mime_types();
		$dir   = Stasimo_Mounts::dir( $m );
		if ( ! self::dir_is_safe( $dir ) ) {
			return new WP_Error( 'stasimo_dir', __( 'The mount folder is outside the allowed location.', 'stasimo-static-site-mount' ) );
		}
		Stasimo_Mounts::prepare_base_dir();
		wp_mkdir_p( dirname( $dir ) );
		$tmp     = $dir . '.stasimo-new-' . wp_generate_password( 6, false );
		$old     = $dir . '.stasimo-old-' . wp_generate_password( 6, false );
		$written = 0;
		wp_mkdir_p( $tmp );
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY );
		foreach ( $it as $f ) {
			$rel = ltrim( str_replace( '\\', '/', substr( $f->getPathname(), strlen( $src ) ) ), '/' );
			if ( preg_match( '#(^|/)\.#', $rel ) ) {
				continue;
			}
			$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
			if ( ! isset( $types[ $ext ] ) || self::is_dangerous_name( $rel ) ) {
				self::rrmdir( $tmp );
				return new WP_Error( 'stasimo_ext', sprintf( /* translators: %s file name */ __( 'File type not allowed: %s', 'stasimo-static-site-mount' ), $rel ) );
			}
			wp_mkdir_p( dirname( $tmp . '/' . $rel ) );
			Stasimo_Files::fs()->copy( $f->getPathname(), $tmp . '/' . $rel, true );
			++$written;
		}
		return self::swap( $m, $dir, $tmp, $old, $written, $tmp );
	}

	/**
	 * Put a finished build in place. $src is the folder with index.html, $work the temporary folder that contains it (deleted afterwards).
	 *
	 * @param array  $m       Mount.
	 * @param string $dir     Final mount folder.
	 * @param string $src     Folder that holds the new build (with index.html).
	 * @param string $old     Where the previous build is moved to.
	 * @param int    $written Number of files in the new build.
	 * @param string $work    Temporary folder that contains $src, deleted afterwards.
	 */
	private static function swap( array $m, $dir, $src, $old, $written, $work ) {
		$fs = Stasimo_Files::fs();
		if ( ! is_file( $src . '/index.html' ) ) {
			self::rrmdir( $work );
			return new WP_Error( 'stasimo_noindex', __( 'The build has no index.html at its top level, nothing was changed.', 'stasimo-static-site-mount' ) );
		}
		// Folder moves are renames inside one filesystem, so a visitor sees either the old build or the new one.
		if ( is_dir( $dir ) && ! $fs->move( $dir, $old, false ) ) {
			self::rrmdir( $work );
			return new WP_Error( 'stasimo_swap', __( 'Could not replace the existing folder.', 'stasimo-static-site-mount' ) );
		}
		if ( ! $fs->move( $src, $dir, false ) ) {
			if ( is_dir( $old ) ) {
				$fs->move( $old, $dir, false );
			}
			self::rrmdir( $work );
			return new WP_Error( 'stasimo_swap', __( 'Could not activate the new build.', 'stasimo-static-site-mount' ) );
		}
		if ( $work !== $src ) {
			self::rrmdir( $work ); // the wrapper folder of an unwrapped build.
		}
		self::rrmdir( $old );
		$idx = Stasimo_Indexer::reindex( $m );
		if ( is_wp_error( $idx ) ) {
			return $idx;
		}
		do_action( 'stasimo_deployed', $m, $idx );
		return array(
			'files' => $written,
			'pages' => $idx['count'],
		);
	}
}
