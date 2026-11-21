<?php
/**
 * REST API for CI: list mounts, re-index, deploy a zip.
 * Authenticate with a WordPress Application Password (Users > Profile).
 *
 * @package StaticSiteMount
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST API for CI: list mounts, create a mount, re-index and deploy a zip.
 */
class Stasimo_Rest {

	/**
	 * Register the REST routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Permission check of every route.
	 *
	 * @return bool
	 */
	public static function can() {
		return Stasimo_Mounts::can_manage();
	}

	/**
	 * Register the routes of the stasimo/v1 namespace.
	 */
	public static function routes() {
		register_rest_route(
			'stasimo/v1',
			'/mounts',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can' ),
				'callback'            => function () {
					$out = array();
					foreach ( Stasimo_Mounts::all() as $slug => $m ) {
						$m           = wp_parse_args( $m, Stasimo_Mounts::defaults() );
						$idx         = Stasimo_Indexer::get( $slug );
						$out[ $slug ] = array(
							'prefix'  => $m['prefix'],
							'url'     => Stasimo_Mounts::url( $m ),
							'preset'  => $m['preset'],
							'enabled' => (bool) $m['enabled'],
							'trusted' => (bool) $m['trusted'],
							'pages'   => count( $idx['pages'] ),
							'indexed' => $idx['time'] ? gmdate( 'c', $idx['time'] ) : null,
						);
					}
					return $out;
				},
			)
		);

		register_rest_route(
			'stasimo/v1',
			'/mounts/(?P<slug>[a-z0-9-]+)',
			array(
				'methods'             => 'PUT',
				'permission_callback' => array( __CLASS__, 'can' ),
				'callback'            => function ( WP_REST_Request $req ) {
					$raw         = (array) $req->get_json_params();
					$raw['slug'] = $req['slug'];
					$existing    = Stasimo_Mounts::get( $req['slug'] );
					if ( $existing && ! isset( $raw['enabled'] ) ) {
						$raw['enabled'] = $existing['enabled'];
					}
					if ( $existing && ! isset( $raw['trusted'] ) ) {
						$raw['trusted'] = $existing['trusted'];
					}
					$m           = Stasimo_Mounts::sanitize( $raw );
					if ( is_wp_error( $m ) ) {
						return new WP_Error( $m->get_error_code(), $m->get_error_message(), array( 'status' => 400 ) );
					}
					$conflict = Stasimo_Mounts::conflict( $m );
					if ( '' !== $conflict ) {
						return new WP_Error( 'stasimo_conflict', $conflict, array( 'status' => 409 ) );
					}
					$all               = Stasimo_Mounts::all();
					$all[ $m['slug'] ] = $m;
					Stasimo_Mounts::save_all( $all );
					Stasimo_Mounts::prepare_base_dir();
					return array_merge( $m, array( 'url' => Stasimo_Mounts::url( $m ) ) );
				},
			)
		);

		register_rest_route(
			'stasimo/v1',
			'/mounts/(?P<slug>[a-z0-9-]+)/reindex',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can' ),
				'callback'            => function ( WP_REST_Request $req ) {
					$m = Stasimo_Mounts::get( $req['slug'] );
					if ( ! $m ) {
						return new WP_Error( 'stasimo_unknown', __( 'Unknown mount.', 'stasimo-static-site-mount' ), array( 'status' => 404 ) );
					}
					$r = Stasimo_Indexer::reindex( $m );
					return is_wp_error( $r ) ? new WP_Error( $r->get_error_code(), $r->get_error_message(), array( 'status' => 422 ) ) : $r;
				},
			)
		);

		register_rest_route(
			'stasimo/v1',
			'/mounts/(?P<slug>[a-z0-9-]+)/deploy',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can' ),
				'callback'            => array( __CLASS__, 'deploy' ),
			)
		);
	}

	/**
	 * Accepts a multipart upload (field "file", recommended: no memory spike) or a raw zip body.
	 *
	 * @param WP_REST_Request $req Request with the mount name and the zip.
	 */
	public static function deploy( WP_REST_Request $req ) {
		$m = Stasimo_Mounts::get( $req['slug'] );
		if ( ! $m ) {
			return new WP_Error( 'stasimo_unknown', __( 'Unknown mount.', 'stasimo-static-site-mount' ), array( 'status' => 404 ) );
		}
		$lock = self::lock( $m['slug'] );
		if ( ! $lock ) {
			return new WP_Error( 'stasimo_busy', __( 'A deploy for this mount is already running.', 'stasimo-static-site-mount' ), array( 'status' => 409 ) );
		}
		// Release the lock even if PHP dies mid-deploy (fatal error, timeout), so the next run is not blocked.
		register_shutdown_function(
			function () use ( $lock ) {
				delete_option( $lock );
			}
		);

		$tmp     = '';
		$cleanup = false;
		$files   = $req->get_file_params();
		if ( ! empty( $files['file'] ) && isset( $files['file']['error'] ) && UPLOAD_ERR_OK !== (int) $files['file']['error'] ) {
			delete_option( $lock );
			return new WP_Error( 'stasimo_upload', self::upload_error( (int) $files['file']['error'] ), array( 'status' => 413 ) );
		}
		if ( empty( $files ) && '' === $req->get_body() && isset( $_SERVER['CONTENT_LENGTH'] ) && (int) $_SERVER['CONTENT_LENGTH'] > 0 ) {
			delete_option( $lock );
			return new WP_Error( 'stasimo_upload', self::upload_error( UPLOAD_ERR_INI_SIZE ), array( 'status' => 413 ) );
		}
		if ( ! empty( $files['file']['tmp_name'] ) && is_uploaded_file( $files['file']['tmp_name'] ) ) {
			$tmp = $files['file']['tmp_name'];
		} else {
			$body = $req->get_body();
			if ( '' === $body ) {
				delete_option( $lock );
				return new WP_Error( 'stasimo_empty', __( 'Send the zip as multipart field "file" or as the request body.', 'stasimo-static-site-mount' ), array( 'status' => 400 ) );
			}
			$fs  = Stasimo_Files::fs(); // also loads wp-admin/includes/file.php, which wp_tempnam() lives in.
			$tmp = wp_tempnam( 'stasimo-upload.zip', get_temp_dir() );
			if ( ! $tmp || ! $fs->put_contents( $tmp, $body, FS_CHMOD_FILE ) ) {
				delete_option( $lock );
				return new WP_Error( 'stasimo_tmp', __( 'Could not store the upload.', 'stasimo-static-site-mount' ), array( 'status' => 500 ) );
			}
			$cleanup = true;
		}

		$res = Stasimo_Deployer::deploy_zip( $m, $tmp );
		if ( $cleanup && $tmp ) {
			wp_delete_file( $tmp );
		}
		delete_option( $lock );

		if ( is_wp_error( $res ) ) {
			return new WP_Error( $res->get_error_code(), $res->get_error_message(), array( 'status' => 422 ) );
		}
		$res['url'] = Stasimo_Mounts::url( $m );
		return $res;
	}

	/**
	 * Atomic lock: add_option() fails if the row exists. A lock older than 5 minutes is treated as stale.
	 *
	 * @param string $slug Mount name.
	 * @return string Option name of the lock, or '' if a deploy is running.
	 */
	private static function lock( $slug ) {
		$name = 'stasimo_lock_' . $slug;
		if ( add_option( $name, time(), '', 'no' ) ) {
			return $name;
		}
		if ( time() - (int) get_option( $name ) > 5 * MINUTE_IN_SECONDS ) {
			delete_option( $name );
			return add_option( $name, time(), '', 'no' ) ? $name : '';
		}
		return '';
	}

	/**
	 * Explain a PHP upload error in plain words.
	 *
	 * @param int $code PHP upload error code.
	 * @return string
	 */
	private static function upload_error( $code ) {
		if ( UPLOAD_ERR_INI_SIZE === $code || UPLOAD_ERR_FORM_SIZE === $code ) {
			return sprintf(
				/* translators: 1: upload_max_filesize, 2: post_max_size */
				__( 'The upload is larger than this server allows (upload_max_filesize %1$s, post_max_size %2$s). Raise both in php.ini, or deploy with WP-CLI or by copying files and pressing Re-index.', 'stasimo-static-site-mount' ),
				ini_get( 'upload_max_filesize' ),
				ini_get( 'post_max_size' )
			);
		}
		/* translators: %d PHP upload error code */
		return sprintf( __( 'The upload failed (PHP upload error %d).', 'stasimo-static-site-mount' ), $code );
	}
}
