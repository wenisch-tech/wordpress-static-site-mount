<?php
/**
 * Storage of deployed builds.
 *
 * Code (HTML, JavaScript, CSS, JSON, SVG, WebAssembly ...) is stored in a database table and never written to the filesystem.
 * Only media files (images, fonts, PDF, video, plain text) are written, into
 * <uploads>/stasimo-static-site-mount/<mount>/<build>/. These are public files, like any media in uploads.
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storage of deployed builds: code in the database, media in the plugin's uploads folder.
 */
class Stasimo_Store {

	/** Schema version of the files table. */
	const DB_VERSION = '1';

	/** Upper limit for one file stored in the database. */
	const MAX_DB_FILE = 16777216; // 16 MB.

	/** Extensions written to the uploads folder. Every other allowed type is code and goes to the database. */
	const MEDIA = array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'pdf', 'mp4', 'webm', 'txt' );

	/** Create or update the files table {prefix}stasimo_files (per site on multisite). */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$wpdb->prefix}stasimo_files (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  mount varchar(191) NOT NULL,
  build varchar(16) NOT NULL,
  path_hash char(32) NOT NULL,
  path text NOT NULL,
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  mtime bigint(20) unsigned NOT NULL DEFAULT 0,
  hash char(32) NOT NULL DEFAULT '',
  body longblob NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY file (mount,build,path_hash),
  KEY mount_build (mount,build)
) {$charset};"
		);
		update_option( 'stasimo_db_version', self::DB_VERSION );
	}

	/** Create the table after an update (the activation hook does not run on updates). */
	public static function maybe_install() {
		if ( self::DB_VERSION !== get_option( 'stasimo_db_version' ) ) {
			self::install();
		}
	}

	/**
	 * Whether a file is media (written to the uploads folder) rather than code (stored in the database).
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public static function is_media( $path ) {
		return in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), self::MEDIA, true );
	}

	/**
	 * Largest code file the database connection accepts (base64 makes the query a third larger).
	 *
	 * @return int Bytes.
	 */
	public static function max_db_file() {
		global $wpdb;
		$packet = (int) $wpdb->get_var( 'SELECT @@max_allowed_packet' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $packet <= 0 ) {
			$packet = 4194304;
		}
		return (int) min( self::MAX_DB_FILE, floor( ( $packet - 65536 ) * 3 / 4 ) );
	}

	/**
	 * Name of the option that points a mount to its live build.
	 *
	 * @param string $slug Mount name.
	 * @return string
	 */
	private static function option_name( $slug ) {
		return 'stasimo_build_' . $slug;
	}

	/**
	 * Id of the live build of a mount, '' if nothing was deployed.
	 *
	 * @param string $slug Mount name.
	 * @return string
	 */
	public static function current( $slug ) {
		$b = get_option( self::option_name( $slug ), '' );
		return is_string( $b ) && preg_match( '/^b[a-z0-9]{12}$/', $b ) ? $b : '';
	}

	/**
	 * A new, unused build id.
	 *
	 * @return string
	 */
	public static function new_build() {
		return 'b' . strtolower( wp_generate_password( 12, false ) );
	}

	/**
	 * Folder of a mount's media files (all builds).
	 *
	 * @param string $slug Mount name.
	 * @return string Absolute path, or '' if the uploads directory is unknown.
	 */
	public static function mount_dir( $slug ) {
		$base = Stasimo_Mounts::base_dir();
		return '' === $base ? '' : $base . '/' . sanitize_title( $slug );
	}

	/**
	 * Folder of one build's media files.
	 *
	 * @param string $slug  Mount name.
	 * @param string $build Build id.
	 * @return string
	 */
	public static function media_dir( $slug, $build ) {
		$dir = self::mount_dir( $slug );
		return '' === $dir ? '' : $dir . '/' . $build;
	}

	/**
	 * Store one code file of a build in the database.
	 *
	 * @param string $slug  Mount name.
	 * @param string $build Build id.
	 * @param string $path  Path inside the build.
	 * @param string $body  File content.
	 * @param int    $mtime Modification time.
	 * @return true|WP_Error
	 */
	public static function put( $slug, $build, $path, $body, $mtime ) {
		global $wpdb;
		// Base64 keeps the query plain ASCII, so binary content (WebAssembly, gzip) is stored byte for byte.
		$ok = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}stasimo_files (mount, build, path_hash, path, size, mtime, hash, body) VALUES (%s, %s, %s, %s, %d, %d, %s, FROM_BASE64(%s))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$slug,
				$build,
				md5( $path ),
				$path,
				strlen( $body ),
				(int) $mtime,
				md5( $body ),
				base64_encode( $body ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary-safe transport into a BLOB column.
			)
		);
		if ( false === $ok ) {
			/* translators: %s file name */
			return new WP_Error( 'stasimo_db', sprintf( __( 'Could not store %s in the database.', 'stasimo-static-site-mount' ), $path ) );
		}
		return true;
	}

	/**
	 * Metadata of the first of several paths that exists as a code file in the live build.
	 *
	 * @param string   $slug  Mount name.
	 * @param string[] $paths Candidate paths, in order of preference.
	 * @return array|null {path, size, mtime, hash}
	 */
	public static function find( $slug, array $paths ) {
		global $wpdb;
		$build = self::current( $slug );
		if ( '' === $build || ! $paths ) {
			return null;
		}
		$hashes = array_map( 'md5', $paths );
		$in     = implode( ', ', array_fill( 0, count( $hashes ), '%s' ) );
		$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one %s per hash, built above.
				"SELECT path, size, mtime, hash FROM {$wpdb->prefix}stasimo_files WHERE mount = %s AND build = %s AND path_hash IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $slug, $build ), $hashes )
			),
			ARRAY_A
		);
		$by     = array();
		foreach ( (array) $rows as $r ) {
			$by[ $r['path'] ] = $r;
		}
		foreach ( $paths as $p ) {
			if ( isset( $by[ $p ] ) ) {
				return $by[ $p ];
			}
		}
		return null;
	}

	/**
	 * Content of a code file of the live build.
	 *
	 * @param string $slug Mount name.
	 * @param string $path Path inside the build.
	 * @return string|null
	 */
	public static function body( $slug, $path ) {
		global $wpdb;
		$build = self::current( $slug );
		if ( '' === $build ) {
			return null;
		}
		return $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT body FROM {$wpdb->prefix}stasimo_files WHERE mount = %s AND build = %s AND path_hash = %s", $slug, $build, md5( $path ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * HTML pages of the live build, in batches: path, mtime and the first 64 KB (enough for the head).
	 *
	 * @param string   $slug     Mount name.
	 * @param callable $callback Called with (path, mtime, head) for every page.
	 */
	public static function each_page( $slug, callable $callback ) {
		global $wpdb;
		$build = self::current( $slug );
		if ( '' === $build ) {
			return;
		}
		$last = 0;
		do {
			$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT id, path, mtime, SUBSTRING(body, 1, 65536) AS head FROM {$wpdb->prefix}stasimo_files WHERE mount = %s AND build = %s AND id > %d AND path LIKE %s ORDER BY id LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$slug,
					$build,
					$last,
					'%.html'
				),
				ARRAY_A
			);
			$batch = count( (array) $rows );
			foreach ( (array) $rows as $r ) {
				$last = (int) $r['id'];
				call_user_func( $callback, $r['path'], (int) $r['mtime'], (string) $r['head'] );
			}
		} while ( 200 === $batch );
	}

	/**
	 * Make a build live and delete every other build of the mount.
	 *
	 * @param string $slug  Mount name.
	 * @param string $build Build id.
	 */
	public static function activate( $slug, $build ) {
		update_option( self::option_name( $slug ), $build, true );
		self::prune( $slug, $build );
	}

	/**
	 * Delete everything of a mount except one build ('' deletes all).
	 *
	 * @param string $slug Mount name.
	 * @param string $keep Build id to keep.
	 */
	public static function prune( $slug, $keep = '' ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}stasimo_files WHERE mount = %s AND build <> %s", $slug, $keep ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dir = self::mount_dir( $slug );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		foreach ( new DirectoryIterator( $dir ) as $f ) {
			if ( $f->isDot() || $f->getFilename() === $keep ) {
				continue;
			}
			if ( $f->isDir() && ! $f->isLink() ) {
				Stasimo_Files::remove_dir( $f->getPathname() );
			} else {
				wp_delete_file( $f->getPathname() );
			}
		}
		if ( '' === $keep ) {
			Stasimo_Files::fs()->rmdir( $dir, false );
		}
	}

	/**
	 * Delete one unfinished build (after a failed deploy). The live build is not touched.
	 *
	 * @param string $slug  Mount name.
	 * @param string $build Build id.
	 */
	public static function discard( $slug, $build ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}stasimo_files WHERE mount = %s AND build = %s", $slug, $build ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dir = self::media_dir( $slug, $build );
		if ( '' !== $dir ) {
			Stasimo_Files::remove_dir( $dir );
		}
	}

	/**
	 * Remove all stored data of a mount.
	 *
	 * @param string $slug Mount name.
	 */
	public static function remove_mount( $slug ) {
		self::prune( $slug, '' );
		delete_option( self::option_name( $slug ) );
	}
}
