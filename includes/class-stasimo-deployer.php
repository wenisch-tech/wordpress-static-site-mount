<?php
/**
 * Deploys a build from a zip file or folder: code into the database, media into the uploads folder, then re-indexes.
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deploys a build from a zip file or folder: code into the database, media into the uploads folder, then re-indexes.
 */
class Stasimo_Deployer {

	const MAX_FILES = 50000;
	const MAX_BYTES = 536870912; // 512 MB unpacked.

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

		$rels = array();
		foreach ( $keep as $i => $name ) {
			$rels[ $i ] = '' !== $strip ? substr( $name, strlen( $strip ) ) : $name;
		}
		if ( ! in_array( 'index.html', $rels, true ) ) {
			$zip->close();
			return new WP_Error( 'stasimo_noindex', __( 'The build has no index.html at its top level, nothing was changed.', 'stasimo-static-site-mount' ) );
		}

		// Code files go into the database. Only media files are written to the plugin's uploads folder; nothing is extracted as a whole.
		$build  = Stasimo_Store::new_build();
		$max_db = Stasimo_Store::max_db_file();
		$media  = self::media_dir( $m, $build );
		if ( is_wp_error( $media ) ) {
			$zip->close();
			return $media;
		}
		foreach ( $rels as $i => $rel ) {
			$st = $zip->statIndex( $i );
			if ( Stasimo_Store::is_media( $rel ) ) {
				$ok = self::copy_media( $media, $rel, 'zip://' . $zip_path . '#' . $zip->getNameIndex( $i ), $zip, $i );
			} elseif ( (int) $st['size'] > $max_db ) {
				$ok = self::too_big( $rel, $max_db );
			} else {
				$ok = Stasimo_Store::put( $m['slug'], $build, $rel, (string) $zip->getFromIndex( $i ), (int) $st['mtime'] );
			}
			if ( is_wp_error( $ok ) ) {
				$zip->close();
				Stasimo_Store::discard( $m['slug'], $build );
				return $ok;
			}
		}
		$zip->close();
		return self::publish( $m, $build, count( $rels ) );
	}

	/**
	 * Deploy from a folder on the same server (used by WP-CLI). The source folder is only read.
	 *
	 * @param array  $m   Mount.
	 * @param string $src Absolute path of the source folder.
	 */
	public static function deploy_dir( array $m, $src ) {
		$src = realpath( $src );
		if ( ! $src || ! is_dir( $src ) ) {
			return new WP_Error( 'stasimo_src', __( 'Source folder not found.', 'stasimo-static-site-mount' ) );
		}
		if ( ! is_file( $src . '/index.html' ) ) {
			return new WP_Error( 'stasimo_noindex', __( 'The build has no index.html at its top level, nothing was changed.', 'stasimo-static-site-mount' ) );
		}
		$types = Stasimo_Server::mime_types();
		$files = array();
		$total = 0;
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY );
		foreach ( $it as $f ) {
			$rel = ltrim( str_replace( '\\', '/', substr( $f->getPathname(), strlen( $src ) ) ), '/' );
			if ( preg_match( '#(^|/)\.#', $rel ) || $f->isLink() ) {
				continue;
			}
			$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
			if ( ! isset( $types[ $ext ] ) || self::is_dangerous_name( $rel ) ) {
				return new WP_Error( 'stasimo_ext', sprintf( /* translators: %s file name */ __( 'File type not allowed: %s', 'stasimo-static-site-mount' ), $rel ) );
			}
			$total += (int) $f->getSize();
			if ( count( $files ) >= self::MAX_FILES || $total > self::MAX_BYTES ) {
				return new WP_Error( 'stasimo_toobig', __( 'The folder is too large.', 'stasimo-static-site-mount' ) );
			}
			$files[ $rel ] = $f;
		}

		$build  = Stasimo_Store::new_build();
		$max_db = Stasimo_Store::max_db_file();
		$media  = self::media_dir( $m, $build );
		if ( is_wp_error( $media ) ) {
			return $media;
		}
		$fs = Stasimo_Files::fs();
		foreach ( $files as $rel => $f ) {
			if ( Stasimo_Store::is_media( $rel ) ) {
				$ok = self::copy_media( $media, $rel, $f->getPathname() );
			} elseif ( (int) $f->getSize() > $max_db ) {
				$ok = self::too_big( $rel, $max_db );
			} else {
				$ok = Stasimo_Store::put( $m['slug'], $build, $rel, (string) $fs->get_contents( $f->getPathname() ), (int) $f->getMTime() );
			}
			if ( is_wp_error( $ok ) ) {
				Stasimo_Store::discard( $m['slug'], $build );
				return $ok;
			}
		}
		return self::publish( $m, $build, count( $files ) );
	}

	/**
	 * Media folder of a new build inside the plugin's uploads folder.
	 *
	 * @param array  $m     Mount.
	 * @param string $build Build id.
	 * @return string|WP_Error
	 */
	private static function media_dir( array $m, $build ) {
		Stasimo_Mounts::prepare_base_dir();
		$dir = Stasimo_Store::media_dir( $m['slug'], $build );
		if ( '' === $dir || ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'stasimo_mkdir', __( 'Could not create a folder in the uploads directory.', 'stasimo-static-site-mount' ) );
		}
		return $dir;
	}

	/**
	 * Copy one media file into the media folder of a build, from a source folder or straight out of the archive (zip:// stream).
	 * A copy streams the file, so a large video does not count against the memory limit.
	 *
	 * @param string          $dir  Media folder of the build.
	 * @param string          $rel  Checked path inside the build.
	 * @param string          $from Source path or zip:// URL.
	 * @param ZipArchive|null $zip  Open archive, used when the entry name cannot be addressed as a zip:// URL.
	 * @param int             $i    Index of the entry in the archive.
	 * @return true|WP_Error
	 */
	private static function copy_media( $dir, $rel, $from, $zip = null, $i = 0 ) {
		$dest = $dir . '/' . $rel;
		$fail = new WP_Error( 'stasimo_write', sprintf( /* translators: %s file name */ __( 'Could not write %s.', 'stasimo-static-site-mount' ), $rel ) );
		if ( ! wp_mkdir_p( dirname( $dest ) ) ) {
			return $fail;
		}
		if ( $zip && false !== strpos( $rel, '#' ) ) { // "#" separates the entry in a zip:// URL.
			$data = $zip->getFromIndex( $i );
			return false !== $data && Stasimo_Files::fs()->put_contents( $dest, $data, FS_CHMOD_FILE ) ? true : $fail;
		}
		return Stasimo_Files::fs()->copy( $from, $dest, true, FS_CHMOD_FILE ) ? true : $fail;
	}

	/**
	 * Error for a code file that is larger than the database accepts.
	 *
	 * @param string $rel File path.
	 * @param int    $max Limit in bytes.
	 * @return WP_Error
	 */
	private static function too_big( $rel, $max ) {
		/* translators: 1: file name, 2: size limit */
		return new WP_Error( 'stasimo_toobig', sprintf( __( '%1$s is larger than %2$s, the most the database accepts for one file.', 'stasimo-static-site-mount' ), $rel, size_format( $max ) ) );
	}

	/**
	 * Make a fully stored build live, delete the previous one and re-index.
	 *
	 * @param array  $m       Mount.
	 * @param string $build   Build id.
	 * @param int    $written Number of files in the build.
	 * @return array|WP_Error
	 */
	private static function publish( array $m, $build, $written ) {
		// Switching the pointer is one option update, so a visitor sees either the old build or the new one.
		Stasimo_Store::activate( $m['slug'], $build );
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
