<?php
/**
 * Replaces a mount's folder with the contents of a zip file or folder, atomically, then re-indexes.
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

class SSM_Deployer {

	const MAX_FILES = 50000;
	const MAX_BYTES = 536870912; // 512 MB unpacked.

	private static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			if ( $f->isDir() && ! $f->isLink() ) {
				rmdir( $f->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			} else {
				unlink( $f->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/** Executable-looking names are refused even with an allowed last extension (shell.php.png, x.phtml.css ...). */
	private static function is_dangerous_name( $name ) {
		return (bool) preg_match( '/\.(php[0-9]*|phtml|pht|phar|cgi|pl|py|rb|sh|asp|aspx|jsp|htaccess)(\.|$)/i', basename( $name ) );
	}

	/** The mount folder must sit inside the base folder: a deploy renames and deletes it. */
	private static function dir_is_safe( $dir ) {
		if ( apply_filters( 'ssm_allow_external_dirs', false ) ) {
			return '' !== $dir && '/' !== $dir;
		}
		$base = SSM_Mounts::base_dir();
		return 0 === strpos( $dir, $base . '/' ) && false === strpos( $dir, '..' ) && strlen( $dir ) > strlen( $base ) + 1;
	}

	/**
	 * Normalize a path from an archive. Returns null for entries that must be skipped, WP_Error for hostile ones.
	 */
	private static function safe_name( $name ) {
		$name = str_replace( '\\', '/', $name );
		if ( false !== strpos( $name, "\0" ) || preg_match( '#^([A-Za-z]:|/)#', $name ) ) {
			return new WP_Error( 'ssm_zip_path', sprintf( /* translators: %s file name */ __( 'Unsafe path in archive: %s', 'static-site-mount' ), $name ) );
		}
		foreach ( explode( '/', $name ) as $seg ) {
			if ( '..' === $seg ) {
				return new WP_Error( 'ssm_zip_path', sprintf( /* translators: %s file name */ __( 'Unsafe path in archive: %s', 'static-site-mount' ), $name ) );
			}
		}
		return $name;
	}

	/**
	 * Deploy a zip file into a mount.
	 *
	 * @return array|WP_Error
	 */
	public static function deploy_zip( array $m, $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'ssm_nozip', __( 'The PHP zip extension is not available on this server.', 'static-site-mount' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'ssm_badzip', __( 'The uploaded file is not a valid zip archive.', 'static-site-mount' ) );
		}

		$types   = SSM_Server::mime_types();
		$entries = array();
		$total   = 0;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
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
				return new WP_Error( 'ssm_toobig', __( 'The archive is too large.', 'static-site-mount' ) );
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

		$dir = SSM_Mounts::dir( $m );
		if ( ! self::dir_is_safe( $dir ) ) {
			$zip->close();
			return new WP_Error( 'ssm_dir', __( 'The mount folder is outside the allowed location.', 'static-site-mount' ) );
		}
		SSM_Mounts::prepare_base_dir();
		wp_mkdir_p( dirname( $dir ) );
		$tmp = $dir . '.ssm-new-' . wp_generate_password( 6, false );
		$old = $dir . '.ssm-old-' . wp_generate_password( 6, false );
		if ( ! wp_mkdir_p( $tmp ) ) {
			$zip->close();
			return new WP_Error( 'ssm_mkdir', __( 'Could not create a temporary folder.', 'static-site-mount' ) );
		}

		$written = 0;
		$actual  = 0;
		foreach ( $entries as $i => $name ) {
			if ( '' !== $strip && 0 === strpos( $name, $strip ) ) {
				$name = substr( $name, strlen( $strip ) );
			}
			if ( '' === $name || preg_match( '#(^|/)\.#', $name ) ) {
				continue; // dotfiles such as .nojekyll or .DS_Store are ignored.
			}
			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! isset( $types[ $ext ] ) || self::is_dangerous_name( $name ) ) {
				$zip->close();
				self::rrmdir( $tmp );
				return new WP_Error( 'ssm_ext', sprintf( /* translators: %s file name */ __( 'File type not allowed: %s', 'static-site-mount' ), $name ) );
			}
			$target = $tmp . '/' . $name;
			wp_mkdir_p( dirname( $target ) );
			$in  = $zip->getStream( $zip->getNameIndex( $i ) );
			$out = fopen( $target, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! $in || ! $out ) {
				$zip->close();
				self::rrmdir( $tmp );
				return new WP_Error( 'ssm_write', __( 'Could not write extracted files.', 'static-site-mount' ) );
			}
			// The size in a zip header can be forged: count what is really written.
			$copied = stream_copy_to_stream( $in, $out, self::MAX_BYTES - $actual + 1 );
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$actual += (int) $copied;
			if ( $actual > self::MAX_BYTES ) {
				$zip->close();
				self::rrmdir( $tmp );
				return new WP_Error( 'ssm_toobig', __( 'The archive is too large.', 'static-site-mount' ) );
			}
			$written++;
		}
		$zip->close();
		return self::swap( $m, $dir, $tmp, $old, $written );
	}

	/**
	 * Deploy from a folder on the same server (used by WP-CLI).
	 */
	public static function deploy_dir( array $m, $src ) {
		$src = realpath( $src );
		if ( ! $src || ! is_dir( $src ) ) {
			return new WP_Error( 'ssm_src', __( 'Source folder not found.', 'static-site-mount' ) );
		}
		$types = SSM_Server::mime_types();
		$dir   = SSM_Mounts::dir( $m );
		if ( ! self::dir_is_safe( $dir ) ) {
			return new WP_Error( 'ssm_dir', __( 'The mount folder is outside the allowed location.', 'static-site-mount' ) );
		}
		SSM_Mounts::prepare_base_dir();
		wp_mkdir_p( dirname( $dir ) );
		$tmp     = $dir . '.ssm-new-' . wp_generate_password( 6, false );
		$old     = $dir . '.ssm-old-' . wp_generate_password( 6, false );
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
				return new WP_Error( 'ssm_ext', sprintf( /* translators: %s file name */ __( 'File type not allowed: %s', 'static-site-mount' ), $rel ) );
			}
			wp_mkdir_p( dirname( $tmp . '/' . $rel ) );
			copy( $f->getPathname(), $tmp . '/' . $rel ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$written++;
		}
		return self::swap( $m, $dir, $tmp, $old, $written );
	}

	private static function swap( array $m, $dir, $tmp, $old, $written ) {
		if ( ! is_file( $tmp . '/index.html' ) ) {
			self::rrmdir( $tmp );
			return new WP_Error( 'ssm_noindex', __( 'The build has no index.html at its top level, nothing was changed.', 'static-site-mount' ) );
		}
		if ( is_dir( $dir ) && ! rename( $dir, $old ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			self::rrmdir( $tmp );
			return new WP_Error( 'ssm_swap', __( 'Could not replace the existing folder.', 'static-site-mount' ) );
		}
		if ( ! rename( $tmp, $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_dir( $old ) ) {
				rename( $old, $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			self::rrmdir( $tmp );
			return new WP_Error( 'ssm_swap', __( 'Could not activate the new build.', 'static-site-mount' ) );
		}
		self::rrmdir( $old );
		$idx = SSM_Indexer::reindex( $m );
		if ( is_wp_error( $idx ) ) {
			return $idx;
		}
		do_action( 'ssm_deployed', $m, $idx );
		return array(
			'files' => $written,
			'pages' => $idx['count'],
		);
	}
}
